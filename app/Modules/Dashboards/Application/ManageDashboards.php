<?php

namespace App\Modules\Dashboards\Application;

use App\Modules\Access\Contracts\RegisterAccessSubject;
use App\Modules\Access\Contracts\SubjectAccess;
use App\Modules\Blocks\Contracts\BlockCards;
use App\Modules\Dashboards\Contracts\DashboardNotFound;
use App\Modules\Dashboards\Contracts\DashboardRef;
use App\Modules\Dashboards\Contracts\Dashboards;
use App\Modules\Dashboards\Contracts\DashboardView;
use App\Platform\Layout\Grid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * {@see Dashboards}. The layout read gives each item its geometry clamped to the Block's own limits, its state (`unpublished` when the Block has no
 * published current version, `access_removed` when the member may no longer see it: access is re-checked on every read) and, for an item that can be
 * shown, the User-safe Block card. An item that cannot be shown carries no name and no data. `next_free_slot` is where a default-sized Block would go.
 */
final class ManageDashboards implements Dashboards
{
    public function __construct(private readonly BlockCards $cards, private readonly SubjectAccess $access) {}

    public function overview(string $workspaceId, string $membershipId): DashboardRef
    {
        DB::insert(
            'insert into dashboards (id, workspace_id, membership_id, name, is_overview, revision, created_at, updated_at) values (?, ?, ?, ?, true, 0, now(), now()) '
            .'on conflict (workspace_id, membership_id) where is_overview do nothing',
            [(string) Str::uuid7(), $workspaceId, $membershipId, 'Overview'],
        );

        $row = DB::selectOne(
            'select id, revision from dashboards where workspace_id = ? and membership_id = ? and is_overview',
            [$workspaceId, $membershipId],
        );

        return new DashboardRef(strtolower((string) $row->id), (int) $row->revision);
    }

    public function find(string $workspaceId, string $membershipId, string $dashboardId): DashboardView
    {
        $dashboard = Str::isUuid($dashboardId)
            ? DB::selectOne(
                'select id, name, is_overview, revision from dashboards where workspace_id = ? and membership_id = ? and id = ?',
                [$workspaceId, $membershipId, strtolower($dashboardId)],
            )
            : null;

        if ($dashboard === null) {
            throw new DashboardNotFound;
        }

        /** @var list<\stdClass> $rows */
        $rows = DB::select(
            'select id, block_id, col, row, width, height, minimized from dashboard_items where workspace_id = ? and dashboard_id = ? order by row, col, id',
            [$workspaceId, strtolower((string) $dashboard->id)],
        );

        $blockIds = array_map(fn (\stdClass $r): string => strtolower((string) $r->block_id), $rows);
        $cards = $this->cards->forBlocks($workspaceId, $blockIds);
        $visible = $this->access->canView($workspaceId, $membershipId, RegisterAccessSubject::BLOCK, $blockIds);
        $items = [];
        $placed = [];

        foreach ($rows as $row) {
            $blockId = strtolower((string) $row->block_id);
            $card = $cards[$blockId] ?? null;
            $state = ! ($visible[$blockId] ?? false) ? 'access_removed' : ($card === null ? 'unpublished' : 'ok');
            $sizes = $state === 'ok' ? $card->sizes : ['min_width' => 3, 'max_width' => 12, 'min_height' => 'small', 'max_height' => 'large'];
            $geometry = Grid::clamp((int) $row->col, (int) $row->row, (int) $row->width, (string) $row->height, $sizes['min_width'], $sizes['max_width'], $sizes['min_height'], $sizes['max_height']);
            $placed[] = $geometry;

            $items[] = [
                'item_id' => strtolower((string) $row->id),
                'block_id' => $blockId,
                'geometry' => $geometry,
                'minimized' => (bool) $row->minimized,
                'state' => $state,
                'refresh_interval' => $state === 'ok' ? $card->refreshInterval : null,
                'is_live' => $state === 'ok' && $card->refreshInterval === 'live',
                'block' => $state === 'ok' ? [
                    'name' => $card->name,
                    'subtitle' => $card->subtitle,
                    'type' => $card->type,
                    'icon' => $card->icon,
                    'chrome' => $card->chrome,
                ] : null,
            ];
        }

        return new DashboardView(
            strtolower((string) $dashboard->id),
            (string) $dashboard->name,
            (bool) $dashboard->is_overview,
            (int) $dashboard->revision,
            $items,
            Grid::nextFreeSlot($placed, 3, 'small'),
        );
    }
}
