<?php

namespace App\Modules\Blocks\Infrastructure;

use App\Modules\Blocks\Contracts\Drafts;
use App\Platform\EditLock\LockEpochs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The soft lock's epoch of a Draft (Story 3.3): `block_versions.lock_epoch` of a Draft that is not discarded, read and raised in the
 * caller's Workspace transaction under row-level security. Raising it touches neither `revision` nor `updated_at`: it is not an edit.
 */
final class DraftLockEpochs implements LockEpochs
{
    public const TYPE = Drafts::LOCK_TYPE;

    public function current(string $workspaceId, string $id): ?int
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        /** @var object{lock_epoch: int|string}|null $row */
        $row = DB::selectOne("select lock_epoch from block_versions where workspace_id = ? and id = ? and state = 'draft'", [$workspaceId, strtolower($id)]);

        return $row === null ? null : (int) $row->lock_epoch;
    }

    public function increment(string $workspaceId, string $id): ?int
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        /** @var object{lock_epoch: int|string}|null $row */
        $row = DB::selectOne("update block_versions set lock_epoch = lock_epoch + 1 where workspace_id = ? and id = ? and state = 'draft' returning lock_epoch", [$workspaceId, strtolower($id)]);

        return $row === null ? null : (int) $row->lock_epoch;
    }
}
