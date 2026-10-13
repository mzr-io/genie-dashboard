<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\BlockImpact;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** {@see BlockImpact} over `block_usage`; the only table it reads. */
final class ReadBlockImpact implements BlockImpact
{
    public function __construct(private readonly WorkspaceTransaction $transactions) {}

    public function forBlock(string $workspaceId, string $blockId): array
    {
        if (! Str::isUuid($blockId)) {
            return ['users' => 0, 'templates' => []];
        }

        return $this->transactions->run($workspaceId, function () use ($blockId): array {
            /** @var object{users: int|string} $row */
            $row = DB::selectOne("select count(*) as users from block_usage where block_id = ? and kind = 'user'", [strtolower($blockId)]);

            return ['users' => (int) $row->users, 'templates' => []];
        });
    }
}
