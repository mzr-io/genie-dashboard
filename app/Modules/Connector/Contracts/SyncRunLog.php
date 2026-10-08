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
     * @param  string  $status  `succeeded`, `failed` or `superseded`
     * @param  list<string>  $parameterNames
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
    ): string;
}
