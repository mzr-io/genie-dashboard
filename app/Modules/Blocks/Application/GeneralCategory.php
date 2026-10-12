<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\BlockCategories;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The lazy "General" category (Story 3.4). {@see self::ensure()} runs inside a Workspace transaction, as the `app` role: it inserts the
 * category when missing (the unique index on the lower-cased name makes it idempotent, also under a race) and gives it to every Block of the
 * Workspace that has none, which is how Blocks that existed before categories get one.
 */
final class GeneralCategory
{
    /** @return string the ID of the Workspace's "General" category */
    public static function ensure(string $workspaceId): string
    {
        DB::insert(
            'insert into block_categories (id, workspace_id, name, created_at, updated_at) values (?, ?, ?, ?, ?) on conflict (workspace_id, lower(name)) do nothing',
            [(string) Str::uuid7(), $workspaceId, BlockCategories::GENERAL, now(), now()],
        );

        /** @var object{id: string} $row */
        $row = DB::selectOne('select id from block_categories where workspace_id = ? and lower(name) = lower(?)', [$workspaceId, BlockCategories::GENERAL]);
        $id = strtolower((string) $row->id);

        DB::update('update blocks set category_id = ? where workspace_id = ? and category_id is null', [$id, $workspaceId]);

        return $id;
    }
}
