<?php

namespace App\Modules\Ingestion\Application;

use App\Modules\Ingestion\Contracts\RefreshNow;
use App\Modules\Ingestion\Infrastructure\SyncSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** {@see RefreshNow}: makes a live target due now; the dispatcher's demand rule still decides whether and when it is fetched. */
final class BringTargetForward implements RefreshNow
{
    public function __construct(private readonly SyncSettings $settings) {}

    public function now(string $workspaceId, string $syncTargetId): bool
    {
        if (! Str::isUuid($syncTargetId)) {
            return false;
        }

        return DB::update(
            'update sync_targets set next_due_at = now(), updated_at = now() where workspace_id = ? and id = ? and retired_at is null and (next_due_at is null or next_due_at > now())',
            [$workspaceId, strtolower($syncTargetId)],
        ) > 0;
    }

    public function windowSeconds(): ?int
    {
        return $this->settings->manualRefreshWindowSeconds();
    }
}
