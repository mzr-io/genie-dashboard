<?php

namespace App\Modules\Ingestion\Application;

use App\Modules\Connector\Contracts\Admission;
use App\Modules\Connector\Contracts\CallOutcome;
use App\Modules\Connector\Contracts\EndpointFetcher;
use App\Modules\Connector\Contracts\EndpointFetchResult;
use App\Modules\Connector\Contracts\EndpointFetchSpec;
use App\Modules\Connector\Contracts\FailureClass;
use App\Modules\Connector\Contracts\GovernorLimits;
use App\Modules\Connector\Contracts\Jitter;
use App\Modules\Connector\Contracts\SourceGovernor;
use App\Modules\Connector\Contracts\SyncRunLog;
use App\Modules\Ingestion\Infrastructure\RetryPolicy;
use App\Modules\Ingestion\Infrastructure\SyncSettings;
use App\Modules\RawStore\Contracts\RawStore;
use App\Platform\Audit\AuditAction;
use App\Platform\Outbox\Outbox;
use App\Support\Observability\MetricEmitter;
use App\Support\Observability\RequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs one dispatch of a sync target (Story 2.14), inside the Workspace the {@see FetchJob} re-entered. It asks Connector for the fetch
 * ({@see EndpointFetcher}: render, guard, transport, pagination and the error ladder), and then commits the outcome under the dispatch fence:
 *
 *  - a changed body (2xx JSON that is new): one transaction moves `applied_seq` to this run's `dispatch_seq` only `WHERE applied_seq < :dispatch_seq`
 *    (zero rows is `superseded` and nothing else changes), then RawStore keeps the exact bytes and an immutable observation, and the target gets
 *    `current_payload_id`, `payload_seq`, `payload_changed_at`, `content_hash` (the lossless-canonical hash), the validators, `last_success_at`,
 *    `last_checked_at` and `consecutive_failures` 0 together with the outbox event `ingestion.payload.changed` (IDs and sequences only);
 *  - not modified (a 304 to the conditional request sent) or unchanged (a 200 whose canonical hash equals `content_hash`, whatever was sent): under the
 *    same fence only `applied_seq`, `last_success_at`, `last_checked_at`, `consecutive_failures` 0 and a refreshed validator move. Nothing is stored,
 *    `current_payload_id`, `payload_seq` and `payload_changed_at` stay, and no event is emitted (Story 2.15);
 *  - a failed run (any ladder code, a non-JSON body, a limit): only `last_checked_at`, `consecutive_failures` and `applied_seq`, under the same
 *    guard. The last good payload stays, the validators stay and nothing is truncated. A 304 nothing conditional was sent for (or with no payload to
 *    keep) is a failed run that also clears `etag`, `last_modified` and `content_hash`, so the next fetch is a full one.
 *
 * A run that is already late or duplicate before it starts (`applied_seq >= dispatch_seq`) sends nothing and is recorded `superseded`. Every
 * attempt is one `sync_runs` row, written through Connector's {@see SyncRunLog}: the sanitised template, the parameter names, the HTTP status,
 * the latency, the bytes, the code and the request ID, never a query string, a value, a header or a secret. Logs and metrics carry the request ID and
 * the Workspace ID, codes and counts, never a body or a value.
 *
 * @phpstan-type TargetRow object{id: string, data_source_id: string, endpoint_id: string, endpoint_revision_id: string, data_source_revision: int|string, applied_seq: int|string, etag: string|null, last_modified: string|null, current_payload_id: string|null, group_primary_target_id: string|null}
 * @phpstan-type Side array{target: TargetRow, targetId: string, dataSourceId: string, runId: string, startedAt: CarbonImmutable, result: EndpointFetchResult|null, sent: string|null, admission: Admission}
 */
final class FetchSyncTarget
{
    public function __construct(
        private readonly EndpointFetcher $fetcher,
        private readonly RawStore $raw,
        private readonly SyncRunLog $runs,
        private readonly Outbox $outbox,
        private readonly RequestContext $context,
        private readonly MetricEmitter $metrics,
        private readonly SourceGovernor $governor,
        private readonly Jitter $jitter,
        private readonly SyncSettings $settings,
        private readonly EvaluateSourceHealth $health,
    ) {}

    public const CONFIG_ERROR = 'config-error';

    public function run(string $workspaceId, string $syncGroupId, int $dispatchSeq, int $attempt = 1): void
    {
        /** @var list<TargetRow> $targets */
        $targets = DB::select(
            'select id, data_source_id, endpoint_id, endpoint_revision_id, data_source_revision, applied_seq, etag, last_modified, current_payload_id, group_primary_target_id from sync_targets where workspace_id = ? and sync_group_id = ? and retired_at is null order by id',
            [$workspaceId, strtolower($syncGroupId)],
        );

        $group = $this->groupOf($targets);

        if ($group !== null) {
            $this->fetchGroup($workspaceId, strtolower($syncGroupId), $group[0], $group[1], $dispatchSeq, $attempt);

            return;
        }

        foreach ($targets as $target) {
            $this->fetchTarget($workspaceId, strtolower($syncGroupId), $target, $dispatchSeq, $attempt);
        }
    }

    /**
     * The primary and its linked comparison, when the group has both live (Story 2.20); null for a group of one target.
     *
     * @param  list<TargetRow>  $targets
     * @return array{0: TargetRow, 1: TargetRow}|null
     */
    private function groupOf(array $targets): ?array
    {
        $byId = [];

        foreach ($targets as $target) {
            $byId[strtolower((string) $target->id)] = $target;
        }

        foreach ($targets as $target) {
            $primary = $target->group_primary_target_id === null ? null : ($byId[strtolower((string) $target->group_primary_target_id)] ?? null);

            if ($primary !== null && $primary->group_primary_target_id === null) {
                return [$primary, $target];
            }
        }

        return null;
    }

    /**
     * A job that failed outside the run's own handling (it threw, or its worker was killed): under the same fence it counts one failure and
     * records a `failed` run with `job-failed` and the request ID, so the failure is visible. It never touches the payload. A target that is
     * not visible, or whose fence a later dispatch has passed, is left alone.
     */
    public function jobFailed(string $workspaceId, string $syncGroupId, int $dispatchSeq, int $attempt): void
    {
        $startedAt = CarbonImmutable::now()->utc();

        foreach (DB::select('select id, data_source_id from sync_targets where workspace_id = ? and sync_group_id = ? and retired_at is null order by id', [$workspaceId, strtolower($syncGroupId)]) as $target) {
            $targetId = strtolower((string) $target->id);

            if ($this->commitFailure($workspaceId, $targetId, $dispatchSeq) === 'superseded') {
                continue;
            }

            $this->record(
                $workspaceId, (string) Str::uuid7(), strtolower((string) $target->data_source_id), $targetId, $dispatchSeq, '', [], 'failed',
                null, null, null, 'job-failed', $startedAt, null, $attempt,
            );

            $this->health->evaluate($workspaceId, strtolower((string) $target->data_source_id));
        }
    }

    /** @param  object{id: string, data_source_id: string, endpoint_id: string, endpoint_revision_id: string, data_source_revision: int|string, applied_seq: int|string, etag: string|null, last_modified: string|null, current_payload_id: string|null}  $target */
    private function fetchTarget(string $workspaceId, string $syncGroupId, object $target, int $dispatchSeq, int $attempt): void
    {
        $startedAt = CarbonImmutable::now()->utc();
        $runId = (string) Str::uuid7();
        $targetId = strtolower((string) $target->id);
        $dataSourceId = strtolower((string) $target->data_source_id);

        if ((int) $target->applied_seq >= $dispatchSeq) {
            // A late or duplicate run: a later one has already been applied, so there is nothing to call.
            $this->record($workspaceId, $runId, $dataSourceId, $targetId, $dispatchSeq, '', [], 'superseded', null, null, null, 'superseded', $startedAt, null, $attempt);

            return;
        }

        // Story 2.17: may a call go out now? (the breaker, the penalty, the token bucket, the concurrency cap). A denied call is not an attempt.
        $limits = $this->settings->governorLimits();
        $retry = $this->settings->retry();
        $admission = $this->governor->admit($workspaceId, $dataSourceId, $limits);

        if (! $admission->admitted) {
            $this->denied($workspaceId, $syncGroupId, $runId, $dataSourceId, $targetId, $dispatchSeq, $attempt, $admission, $retry, $startedAt);

            return;
        }

        // The conditional request: the stored ETag, else the stored Last-Modified, only when there is a payload to keep. The Data Source may still
        // refuse to send it (a paged call hashes only).
        $hasPayload = $target->current_payload_id !== null;
        $etag = $hasPayload && is_string($target->etag) && $target->etag !== '' ? $target->etag : null;
        $modified = $hasPayload && $etag === null && is_string($target->last_modified) && $target->last_modified !== '' ? $target->last_modified : null;

        try {
            $result = $this->fetcher->fetch(new EndpointFetchSpec(
                $workspaceId, $dataSourceId, strtolower((string) $target->endpoint_id), strtolower((string) $target->endpoint_revision_id),
                (int) $target->data_source_revision, $this->values($workspaceId, $targetId), $runId, $etag, $modified,
            ));
        } catch (Throwable $e) {
            // The call never told the breaker anything: a half-open probe gives its lease back (no outcome counted) so the next job can probe.
            if ($admission->probe) {
                $this->governor->record($workspaceId, $dataSourceId, CallOutcome::Throttled, $limits, true);
            }

            throw $e;
        } finally {
            if ($admission->slot) {
                $this->governor->release($workspaceId, $dataSourceId);
            }
        }

        $this->learn($workspaceId, $dataSourceId, $result, $limits, $admission->probe);

        if ($result->moved) {
            // The Endpoint or Data Source has a newer revision: this target is about to be retired, and no call was made for it.
            $this->record($workspaceId, $runId, $dataSourceId, $targetId, $dispatchSeq, $result->urlTemplate, $result->parameterNames, 'superseded', null, null, null, 'revision_moved', $startedAt, null, $attempt);

            return;
        }

        if (! $result->ok && $this->retried($workspaceId, $syncGroupId, $runId, $dataSourceId, $targetId, $dispatchSeq, $attempt, $result, $retry, $admission->probe, $startedAt)) {
            return;
        }

        $outcome = $result->ok
            ? $this->commitSuccess($workspaceId, $target, $targetId, $dispatchSeq, $result, $etag ?? $modified)
            : $this->commitFailure($workspaceId, $targetId, $dispatchSeq, $result->reason === EndpointFetchResult::NOT_MODIFIED_WITHOUT_PAYLOAD);

        if ($outcome === 'store_failed') {
            // The good body could not be kept: it is a failed run, and the last good payload still stands.
            $this->commitFailure($workspaceId, $targetId, $dispatchSeq);
        }

        $status = match ($outcome) {
            'superseded' => 'superseded',
            'changed', 'not_modified', 'unchanged' => 'succeeded',
            default => 'failed',
        };
        $code = match (true) {
            $status === 'superseded' => 'superseded',
            $outcome === 'store_failed' => 'store-failed',
            default => $this->errorCode($result),
        };

        $this->record(
            $workspaceId, $runId, $dataSourceId, $targetId, $dispatchSeq, $result->urlTemplate, $result->parameterNames, $status,
            $result->status, $result->latencyMs, $result->bytes, $status === 'succeeded' ? null : $code, $startedAt,
            $status === 'succeeded' ? $outcome : null, $attempt,
        );

        // Story 2.18: a final run (succeeded or failed) is evidence for the Data Source's health. A superseded run says nothing.
        if ($status !== 'superseded') {
            $this->health->evaluate($workspaceId, $dataSourceId);
        }
    }

    /**
     * A sync group (Story 2.20): the primary and its comparison fetched in one run under one fence, the primary's `dispatch_seq`/`applied_seq`.
     * Each side is admitted and fetched on its own (the per-Data-Source governor of 2.17 is unchanged). A denied call that waits, a revision that
     * moved or a retryable failure re-runs the whole group (nothing is committed for this attempt). When both calls have a final answer,
     * {@see commitGroup()} writes both payloads and one generation in one transaction.
     *
     * @param  TargetRow  $primary
     * @param  TargetRow  $comparison
     */
    private function fetchGroup(string $workspaceId, string $syncGroupId, object $primary, object $comparison, int $dispatchSeq, int $attempt): void
    {
        $startedAt = CarbonImmutable::now()->utc();
        $primaryId = strtolower((string) $primary->id);

        if ((int) $primary->applied_seq >= $dispatchSeq) {
            // A late or duplicate run of the group: a later one has already been applied, so there is nothing to call.
            foreach ([$primary, $comparison] as $target) {
                $this->record($workspaceId, (string) Str::uuid7(), strtolower((string) $target->data_source_id), strtolower((string) $target->id), $dispatchSeq, '', [], 'superseded', null, null, null, 'superseded', $startedAt, null, $attempt);
            }

            return;
        }

        $limits = $this->settings->governorLimits();
        $retry = $this->settings->retry();
        /** @var array<int, Side> $sides */
        $sides = [];

        foreach ([$primary, $comparison] as $index => $target) {
            $targetId = strtolower((string) $target->id);
            $dataSourceId = strtolower((string) $target->data_source_id);
            $runId = (string) Str::uuid7();
            $sideStarted = CarbonImmutable::now()->utc();
            $admission = $this->governor->admit($workspaceId, $dataSourceId, $limits);

            if (! $admission->admitted) {
                if ($this->waited($workspaceId, $syncGroupId, $primaryId, $dispatchSeq, $attempt, $admission, $retry)) {
                    return;
                }

                if ($index === 0) {
                    // The primary has no answer to commit: the skip is final for the group, under the fence.
                    $this->skipped($workspaceId, $runId, $dataSourceId, $targetId, $dispatchSeq, $attempt, $admission, $sideStarted, true);

                    return;
                }

                // The comparison could not be called: the primary's answer is committed with the comparison side failed.
                $sides[$index] = ['target' => $target, 'targetId' => $targetId, 'dataSourceId' => $dataSourceId, 'runId' => $runId, 'startedAt' => $sideStarted, 'result' => null, 'sent' => null, 'admission' => $admission];

                continue;
            }

            $hasPayload = $target->current_payload_id !== null;
            $etag = $hasPayload && is_string($target->etag) && $target->etag !== '' ? $target->etag : null;
            $modified = $hasPayload && $etag === null && is_string($target->last_modified) && $target->last_modified !== '' ? $target->last_modified : null;

            try {
                $result = $this->fetcher->fetch(new EndpointFetchSpec(
                    $workspaceId, $dataSourceId, strtolower((string) $target->endpoint_id), strtolower((string) $target->endpoint_revision_id),
                    (int) $target->data_source_revision, $this->values($workspaceId, $targetId), $runId, $etag, $modified,
                ));
            } catch (Throwable $e) {
                if ($admission->probe) {
                    $this->governor->record($workspaceId, $dataSourceId, CallOutcome::Throttled, $limits, true);
                }

                throw $e;
            } finally {
                if ($admission->slot) {
                    $this->governor->release($workspaceId, $dataSourceId);
                }
            }

            $this->learn($workspaceId, $dataSourceId, $result, $limits, $admission->probe);

            if ($result->moved) {
                // A newer revision: the target is about to be retired. Nothing is committed for the group.
                $this->record($workspaceId, $runId, $dataSourceId, $targetId, $dispatchSeq, $result->urlTemplate, $result->parameterNames, 'superseded', null, null, null, 'revision_moved', $sideStarted, null, $attempt);

                return;
            }

            if (! $result->ok && $this->retried($workspaceId, $syncGroupId, $runId, $dataSourceId, $targetId, $dispatchSeq, $attempt, $result, $retry, $admission->probe, $sideStarted, $primaryId)) {
                return;
            }

            $sides[$index] = ['target' => $target, 'targetId' => $targetId, 'dataSourceId' => $dataSourceId, 'runId' => $runId, 'startedAt' => $sideStarted, 'result' => $result, 'sent' => $etag ?? $modified, 'admission' => $admission];
        }

        $outcomes = $this->commitGroup($workspaceId, $syncGroupId, $dispatchSeq, $sides);

        foreach ($sides as $index => $side) {
            $result = $side['result'];
            $outcome = $outcomes[$index];
            $status = match ($outcome) {
                'superseded' => 'superseded',
                'skipped' => 'skipped',
                'changed', 'not_modified', 'unchanged' => 'succeeded',
                default => 'failed',
            };
            $denied = $result === null;
            $code = match (true) {
                $status === 'superseded' => 'superseded',
                $denied => $side['admission']->reason === Admission::CIRCUIT_OPEN ? 'circuit-open' : 'rate-limited',
                $outcome === 'store_failed' => 'store-failed',
                default => $this->errorCode($result),
            };

            $this->record(
                $workspaceId, $side['runId'], $side['dataSourceId'], $side['targetId'], $dispatchSeq, $result === null ? '' : $result->urlTemplate, $result === null ? [] : $result->parameterNames, $status,
                $result?->status, $result?->latencyMs, $result?->bytes, $status === 'succeeded' ? null : $code, $side['startedAt'],
                $status === 'succeeded' ? $outcome : null, $attempt, $denied ? ($side['admission']->reason === Admission::CIRCUIT_OPEN ? 'circuit_open' : 'rate_limited') : null,
            );
        }

        if ($outcomes[0] !== 'superseded') {
            // Story 2.18: a final run is evidence for each Data Source's health (once per source).
            foreach (array_unique(array_column($sides, 'dataSourceId')) as $dataSourceId) {
                $this->health->evaluate($workspaceId, $dataSourceId);
            }
        }
    }

    /**
     * One transaction for the group: the primary's side first under the fence (a lost fence changes nothing at all), then the comparison's, then
     * the `sync_generations` row and the events. A side that failed leaves its last good payload; the generation says which side failed and is then
     * not complete, so compute never reads it. A generation is written when a payload changed, or when the pair of outcomes differs from the
     * latest generation's (so a recovered comparison completes the group without a change); never when both sides failed.
     *
     * @param  array<int, Side>  $sides
     * @return array{0: string, 1: string} the outcome of each side: 'changed', 'not_modified', 'unchanged', 'failed', 'skipped', 'superseded' or 'store_failed'
     */
    private function commitGroup(string $workspaceId, string $syncGroupId, int $dispatchSeq, array $sides): array
    {
        try {
            return DB::transaction(function () use ($workspaceId, $syncGroupId, $dispatchSeq, $sides): array {
                $outcomes = [];
                $changes = [];

                foreach ([0, 1] as $index) {
                    $side = $sides[$index];
                    $result = $side['result'];
                    $change = null;

                    if ($result === null) {
                        // Not called: only the fence number moves, as for a skipped single target.
                        DB::update('update sync_targets set applied_seq = ?, updated_at = clock_timestamp() where workspace_id = ? and id = ? and applied_seq < ?', [$dispatchSeq, $workspaceId, $side['targetId'], $dispatchSeq]);
                        $outcome = 'skipped';
                    } elseif ($result->ok) {
                        $outcome = $this->successBody($workspaceId, $side['targetId'], $dispatchSeq, $result, $side['sent'], $change);
                    } else {
                        $outcome = $this->failureUpdate($workspaceId, $side['targetId'], $dispatchSeq, $result->reason === EndpointFetchResult::NOT_MODIFIED_WITHOUT_PAYLOAD) === null ? 'superseded' : 'failed';
                    }

                    if ($outcome === 'superseded' && $index === 0) {
                        // The primary's fence is the group's: a late group run writes nothing at all.
                        return ['superseded', 'superseded'];
                    }

                    // A comparison whose own number is not behind this dispatch cannot be applied: it counts as a failed side, untouched.
                    $outcomes[$index] = $outcome === 'superseded' ? 'failed' : $outcome;
                    $changes[$index] = $change;
                }

                $ok = fn (string $outcome): bool => in_array($outcome, ['changed', 'not_modified', 'unchanged'], true);
                $primaryOk = $ok($outcomes[0]);
                $comparisonOk = $ok($outcomes[1]);
                $changed = $changes[0] !== null || $changes[1] !== null;
                $generationId = null;

                if (($primaryOk || $comparisonOk) && ($changed || $this->generationDiffers($workspaceId, $syncGroupId, $primaryOk, $comparisonOk))) {
                    $generationId = $this->writeGeneration($workspaceId, $syncGroupId, $dispatchSeq, $sides, $primaryOk, $comparisonOk);
                }

                if ($generationId !== null) {
                    $extra = [
                        'sync_group_id' => $syncGroupId,
                        'generation_id' => $generationId,
                        'generation_complete' => $primaryOk && $comparisonOk,
                        'failed_side' => match (true) {
                            ! $primaryOk => 'primary',
                            ! $comparisonOk => 'comparison',
                            default => null,
                        },
                    ];

                    foreach ([0, 1] as $index) {
                        if ($changes[$index] !== null) {
                            $this->emitChanged($sides[$index]['target'], $sides[$index]['targetId'], $changes[$index], $dispatchSeq, $extra);
                        }
                    }

                    if (! $changed) {
                        // The generation is news even though no payload moved (the comparison failed, or it recovered): the primary's subject carries it.
                        $row = DB::selectOne('select current_payload_id, payload_seq from sync_targets where workspace_id = ? and id = ?', [$workspaceId, $sides[0]['targetId']]);

                        if ($row !== null && $row->current_payload_id !== null) {
                            $this->emitChanged($sides[0]['target'], $sides[0]['targetId'], ['payload_id' => strtolower((string) $row->current_payload_id), 'seq' => (int) $row->payload_seq], $dispatchSeq, $extra);
                        }
                    }

                    $this->metrics->increment('dashflow.ingestion.generation_'.($primaryOk && $comparisonOk ? 'complete' : 'incomplete'), ['workspace_id' => $workspaceId]);
                }

                return [$outcomes[0], $outcomes[1]];
            });
        } catch (Throwable $e) {
            // Only the class. The whole group rolled back: both sides are a failed run, and the last good payloads stand.
            Log::error('ingestion.fetch.store_failed', ['workspace_id' => $workspaceId, 'exception' => $e::class]);

            $primary = $this->commitFailure($workspaceId, $sides[0]['targetId'], $dispatchSeq);

            if ($primary === 'superseded') {
                return ['superseded', 'superseded'];
            }

            $this->commitFailure($workspaceId, $sides[1]['targetId'], $dispatchSeq);

            return ['store_failed', 'store_failed'];
        }
    }

    /** Whether the pair of outcomes differs from the group's latest generation (or there is none yet). */
    private function generationDiffers(string $workspaceId, string $syncGroupId, bool $primaryOk, bool $comparisonOk): bool
    {
        $latest = DB::selectOne(
            'select primary_ok, comparison_ok from sync_generations where workspace_id = ? and sync_group_id = ? order by dispatch_seq desc limit 1',
            [$workspaceId, $syncGroupId],
        );

        return $latest === null || (bool) $latest->primary_ok !== $primaryOk || (bool) $latest->comparison_ok !== $comparisonOk;
    }

    /**
     * The generation of this run: IDs, sequence numbers and the outcome of each side. A side that failed keeps no payload (its older payload is
     * not part of this generation); a side that succeeded names its current payload, which is the one this run just kept or confirmed.
     *
     * @param  array<int, array{targetId: string}>  $sides
     */
    private function writeGeneration(string $workspaceId, string $syncGroupId, int $dispatchSeq, array $sides, bool $primaryOk, bool $comparisonOk): string
    {
        $id = (string) Str::uuid7();
        $payloads = [];

        foreach ([0 => $primaryOk, 1 => $comparisonOk] as $index => $ok) {
            $row = $ok ? DB::selectOne('select current_payload_id, payload_seq from sync_targets where workspace_id = ? and id = ?', [$workspaceId, $sides[$index]['targetId']]) : null;
            $payloads[$index] = $row === null ? [null, null] : [strtolower((string) $row->current_payload_id), (int) $row->payload_seq];
        }

        DB::insert(
            'insert into sync_generations (id, workspace_id, sync_group_id, primary_target_id, comparison_target_id, dispatch_seq, primary_ok, comparison_ok, complete, '
            .'primary_payload_id, comparison_payload_id, primary_payload_seq, comparison_payload_seq, created_at) values (?, ?, ?, ?, ?, ?, ?::boolean, ?::boolean, ?::boolean, ?, ?, ?, ?, now())',
            [
                $id, $workspaceId, $syncGroupId, $sides[0]['targetId'], $sides[1]['targetId'], $dispatchSeq, $primaryOk ? 'true' : 'false', $comparisonOk ? 'true' : 'false',
                $primaryOk && $comparisonOk ? 'true' : 'false', $payloads[0][0], $payloads[1][0], $payloads[0][1], $payloads[1][1],
            ],
        );

        return $id;
    }

    /** The code of a failed run: `config-error` for anything the Admin has to fix (Story 2.17), else the ladder's own code. */
    private function errorCode(EndpointFetchResult $result): string
    {
        return $result->failureClass === FailureClass::Configuration ? self::CONFIG_ERROR : ($result->code->value ?? 'fetch-failed');
    }

    /**
     * Tells the governor what the call showed (Story 2.17): the source responded (a 2xx or 304, or an answer that is the Admin's or the
     * data's to fix) resets the breaker; a transient or ambiguous failure counts; a throttled answer sets the penalty and empties the bucket
     * and neither counts nor resets. A call that never reached the source (a blocked address, a missing secret, a revision that moved) teaches
     * nothing, and a probe that learned nothing gives its lease back.
     */
    private function learn(string $workspaceId, string $dataSourceId, EndpointFetchResult $result, GovernorLimits $limits, bool $probe): void
    {
        $class = $result->failureClass;
        $outcome = match (true) {
            $result->moved => null,
            $result->ok => CallOutcome::Responded,
            $class === FailureClass::Transient, $class === FailureClass::Ambiguous => CallOutcome::Failed,
            $class === FailureClass::Throttled => CallOutcome::Throttled,
            $class === FailureClass::Data, $class === FailureClass::Configuration && $result->status !== null => CallOutcome::Responded,
            default => null,
        };

        if ($class === FailureClass::Throttled) {
            $labels = ['workspace_id' => $workspaceId];
            $this->metrics->increment('dashflow.connector.throttled', $labels);

            if ($limits->penaltyCapSeconds !== null) {
                $this->governor->penalize($workspaceId, $dataSourceId, $result->retryAfterSeconds === null ? 0 : max(1, min($limits->penaltyCapSeconds, $result->retryAfterSeconds)), $limits);
            }
        }

        if ($outcome === null && ! $probe) {
            return;
        }

        $transition = $this->governor->record($workspaceId, $dataSourceId, $outcome ?? CallOutcome::Throttled, $limits, $probe);

        if ($transition === SourceGovernor::OPENED) {
            $this->metrics->increment('dashflow.connector.circuit_opened', ['workspace_id' => $workspaceId]);
        } elseif ($transition === SourceGovernor::CLOSED) {
            $this->metrics->increment('dashflow.connector.circuit_closed', ['workspace_id' => $workspaceId]);
        }

        if ($transition !== null) {
            // Story 2.18: the breaker opening or closing re-evaluates the Data Source's health.
            $this->health->evaluate($workspaceId, $dataSourceId);
        }
    }

    /**
     * A retryable failure that has attempts left and a delay that fits before the target is due again becomes a `retrying` run and the same
     * fenced job, queued again with that delay: nothing on the target changes. Never for a probe, an Endpoint test or a POST that is not
     * `transient` or `throttled`.
     */
    private function retried(
        string $workspaceId, string $syncGroupId, string $runId, string $dataSourceId, string $targetId, int $dispatchSeq, int $attempt,
        EndpointFetchResult $result, ?RetryPolicy $retry, bool $probe, CarbonImmutable $startedAt, ?string $dueTargetId = null,
    ): bool {
        if ($retry === null || $probe || $attempt >= $retry->maxAttempts || $result->failureClass === null || ! $result->failureClass->retryable()) {
            return false;
        }

        $delay = $result->failureClass === FailureClass::Throttled && $result->retryAfterSeconds !== null
            ? max(1, min($retry->cap, $result->retryAfterSeconds))
            : $this->jitter->upTo($retry->ceiling($attempt));

        if (! $this->fits($workspaceId, $dueTargetId ?? $targetId, $delay)) {
            return false;
        }

        // The run is written first: with a synchronous queue the next attempt runs inside the dispatch call. It has its own id, so a final
        // failure recorded under `$runId` after a requeue that failed cannot collide with it.
        $this->record(
            $workspaceId, (string) Str::uuid7(), $dataSourceId, $targetId, $dispatchSeq, $result->urlTemplate, $result->parameterNames, 'retrying',
            $result->status, $result->latencyMs, $result->bytes, $this->errorCode($result), $startedAt, null, $attempt,
        );

        if ($this->requeue($workspaceId, $syncGroupId, $dispatchSeq, $attempt + 1, $delay)) {
            $this->metrics->increment('dashflow.connector.fetch_retried', ['workspace_id' => $workspaceId]);

            return true;
        }

        // The retry could not be queued: the failure is final after all. The `retrying` row stays as the history of this call.
        return false;
    }

    /** A call that was denied (Story 2.17): the breaker's skip is final; a penalty, an empty bucket or a full concurrency cap waits and tries again when that fits. */
    private function denied(
        string $workspaceId, string $syncGroupId, string $runId, string $dataSourceId, string $targetId, int $dispatchSeq, int $attempt,
        Admission $admission, ?RetryPolicy $retry, CarbonImmutable $startedAt,
    ): void {
        if ($this->waited($workspaceId, $syncGroupId, $targetId, $dispatchSeq, $attempt, $admission, $retry)) {
            return;
        }

        $this->skipped($workspaceId, $runId, $dataSourceId, $targetId, $dispatchSeq, $attempt, $admission, $startedAt, true);
    }

    /** Whether a denied call waits for the governor: the same fenced job is queued again when the wait ends before the target is due again. */
    private function waited(string $workspaceId, string $syncGroupId, string $dueTargetId, int $dispatchSeq, int $attempt, Admission $admission, ?RetryPolicy $retry): bool
    {
        if ($admission->reason === Admission::CIRCUIT_OPEN || $retry === null) {
            return false;
        }

        $wait = $admission->reason === Admission::CONCURRENCY ? $retry->base : $admission->waitSeconds;

        if ($this->fits($workspaceId, $dueTargetId, $wait) && $this->requeue($workspaceId, $syncGroupId, $dispatchSeq, $attempt, $wait)) {
            // A wait for the governor is not a retry: its own counter.
            $this->metrics->increment('dashflow.connector.fetch_requeued', ['workspace_id' => $workspaceId]);

            return true;
        }

        return false;
    }

    /**
     * The final record of a denied call. Under the fence, only `applied_seq` moves: a skip is neither a failure nor a check of the source. A
     * comparison side of a group does not move the fence (`$fence` false): the primary's commit carries it.
     */
    private function skipped(
        string $workspaceId, string $runId, string $dataSourceId, string $targetId, int $dispatchSeq, int $attempt, Admission $admission,
        CarbonImmutable $startedAt, bool $fence,
    ): void {
        $code = $admission->reason === Admission::CIRCUIT_OPEN ? 'circuit-open' : 'rate-limited';
        $moved = true;

        if ($fence) {
            try {
                $moved = DB::transaction(fn () => DB::selectOne(
                    'update sync_targets set applied_seq = ?, updated_at = clock_timestamp() where workspace_id = ? and id = ? and applied_seq < ? returning id',
                    [$dispatchSeq, $workspaceId, $targetId, $dispatchSeq],
                )) !== null;
            } catch (Throwable $e) {
                Log::error('ingestion.fetch.skip_not_recorded', ['workspace_id' => $workspaceId, 'exception' => $e::class]);
                $moved = false;
            }
        }

        $this->record(
            $workspaceId, $runId, $dataSourceId, $targetId, $dispatchSeq, '', [], $moved ? 'skipped' : 'superseded',
            null, null, null, $moved ? $code : 'superseded', $startedAt, null, $attempt, $admission->reason === Admission::CIRCUIT_OPEN ? 'circuit_open' : 'rate_limited',
        );
    }

    /** Whether a delay ends before the target is due again (the dispatcher already set `next_due_at` to the dispatch time plus the interval). */
    private function fits(string $workspaceId, string $targetId, int $delaySeconds): bool
    {
        $row = DB::selectOne(
            'select extract(epoch from next_due_at - clock_timestamp()) as due_in from sync_targets where workspace_id = ? and id = ? and next_due_at is not null',
            [$workspaceId, $targetId],
        );

        return $row !== null && $row->due_in !== null && $delaySeconds < (float) $row->due_in;
    }

    private function requeue(string $workspaceId, string $syncGroupId, int $dispatchSeq, int $attempt, int $delaySeconds): bool
    {
        try {
            FetchJob::dispatch($workspaceId, $syncGroupId, $dispatchSeq, $attempt)->delay($delaySeconds);

            return true;
        } catch (Throwable $e) {
            Log::error('ingestion.fetch.requeue_failed', ['workspace_id' => $workspaceId, 'exception' => $e::class]);

            return false;
        }
    }

    /**
     * @param  object{endpoint_id: string}  $target
     * @param  string|null  $sent  the validator the request carried (the ETag, else the Last-Modified), null when it was not conditional
     * @return 'changed'|'not_modified'|'unchanged'|'failed'|'superseded'|'store_failed'
     */
    private function commitSuccess(string $workspaceId, object $target, string $targetId, int $dispatchSeq, EndpointFetchResult $result, ?string $sent): string
    {
        try {
            return DB::transaction(function () use ($workspaceId, $target, $targetId, $dispatchSeq, $result, $sent): string {
                $change = null;
                $outcome = $this->successBody($workspaceId, $targetId, $dispatchSeq, $result, $sent, $change);

                if ($change !== null) {
                    $this->emitChanged($target, $targetId, $change, $dispatchSeq);
                }

                return $outcome;
            });
        } catch (Throwable $e) {
            // Only the class: a storage error message could carry what was being stored.
            Log::error('ingestion.fetch.store_failed', ['workspace_id' => $workspaceId, 'exception' => $e::class]);

            return 'store_failed';
        }
    }

    /**
     * The good-response commit of one target, inside the caller's transaction (it throws when the body cannot be kept, and the caller rolls back).
     * The outbox event is the caller's: a stored change is reported in `$change`.
     *
     * @param  array{payload_id: string, seq: int}|null  $change
     * @return 'changed'|'not_modified'|'unchanged'|'failed'|'superseded'
     */
    private function successBody(string $workspaceId, string $targetId, int $dispatchSeq, EndpointFetchResult $result, ?string $sent, ?array &$change): string
    {
        $now = CarbonImmutable::now()->utc();

        // The row is locked first, so what the comparisons read is what the fence update then moves.
        $row = DB::selectOne(
            'select applied_seq, content_hash, current_payload_id, etag, last_modified from sync_targets where workspace_id = ? and id = ? for update',
            [$workspaceId, $targetId],
        );

        // The fence: a run that is not newer than the last applied one changes nothing at all.
        if ($row === null || (int) $row->applied_seq >= $dispatchSeq) {
            return 'superseded';
        }

        if ($result->notModified) {
            if ($row->current_payload_id === null || $sent === null) {
                // A 304 with no payload to keep: it is a failed run, and the next fetch is a full one.
                $this->failureUpdate($workspaceId, $targetId, $dispatchSeq, true);

                return 'failed';
            }

            if ($sent !== ($row->etag ?? $row->last_modified)) {
                // The conditional state moved while this run was out: its 304 speaks for an older payload.
                return 'superseded';
            }

            $this->unchangedUpdate($workspaceId, $targetId, $dispatchSeq, $result, true);

            return 'not_modified';
        }

        $hash = CanonicalBodyHash::of((string) $result->body);

        if ($hash !== null && $row->current_payload_id !== null && $row->content_hash === $hash) {
            $this->unchangedUpdate($workspaceId, $targetId, $dispatchSeq, $result, false);

            return 'unchanged';
        }

        $moved = DB::selectOne(
            // One instant for "Last success", "Checked" and "Data as of": this run's response is the payload.
            'with t as (select clock_timestamp() as ts) update sync_targets set applied_seq = ?, payload_seq = payload_seq + 1, last_success_at = t.ts, last_checked_at = t.ts, payload_changed_at = t.ts, consecutive_failures = 0, updated_at = t.ts '
            .'from t where workspace_id = ? and id = ? and applied_seq < ? returning payload_seq',
            [$dispatchSeq, $workspaceId, $targetId, $dispatchSeq],
        );

        if ($moved === null) {
            return 'superseded';
        }

        $seq = (int) $moved->payload_seq;
        $payload = $this->raw->put($workspaceId, $targetId, $seq, $dispatchSeq, (string) $result->body, $this->context->requestId(), $now);

        DB::update(
            'update sync_targets set current_payload_id = ?, content_hash = ?, etag = ?, last_modified = ? where workspace_id = ? and id = ?',
            [$payload->id, $hash, $result->etag, $result->lastModified, $workspaceId, $targetId],
        );

        $change = ['payload_id' => $payload->id, 'seq' => $seq];

        return 'changed';
    }

    /**
     * `ingestion.payload.changed` for a stored change: IDs and sequences only. A group run adds the group and its generation (Story 2.20).
     *
     * @param  object{endpoint_id: string}  $target
     * @param  array{payload_id: string, seq: int}  $change
     * @param  array<string, int|bool|string|null>  $extra
     */
    private function emitChanged(object $target, string $targetId, array $change, int $dispatchSeq, array $extra = []): void
    {
        $this->outbox->emit(AuditAction::IngestionPayloadChanged, 'sync_target:'.$targetId, [
            'sync_target_id' => $targetId,
            'endpoint_id' => strtolower((string) $target->endpoint_id),
            'payload_id' => $change['payload_id'],
            'payload_seq' => $change['seq'],
            'dispatch_seq' => $dispatchSeq,
        ] + $extra);
    }

    /**
     * An unchanged success (a 304, or a body with the same canonical hash): the run counts, nothing is stored. The validators the answer
     * carried replace the stored ones (a 304 that carries none keeps them); the payload, its sequence, its time and its hash stay.
     */
    private function unchangedUpdate(string $workspaceId, string $targetId, int $dispatchSeq, EndpointFetchResult $result, bool $keepMissingValidators): void
    {
        $etag = $keepMissingValidators ? 'coalesce(?, etag)' : '?';
        $modified = $keepMissingValidators ? 'coalesce(?, last_modified)' : '?';

        DB::update(
            'update sync_targets set applied_seq = ?, last_success_at = clock_timestamp(), last_checked_at = clock_timestamp(), consecutive_failures = 0, '
            ."etag = {$etag}, last_modified = {$modified}, updated_at = clock_timestamp() where workspace_id = ? and id = ? and applied_seq < ?",
            [$dispatchSeq, $result->etag, $result->lastModified, $workspaceId, $targetId, $dispatchSeq],
        );
    }

    /**
     * @param  bool  $clearConditional  a 304 that nothing conditional explains: `etag`, `last_modified` and `content_hash` go too
     * @return 'failed'|'superseded'
     */
    private function commitFailure(string $workspaceId, string $targetId, int $dispatchSeq, bool $clearConditional = false): string
    {
        try {
            $row = DB::transaction(fn () => $this->failureUpdate($workspaceId, $targetId, $dispatchSeq, $clearConditional));
        } catch (Throwable $e) {
            Log::error('ingestion.fetch.failure_not_recorded', ['workspace_id' => $workspaceId, 'exception' => $e::class]);

            return 'failed';
        }

        return $row === null ? 'superseded' : 'failed';
    }

    private function failureUpdate(string $workspaceId, string $targetId, int $dispatchSeq, bool $clearConditional): ?object
    {
        $clear = $clearConditional ? ', etag = null, last_modified = null, content_hash = null' : '';

        return DB::selectOne(
            'update sync_targets set applied_seq = ?, last_checked_at = clock_timestamp(), consecutive_failures = consecutive_failures + 1'.$clear.', updated_at = clock_timestamp() '
            .'where workspace_id = ? and id = ? and applied_seq < ? returning consecutive_failures',
            [$dispatchSeq, $workspaceId, $targetId, $dispatchSeq],
        );
    }

    /**
     * The target's typed parameters as values by name (`header:{name}` for a header), decoded by the database, not in PHP.
     *
     * @return array<string, string>
     */
    private function values(string $workspaceId, string $targetId): array
    {
        $values = [];

        foreach (DB::select("select p.key as name, p.value->>'v' as v from sync_targets t, jsonb_each(t.params) p where t.workspace_id = ? and t.id = ?", [$workspaceId, $targetId]) as $row) {
            if (is_string($row->v)) {
                $values[(string) $row->name] = $row->v;
            }
        }

        return $values;
    }

    /**
     * @param  list<string>  $parameterNames
     */
    private function record(
        string $workspaceId, string $runId, string $dataSourceId, string $targetId, int $dispatchSeq, string $urlTemplate, array $parameterNames,
        string $status, ?int $httpStatus, ?int $latencyMs, ?int $bytes, ?string $errorCode, CarbonImmutable $startedAt, ?string $outcome = null,
        ?int $attempt = null, ?string $skipReason = null,
    ): void {
        $requestId = $this->context->requestId();

        try {
            // Its own savepoint: a failing history write must not undo what the run committed.
            DB::transaction(fn () => $this->runs->recordScheduledFetch(
                $workspaceId, $runId, $dataSourceId, $targetId, $dispatchSeq, $urlTemplate, $parameterNames, $status,
                $httpStatus, $latencyMs, $bytes, $errorCode, $requestId, $startedAt, $outcome, $attempt,
            ));
        } catch (Throwable $e) {
            Log::error('ingestion.fetch.run_not_recorded', ['workspace_id' => $workspaceId, 'exception' => $e::class]);
        }

        // Counters carry the Workspace only: a request ID is unbounded and belongs in logs.
        $labels = ['workspace_id' => $workspaceId];
        $this->metrics->increment('dashflow.ingestion.fetch_'.$status, $labels);

        if ($status === 'skipped') {
            $this->metrics->increment('dashflow.connector.fetch_skipped', $labels);
            $this->metrics->increment('dashflow.connector.fetch_skipped_'.($skipReason ?? 'rate_limited'), $labels);
        }

        if ($outcome === 'changed') {
            $this->metrics->increment('dashflow.ingestion.payload_changed', $labels);
        } elseif ($outcome === 'not_modified' || $outcome === 'unchanged') {
            $this->metrics->increment('dashflow.ingestion.fetch_'.$outcome, $labels);
        } elseif ($status === 'failed') {
            Log::warning('ingestion.fetch.failed', ['workspace_id' => $workspaceId, 'code' => $errorCode]);
        }
    }
}
