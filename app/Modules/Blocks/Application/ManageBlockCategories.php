<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\BlockCategories;
use App\Modules\Blocks\Contracts\Category;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\DB;

/** Reads the Workspace's block categories (Story 3.4), seeding "General" on the way ({@see GeneralCategory}). */
final class ManageBlockCategories implements BlockCategories
{
    public function __construct(private readonly WorkspaceTransaction $transactions) {}

    public function all(string $workspaceId): array
    {
        return $this->transactions->run($workspaceId, function () use ($workspaceId): array {
            GeneralCategory::ensure($workspaceId);

            $rows = DB::select('select id, name, archived from block_categories where workspace_id = ? order by lower(name), id', [$workspaceId]);

            return array_values(array_map(
                fn (\stdClass $row): Category => new Category(strtolower((string) $row->id), (string) $row->name, (bool) $row->archived),
                $rows,
            ));
        });
    }
}
