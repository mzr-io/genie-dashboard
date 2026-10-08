<?php

namespace App\Modules\Ingestion\Application;

use App\Modules\Connector\Contracts\EndpointFetcher;
use App\Modules\Connector\Contracts\EndpointFetchResult;
use App\Modules\Connector\Contracts\EndpointFetchSpec;
use App\Modules\Connector\Contracts\SyncRunLog;
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
    ) {}

    public function run(string $workspaceId, string $syncGroupId, int $dispatchSeq): void
    {
        /** @var list<object{id: string, data_source_id: string, endpoint_id: string, endpoint_revision_id: string, data_source_revision: int|string, applied_seq: int|string, etag: string|null, last_modified: string|null, current_payload_id: string|null}> $targets */
        $targets = DB::select(
            'select id, data_source_id, endpoint_id, endpoint_revision_id, data_source_revision, applied_seq, etag, last_modified, current_payload_id from sync_targets where workspace_id = ? and sync_group_id = ? and retired_at is null order by id',
            [$workspaceId, strtolower($syncGroupId)],
        );

        foreach ($targets as $target) {
            $this->fetchTarget($workspaceId, $target, $dispatchSeq);
        }
    }

    /** @param  object{id: string, data_source_id: string, endpoint_id: string, endpoint_revision_id: string, data_source_revision: int|string, applied_seq: int|string, etag: string|null, last_modified: string|null, current_payload_id: string|null}  $target */
    private function fetchTarget(string $workspaceId, object $target, int $dispatchSeq): void
    {
        $startedAt = CarbonImmutable::now()->utc();
        $runId = (string) Str::uuid7();
        $targetId = strtolower((string) $target->id);
        $dataSourceId = strtolower((string) $target->data_source_id);

        if ((int) $target->applied_seq >= $dispatchSeq) {
            // A late or duplicate run: a later one has already been applied, so there is nothing to call.
            $this->record($workspaceId, $runId, $dataSourceId, $targetId, $dispatchSeq, '', [], 'superseded', null, null, null, 'superseded', $startedAt);

            return;
        }

        // The conditional request: the stored ETag, else the stored Last-Modified, only when there is a payload to keep. The Data Source may still
        // refuse to send it (a paged call hashes only).
        $hasPayload = $target->current_payload_id !== null;
        $etag = $hasPayload && is_string($target->etag) && $target->etag !== '' ? $target->etag : null;
        $modified = $hasPayload && $etag === null && is_string($target->last_modified) && $target->last_modified !== '' ? $target->last_modified : null;

        $result = $this->fetcher->fetch(new EndpointFetchSpec(
            $workspaceId, $dataSourceId, strtolower((string) $target->endpoint_id), strtolower((string) $target->endpoint_revision_id),
            (int) $target->data_source_revision, $this->values($workspaceId, $targetId), $runId, $etag, $modified,
        ));

        if ($result->moved) {
            // The Endpoint or Data Source has a newer revision: this target is about to be retired, and no call was made for it.
            $this->record($workspaceId, $runId, $dataSourceId, $targetId, $dispatchSeq, $result->urlTemplate, $result->parameterNames, 'superseded', null, null, null, 'revision_moved', $startedAt);

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
            default => $result->code->value ?? 'fetch-failed',
        };

        $this->record(
            $workspaceId, $runId, $dataSourceId, $targetId, $dispatchSeq, $result->urlTemplate, $result->parameterNames, $status,
            $result->status, $result->latencyMs, $result->bytes, $status === 'succeeded' ? null : $code, $startedAt,
            $status === 'succeeded' ? $outcome : null,
        );
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
    ): void {
        $requestId = $this->context->requestId();

        try {
            // Its own savepoint: a failing history write must not undo what the run committed.
            DB::transaction(fn () => $this->runs->recordScheduledFetch(
                $workspaceId, $runId, $dataSourceId, $targetId, $dispatchSeq, $urlTemplate, $parameterNames, $status,
                $httpStatus, $latencyMs, $bytes, $errorCode, $requestId, $startedAt, $outcome,
            ));
        } catch (Throwable $e) {
            Log::error('ingestion.fetch.run_not_recorded', ['workspace_id' => $workspaceId, 'exception' => $e::class]);
        }

        // Counters carry the Workspace only: a request ID is unbounded and belongs in logs.
        $labels = ['workspace_id' => $workspaceId];
        $this->metrics->increment('dashflow.ingestion.fetch_'.$status, $labels);

        if ($outcome === 'changed') {
            $this->metrics->increment('dashflow.ingestion.payload_changed', $labels);
        } elseif ($outcome === 'not_modified' || $outcome === 'unchanged') {
            $this->metrics->increment('dashflow.ingestion.fetch_'.$outcome, $labels);
        } elseif ($status === 'failed') {
            Log::warning('ingestion.fetch.failed', ['workspace_id' => $workspaceId, 'code' => $errorCode]);
        }
    }
}
