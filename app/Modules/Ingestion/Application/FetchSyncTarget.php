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
 *  - a good body (2xx JSON): one transaction moves `applied_seq` to this run's `dispatch_seq` only `WHERE applied_seq < :dispatch_seq` (zero rows is
 *    `superseded` and nothing else changes), then RawStore keeps the exact bytes and an immutable observation, and the target gets
 *    `current_payload_id`, `payload_seq`, `last_success_at`, `last_checked_at` and `consecutive_failures` 0 together with the outbox event
 *    `ingestion.payload.changed` (IDs and sequences only; until Story 2.15 every success is a change);
 *  - a failed run (any ladder code, a non-JSON body, a limit): only `last_checked_at`, `consecutive_failures` and `applied_seq`, under the same
 *    guard. The last good payload stays and nothing is truncated.
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
        /** @var list<object{id: string, data_source_id: string, endpoint_id: string, endpoint_revision_id: string, data_source_revision: int|string, applied_seq: int|string}> $targets */
        $targets = DB::select(
            'select id, data_source_id, endpoint_id, endpoint_revision_id, data_source_revision, applied_seq from sync_targets where workspace_id = ? and sync_group_id = ? and retired_at is null order by id',
            [$workspaceId, strtolower($syncGroupId)],
        );

        foreach ($targets as $target) {
            $this->fetchTarget($workspaceId, $target, $dispatchSeq);
        }
    }

    /** @param  object{id: string, data_source_id: string, endpoint_id: string, endpoint_revision_id: string, data_source_revision: int|string, applied_seq: int|string}  $target */
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

        $result = $this->fetcher->fetch(new EndpointFetchSpec(
            $workspaceId, $dataSourceId, strtolower((string) $target->endpoint_id), strtolower((string) $target->endpoint_revision_id),
            (int) $target->data_source_revision, $this->values($workspaceId, $targetId), $runId,
        ));

        if ($result->moved) {
            // The Endpoint or Data Source has a newer revision: this target is about to be retired, and no call was made for it.
            $this->record($workspaceId, $runId, $dataSourceId, $targetId, $dispatchSeq, $result->urlTemplate, $result->parameterNames, 'superseded', null, null, null, 'revision_moved', $startedAt);

            return;
        }

        $outcome = $result->ok
            ? $this->commitSuccess($workspaceId, $target, $targetId, $dispatchSeq, $result)
            : $this->commitFailure($workspaceId, $targetId, $dispatchSeq);

        if ($outcome === 'store_failed') {
            // The good body could not be kept: it is a failed run, and the last good payload still stands.
            $this->commitFailure($workspaceId, $targetId, $dispatchSeq);
        }

        $status = match ($outcome) {
            'superseded' => 'superseded',
            'succeeded' => 'succeeded',
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
        );
    }

    /**
     * @param  object{endpoint_id: string}  $target
     * @return 'succeeded'|'superseded'|'store_failed'
     */
    private function commitSuccess(string $workspaceId, object $target, string $targetId, int $dispatchSeq, EndpointFetchResult $result): string
    {
        $now = CarbonImmutable::now()->utc();

        try {
            return DB::transaction(function () use ($workspaceId, $target, $targetId, $dispatchSeq, $result, $now): string {
                // The fence: a run that is not newer than the last applied one changes nothing at all.
                $moved = DB::selectOne(
                    'update sync_targets set applied_seq = ?, payload_seq = payload_seq + 1, last_success_at = clock_timestamp(), last_checked_at = clock_timestamp(), consecutive_failures = 0, updated_at = clock_timestamp() '
                    .'where workspace_id = ? and id = ? and applied_seq < ? returning payload_seq',
                    [$dispatchSeq, $workspaceId, $targetId, $dispatchSeq],
                );

                if ($moved === null) {
                    return 'superseded';
                }

                $seq = (int) $moved->payload_seq;
                $payload = $this->raw->put($workspaceId, $targetId, $seq, $dispatchSeq, (string) $result->body, $this->context->requestId(), $now);

                DB::update(
                    'update sync_targets set current_payload_id = ?, content_hash = ? where workspace_id = ? and id = ?',
                    [$payload->id, $payload->contentHash, $workspaceId, $targetId],
                );

                $this->outbox->emit(AuditAction::IngestionPayloadChanged, 'sync_target:'.$targetId, [
                    'sync_target_id' => $targetId,
                    'endpoint_id' => strtolower((string) $target->endpoint_id),
                    'payload_id' => $payload->id,
                    'payload_seq' => $seq,
                    'dispatch_seq' => $dispatchSeq,
                ]);

                return 'succeeded';
            });
        } catch (Throwable $e) {
            // Only the class: a storage error message could carry what was being stored.
            Log::error('ingestion.fetch.store_failed', ['workspace_id' => $workspaceId, 'exception' => $e::class]);

            return 'store_failed';
        }
    }

    /** @return 'failed'|'superseded' */
    private function commitFailure(string $workspaceId, string $targetId, int $dispatchSeq): string
    {
        try {
            $row = DB::transaction(fn () => DB::selectOne(
                'update sync_targets set applied_seq = ?, last_checked_at = clock_timestamp(), consecutive_failures = consecutive_failures + 1, updated_at = clock_timestamp() '
                .'where workspace_id = ? and id = ? and applied_seq < ? returning consecutive_failures',
                [$dispatchSeq, $workspaceId, $targetId, $dispatchSeq],
            ));
        } catch (Throwable $e) {
            Log::error('ingestion.fetch.failure_not_recorded', ['workspace_id' => $workspaceId, 'exception' => $e::class]);

            return 'failed';
        }

        return $row === null ? 'superseded' : 'failed';
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
        string $status, ?int $httpStatus, ?int $latencyMs, ?int $bytes, ?string $errorCode, CarbonImmutable $startedAt,
    ): void {
        $requestId = $this->context->requestId();

        try {
            // Its own savepoint: a failing history write must not undo what the run committed.
            DB::transaction(fn () => $this->runs->recordScheduledFetch(
                $workspaceId, $runId, $dataSourceId, $targetId, $dispatchSeq, $urlTemplate, $parameterNames, $status,
                $httpStatus, $latencyMs, $bytes, $errorCode, $requestId, $startedAt,
            ));
        } catch (Throwable $e) {
            Log::error('ingestion.fetch.run_not_recorded', ['workspace_id' => $workspaceId, 'exception' => $e::class]);
        }

        // Counters carry the Workspace only: a request ID is unbounded and belongs in logs.
        $labels = ['workspace_id' => $workspaceId];
        $this->metrics->increment('dashflow.ingestion.fetch_'.$status, $labels);

        if ($status === 'succeeded') {
            $this->metrics->increment('dashflow.ingestion.payload_changed', $labels);
        } elseif ($status === 'failed') {
            Log::warning('ingestion.fetch.failed', ['workspace_id' => $workspaceId, 'code' => $errorCode]);
        }
    }
}
