<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftLockLost;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\DraftPublished;
use App\Modules\Blocks\Contracts\DraftSamples;
use App\Modules\Blocks\Contracts\SampleRejected;
use App\Modules\Blocks\Contracts\SampleUnavailable;
use App\Modules\Connector\Contracts\SampleRuns;
use App\Platform\Operations\Operation;
use App\Platform\Operations\OperationHandler;
use App\Platform\Operations\OperationOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The `draft_sample_fetch` Operation (Story 3.9), run by `worker-connector`. It asks the Connector to run the request (the machinery of Story 2.10:
 * the guard, the transport, the error ladder, the `sync_runs` row), and on success takes the body from the Operation's encrypted Valkey blob and
 * writes it to `draft_samples` (origin fetched) through the same sealed storage, limits and shape as a pasted sample, replacing the Draft's sample
 * and raising its `sample_revision`. The blob is dropped at once.
 *
 * Nothing is stored for a failure, a response over a limit (never truncated), an Operation that ran past its lifetime (the timeout ceiling), a
 * Draft whose edit lock was taken over meanwhile (`stale`) or a Draft that is gone. A Draft whose revision moved while the fetch ran still gets the
 * sample: the Admin is told it belongs to earlier parameters (the request key stored with it is compared with the Draft's current one).
 *
 * The summary adds to the Test endpoint's (`ok`, `status`, `latency_ms`, `code`, `reason`, `size_bytes`, `limit_bytes`, `host`, `request_id`,
 * `endpoint_revision`, `page`, `pages`) the Draft's revision at the start, the stored `sample_revision` and where the records are (a count and
 * the display path), for `fetch-ok`. It never holds the body.
 */
final class FetchDraftSample implements OperationHandler
{
    public function __construct(
        private readonly SampleRuns $runs,
        private readonly DraftSamples $samples,
    ) {}

    public function handle(Operation $operation, array $input): OperationOutcome
    {
        $dataSourceId = is_string($input['data_source_id'] ?? null) ? $input['data_source_id'] : null;
        $endpointId = is_string($input['endpoint_id'] ?? null) ? $input['endpoint_id'] : null;
        $endpointRevision = is_int($input['endpoint_revision'] ?? null) ? $input['endpoint_revision'] : null;
        $requestKey = is_string($input['request_key'] ?? null) ? $input['request_key'] : null;
        $lockEpoch = is_int($input['lock_epoch'] ?? null) ? $input['lock_epoch'] : null;
        $values = [];

        foreach (is_array($input['values'] ?? null) ? $input['values'] : [] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $values[$name] = $value;
            }
        }

        if ($dataSourceId === null || $endpointId === null || $endpointRevision === null || $requestKey === null || $lockEpoch === null || $operation->subjectId === null) {
            return $this->failed(['ok' => false], 'invalid_input', $operation);
        }

        $outcome = $this->runs->run($operation, $dataSourceId, $endpointId, $endpointRevision, $values);
        $summary = $outcome->summary + ['draft_revision' => $operation->subjectRevision, 'sample_revision' => null, 'records' => null, 'records_path' => null];

        if (! $outcome->succeeded) {
            // A failed or stale fetch stores nothing; its summary (a user code, the status, the request ID, the host) is the Connector's own.
            return new OperationOutcome(false, $summary, $outcome->stale);
        }

        // Past its lifetime (the timeout ceiling): the Admin sees it expired and retries, and nothing partial is kept.
        if (CarbonImmutable::parse($operation->expiresAt)->getTimestamp() <= CarbonImmutable::now()->getTimestamp()) {
            return $this->failed($summary, 'timeout', $operation);
        }

        $body = $this->runs->take($operation, $endpointId, $endpointRevision);

        if ($body === null) {
            return $this->failed($summary, 'blob_unavailable', $operation);
        }

        // Clamped to the columns: `fetch_status` is a smallint and `fetch_latency_ms` an integer.
        $status = is_int($summary['status'] ?? null) ? max(0, min(32767, $summary['status'])) : 200;
        $latency = is_int($summary['latency_ms'] ?? null) ? max(0, min(2147483647, $summary['latency_ms'])) : 0;

        try {
            // In a savepoint: a database failure must not abort the worker's transaction, which still has to write the Operation's summary.
            $stored = DB::transaction(fn () => $this->samples->storeFetched(
                new DraftActor($operation->requesterMembershipId, $operation->workspaceId), $operation->subjectId, $body, $lockEpoch, $status, $latency, $requestKey,
            ));
        } catch (DraftLockLost) {
            // Someone took the edit lock over while the fetch ran: the result is for a Draft this tab no longer owns.
            return OperationOutcome::stale($this->failedSummary($summary, 'lock_lost'));
        } catch (DraftNotFound|DraftPublished) {
            return $this->failed($summary, 'draft_gone', $operation);
        } catch (SampleRejected $e) {
            return $this->failed($summary, $e->reason, $operation, match ($e->reason) {
                'too_large' => 'response-too-large',
                'not_json' => 'not-json',
                default => null,
            }, $e->reason === 'too_large' ? $e->check?->limit : null);
        } catch (SampleUnavailable) {
            return $this->failed($summary, 'blob_unavailable', $operation);
        } catch (\Throwable $e) {
            // Anything else (the database, say): the Operation still ends with a summary, and only the class is logged.
            Log::error('blocks.draft_sample_fetch.store_failed', ['workspace_id' => $operation->workspaceId, 'operation_id' => $operation->id, 'exception' => $e::class]);

            return $this->failed($summary, 'store_failed', $operation);
        } finally {
            $this->runs->discard($operation);
        }

        $records = $stored->check->shape['records'] ?? null;

        return new OperationOutcome(true, [
            'sample_revision' => $stored->sampleRevision,
            'records' => $records === null ? null : $records['count'],
            'records_path' => $records === null ? null : mb_substr($records['display'], 0, OperationOutcome::VALUE_MAX),
        ] + $summary);
    }

    /** Whatever the outcome, the Operation's blob is dropped: a failed, stale, expired or crashed fetch leaves none (idempotent). */
    public function cleanup(Operation $operation): void
    {
        $this->runs->discard($operation);
    }

    /**
     * @param  array<string, string|int|bool|null>  $summary
     * @return array<string, string|int|bool|null>
     */
    private function failedSummary(array $summary, string $reason, ?string $code = 'fetch-failed', ?int $limit = null): array
    {
        return ['ok' => false, 'code' => $code, 'reason' => $reason] + ($limit === null ? [] : ['limit_bytes' => $limit]) + $summary;
    }

    /**
     * @param  array<string, string|int|bool|null>  $summary
     */
    private function failed(array $summary, string $reason, Operation $operation, ?string $code = null, ?int $limit = null): OperationOutcome
    {
        Log::warning('blocks.draft_sample_fetch.not_stored', ['workspace_id' => $operation->workspaceId, 'operation_id' => $operation->id, 'reason' => $reason]);

        return new OperationOutcome(false, $this->failedSummary($summary, $reason, $code ?? 'fetch-failed', $limit));
    }
}
