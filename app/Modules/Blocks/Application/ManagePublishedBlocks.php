<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\PublishedBlock;
use App\Modules\Blocks\Contracts\PublishedBlocks;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\DB;

/** The minimal Published blocks list (Story 3.19): Blocks whose current version is published, with the version's name and the Block's category and owner. */
final class ManagePublishedBlocks implements PublishedBlocks
{
    private const STAMP = "'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"'";

    public function __construct(private readonly WorkspaceTransaction $transactions) {}

    public function list(string $workspaceId): array
    {
        return $this->transactions->run($workspaceId, function () use ($workspaceId): array {
            $rows = DB::select(
                'select b.id as block_id, v.id as version_id, v.name, b.type_key, coalesce(c.name, b.category) as category, v.version, b.owner_membership_id, '
                .'to_char(greatest(v.published_at, b.updated_at) at time zone \'UTC\', '.self::STAMP.') as updated '
                .'from blocks b join block_versions v on v.id = b.current_version_id and v.state = \'published\' '
                .'left join block_categories c on c.id = b.category_id '
                .'where b.workspace_id = ? order by greatest(v.published_at, b.updated_at) desc, b.id desc',
                [$workspaceId],
            );

            return array_values(array_map(fn (\stdClass $row): PublishedBlock => new PublishedBlock(
                strtolower((string) $row->block_id),
                strtolower((string) $row->version_id),
                (string) $row->name,
                (string) $row->type_key,
                $row->category === null ? null : (string) $row->category,
                (string) $row->version,
                strtolower((string) $row->owner_membership_id),
                (string) $row->updated,
                'published',
            ), $rows));
        });
    }
}
