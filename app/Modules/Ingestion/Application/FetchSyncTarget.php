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
        /** @var list<object{id: string, data_source_id: string, endpoint_id: string, endpoint_revision_id: string, data_source_revision: int|string, applied_seq: int|string, etag: string|null, last_modified: string|null, current_payload_id: string|null}> $targets */
        $targets = DB::select(
            'select id, data_source_id, endpoint_id, endpoint_revision_id, data_source_revision, applied_seq, etag, last_modified, current_payload_id from sync_targets where workspace_id = ? and sync_group_id = ? and retired_at is null order by id',
            [$workspaceId, strtolower($syncGroupId)],
        );

        foreach ($targets as $target) {
            $this->fetchTarget($workspaceId, strtolower($syncGroupId), $target, $dispatchSeq, $attempt);
        }
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
        EndpointFetchResult $result, ?RetryPolicy $retry, bool $probe, CarbonImmutable $startedAt,
    ): bool {
        if ($retry === null || $probe || $attempt >= $retry->maxAttempts || $result->failureClass === null || ! $result->failureClass->retryable()) {
            return false;
        }

        $delay = $result->failureClass === FailureClass::Throttled && $result->retryAfterSeconds !== null
            ? max(1, min($retry->cap, $result->retryAfterSeconds))
            : $this->jitter->upTo($retry->ceiling($attempt));

        if (! $this->fits($workspaceId, $targetId, $delay)) {
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
        $breaker = $admission->reason === Admission::CIRCUIT_OPEN;

        if (! $breaker && $retry !== null) {
            $wait = $admission->reason === Admission::CONCURRENCY ? $retry->base : $admission->waitSeconds;

            if ($this->fits($workspaceId, $targetId, $wait) && $this->requeue($workspaceId, $syncGroupId, $dispatchSeq, $attempt, $wait)) {
                // A wait for the governor is not a retry: its own counter.
                $this->metrics->increment('dashflow.connector.fetch_requeued', ['workspace_id' => $workspaceId]);

                return;
            }
        }

        $code = $breaker ? 'circuit-open' : 'rate-limited';

        try {
            // Under the fence, only `applied_seq` moves: a skip is neither a failure nor a check of the source.
            $moved = DB::transaction(fn () => DB::selectOne(
                'update sync_targets set applied_seq = ?, updated_at = clock_timestamp() where workspace_id = ? and id = ? and applied_seq < ? returning id',
                [$dispatchSeq, $workspaceId, $targetId, $dispatchSeq],
            ));
        } catch (Throwable $e) {
            Log::error('ingestion.fetch.skip_not_recorded', ['workspace_id' => $workspaceId, 'exception' => $e::class]);
            $moved = null;
        }

        $this->record(
            $workspaceId, $runId, $dataSourceId, $targetId, $dispatchSeq, '', [], $moved === null ? 'superseded' : 'skipped',
            null, null, null, $moved === null ? 'superseded' : $code, $startedAt, null, $attempt, $breaker ? 'circuit_open' : 'rate_limited',
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
        $now = CarbonImmutable::now()->utc();

        try {
            return DB::transaction(function () use ($workspaceId, $target, $targetId, $dispatchSeq, $result, $sent, $now): string {
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

                $this->outbox->emit(AuditAction::IngestionPayloadChanged, 'sync_target:'.$targetId, [
                    'sync_target_id' => $targetId,
                    'endpoint_id' => strtolower((string) $target->endpoint_id),
                    'payload_id' => $payload->id,
                    'payload_seq' => $seq,
                    'dispatch_seq' => $dispatchSeq,
                ]);

                return 'changed';
            });
        } catch (Throwable $e) {
            // Only the class: a storage error message could carry what was being stored.
            Log::error('ingestion.fetch.store_failed', ['workspace_id' => $workspaceId, 'exception' => $e::class]);

            return 'store_failed';
        }
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
