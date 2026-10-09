<?php

namespace App\Modules\Connector\Contracts;

/**
 * Writes the `sync_runs` row of a scheduled fetch (Story 2.14): `sync_runs` stays owned by Connector and Ingestion writes through
 * this contract, in the caller's Workspace transaction. The row holds the sanitised URL template and the parameter names, never a
 * query string, a value, a header or a secret.
 */
interface SyncRunLog
{
    /** The `sync_runs.kind` of a scheduled fetch. */
    public const KIND = 'scheduled_fetch';

    /**
     * @param  string  $status  `succeeded`, `failed`, `superseded`, `retrying` (Story 2.17: a retryable failure queued again) or `skipped` (no call: the breaker or a rate limit)
     * @param  list<string>  $parameterNames
     * @param  string|null  $outcome  Story 2.15, of a `succeeded` run: `changed`, `not_modified` or `unchanged` (null before and for any other run)
     * @param  int|null  $attempt  Story 2.17: the attempt number (1 for the first call), null when unknown
     * @return string the run's id (`$id` when given)
     */
    public function recordScheduledFetch(
        string $workspaceId,
        ?string $id,
        ?string $dataSourceId,
        string $syncTargetId,
        int $dispatchSeq,
        string $urlTemplate,
        array $parameterNames,
        string $status,
        ?int $httpStatus,
        ?int $latencyMs,
        ?int $bytes,
        ?string $errorCode,
        ?string $requestId,
        \DateTimeInterface $startedAt,
        ?string $outcome = null,
        ?int $attempt = null,
    ): string;
}
