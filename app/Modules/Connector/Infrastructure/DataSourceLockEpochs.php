<?php

namespace App\Modules\Connector\Infrastructure;

use App\Modules\Connector\Contracts\DataSources;
use App\Platform\EditLock\LockEpochs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The soft lock's epoch of a Data Source (Story 2.8): `data_sources.lock_epoch`, read and raised in the caller's Workspace
 * transaction under row-level security. Raising it touches neither `revision` nor `updated_at`: it is not an edit.
 */
final class DataSourceLockEpochs implements LockEpochs
{
    public const TYPE = DataSources::LOCK_TYPE;

    public function current(string $workspaceId, string $id): ?int
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        /** @var object{lock_epoch: int|string}|null $row */
        $row = DB::selectOne('select lock_epoch from data_sources where workspace_id = ? and id = ?', [$workspaceId, strtolower($id)]);

        return $row === null ? null : (int) $row->lock_epoch;
    }

    public function increment(string $workspaceId, string $id): ?int
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        /** @var object{lock_epoch: int|string}|null $row */
        $row = DB::selectOne('update data_sources set lock_epoch = lock_epoch + 1 where workspace_id = ? and id = ? returning lock_epoch', [$workspaceId, strtolower($id)]);

        return $row === null ? null : (int) $row->lock_epoch;
    }
}
