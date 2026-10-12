<?php

namespace App\Modules\Results\Contracts;

/**
 * `compute_ctx` (Story 4.2; AD-25): the digest of everything besides the data that changes what a result looks like. A different value is a
 * different cache key, so a result is never served for a context it was not computed for.
 */
final class ComputeContext
{
    /** The Query Plan engine's version: bump it when its output for the same plan and payload can change. */
    public const ENGINE_VERSION = 1;

    /**
     * @param  array{start: string, end: string}|null  $period  the resolved period's Workspace-local dates, null for a Block with no period
     * @param  array{start: string, end: string}|null  $comparison  the comparison window, null when there is none
     */
    public static function of(?array $period, ?array $comparison, string $workspaceTimezone, int $settingsRevision, int $shaperContractVersion, int $engineVersion = self::ENGINE_VERSION): string
    {
        return 'cc1:'.hash('sha256', json_encode([
            'period' => $period === null ? null : [$period['start'], $period['end']],
            'comparison' => $comparison === null ? null : [$comparison['start'], $comparison['end']],
            'tz' => $workspaceTimezone,
            'settings' => $settingsRevision,
            'engine' => $engineVersion,
            'shaper' => $shaperContractVersion,
        ], JSON_THROW_ON_ERROR));
    }
}
