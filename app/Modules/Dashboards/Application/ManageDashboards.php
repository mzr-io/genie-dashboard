<?php

namespace App\Modules\Dashboards\Application;

use App\Modules\Access\Contracts\RegisterAccessSubject;
use App\Modules\Access\Contracts\SubjectAccess;
use App\Modules\Blocks\Contracts\BlockCards;
use App\Modules\Dashboards\Contracts\BlockNotAvailable;
use App\Modules\Dashboards\Contracts\DashboardNotFound;
use App\Modules\Dashboards\Contracts\DashboardRef;
use App\Modules\Dashboards\Contracts\Dashboards;
use App\Modules\Dashboards\Contracts\DashboardView;
use App\Modules\Dashboards\Contracts\InvalidLayout;
use App\Modules\Dashboards\Contracts\ItemChange;
use App\Modules\Dashboards\Contracts\ItemLocked;
use App\Modules\Dashboards\Contracts\ItemNotFound;
use App\Modules\Dashboards\Contracts\RevisionConflict;
use App\Modules\Results\Contracts\ManualRefresh;
use App\Modules\Results\Contracts\RefreshOutcome;
use App\Modules\Results\Contracts\ResultState;
use App\Modules\Results\Contracts\ViewedResults;
use App\Modules\Results\Contracts\ViewRequest;
use App\Platform\Audit\AuditAction;
use App\Platform\Layout\Grid;
use App\Platform\Outbox\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * {@see Dashboards}. The layout read gives each item its geometry clamped to the Block's own limits, its state (`unpublished` when the Block has no
 * published current version, `access_removed` when the member may no longer see it: access is re-checked on every read) and, for an item that can be
 * shown, the User-safe Block card. An item that cannot be shown carries no name and no data. `next_free_slot` is where a default-sized Block would go.
 */
final class ManageDashboards implements Dashboards
{
    public function __construct(
        private readonly BlockCards $cards,
        private readonly SubjectAccess $access,
        private readonly ViewedResults $results,
        private readonly ManualRefresh $refreshes,
        private readonly Outbox $outbox,
    ) {}

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
                    'about' => $card->about,
                    'sizes' => $card->sizes,
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

    public function results(string $workspaceId, string $membershipId, string $dashboardId, ?string $range): array
    {
        $dashboard = Str::isUuid($dashboardId)
            ? DB::selectOne('select id from dashboards where workspace_id = ? and membership_id = ? and id = ?', [$workspaceId, $membershipId, strtolower($dashboardId)])
            : null;

        if ($dashboard === null) {
            throw new DashboardNotFound;
        }

        /** @var list<\stdClass> $rows */
        $rows = DB::select('select id, block_id from dashboard_items where workspace_id = ? and dashboard_id = ? order by row, col, id', [$workspaceId, strtolower((string) $dashboard->id)]);
        $blockIds = array_map(fn (\stdClass $r): string => strtolower((string) $r->block_id), $rows);
        $cards = $this->cards->forBlocks($workspaceId, $blockIds);
        $visible = $this->access->canView($workspaceId, $membershipId, RegisterAccessSubject::BLOCK, $blockIds);
        $out = [];

        foreach ($rows as $row) {
            $blockId = strtolower((string) $row->block_id);
            $base = ['item_id' => strtolower((string) $row->id), 'block_id' => $blockId];
            $card = $cards[$blockId] ?? null;

            if (! ($visible[$blockId] ?? false)) {
                $out[] = $base + self::empty(ResultState::AccessRemoved);

                continue;
            }

            if ($card === null) {
                $out[] = $base + self::empty(ResultState::Unpublished);

                continue;
            }

            $viewed = $this->results->view(new ViewRequest($workspaceId, $membershipId, $card->versionId, null, $range));
            $result = $viewed->result;

            $out[] = $base + ($result === null ? self::empty($viewed->state) : [
                'state' => $viewed->state->value,
                'stale' => $result->stale,
                'refreshing' => $result->refreshing,
                'data_as_of' => $result->dataAsOf,
                'last_success_at' => $result->lastSuccessAt,
                'stale_at' => $viewed->staleAt,
                'result_etag' => $result->resultEtag,
                'slot_states' => $result->slotStates,
                'render_payload' => $result->renderPayload,
            ]);
        }

        return $out;
    }

    /** @return array<string, mixed> an item with a state and no data */
    private static function empty(ResultState $state): array
    {
        return [
            'state' => $state->value, 'stale' => false, 'refreshing' => false, 'data_as_of' => null, 'last_success_at' => null, 'stale_at' => null,
            'result_etag' => null, 'slot_states' => (object) [], 'render_payload' => null,
        ];
    }

    public function setMinimized(string $workspaceId, string $membershipId, string $dashboardId, string $itemId, bool $minimized, int $expectedRevision): int
    {
        $dashboard = Str::isUuid($dashboardId)
            ? DB::selectOne(
                'select id, revision from dashboards where workspace_id = ? and membership_id = ? and id = ? for update',
                [$workspaceId, $membershipId, strtolower($dashboardId)],
            )
            : null;

        if ($dashboard === null) {
            throw new DashboardNotFound;
        }

        $revision = (int) $dashboard->revision;

        if ($revision !== $expectedRevision) {
            throw new RevisionConflict($revision);
        }

        $item = Str::isUuid($itemId)
            ? DB::selectOne('select id, minimized from dashboard_items where workspace_id = ? and dashboard_id = ? and id = ? for update', [$workspaceId, strtolower((string) $dashboard->id), strtolower($itemId)])
            : null;

        if ($item === null) {
            throw new ItemNotFound;
        }

        if ((bool) $item->minimized === $minimized) {
            return $revision;
        }

        DB::update('update dashboard_items set minimized = ?, updated_at = now() where workspace_id = ? and id = ?', [$minimized, $workspaceId, strtolower((string) $item->id)]);
        DB::update('update dashboards set revision = revision + 1, updated_at = now() where workspace_id = ? and id = ?', [$workspaceId, strtolower((string) $dashboard->id)]);

        $this->outbox->emit(AuditAction::DashboardsDashboardChanged, 'dashboard:'.strtolower((string) $dashboard->id), [
            'dashboard_id' => strtolower((string) $dashboard->id),
            'item_id' => strtolower((string) $item->id),
            'command' => 'set_minimized',
            'revision' => $revision + 1,
        ]);

        return $revision + 1;
    }

    public function refresh(string $workspaceId, string $membershipId, string $dashboardId, string $itemId): RefreshOutcome
    {
        $dashboard = Str::isUuid($dashboardId)
            ? DB::selectOne('select id from dashboards where workspace_id = ? and membership_id = ? and id = ?', [$workspaceId, $membershipId, strtolower($dashboardId)])
            : null;

        if ($dashboard === null) {
            throw new DashboardNotFound;
        }

        $item = Str::isUuid($itemId)
            ? DB::selectOne('select block_id from dashboard_items where workspace_id = ? and dashboard_id = ? and id = ?', [$workspaceId, strtolower((string) $dashboard->id), strtolower($itemId)])
            : null;

        if ($item === null) {
            throw new ItemNotFound;
        }

        $blockId = strtolower((string) $item->block_id);
        $card = $this->cards->forBlocks($workspaceId, [$blockId])[$blockId] ?? null;

        // A Block the member can no longer see, or that is no longer published, has nothing to refresh.
        if ($card === null || ! ($this->access->canView($workspaceId, $membershipId, RegisterAccessSubject::BLOCK, [$blockId])[$blockId] ?? false)) {
            return RefreshOutcome::unavailable();
        }

        return $this->refreshes->refresh(new ViewRequest($workspaceId, $membershipId, $card->versionId));
    }

    public function addItem(string $workspaceId, string $membershipId, string $dashboardId, string $blockId, int $expectedRevision): ItemChange
    {
        return $this->place($workspaceId, $membershipId, $dashboardId, $blockId, $expectedRevision, null, false, 'add_item');
    }

    public function restoreItem(string $workspaceId, string $membershipId, string $dashboardId, string $blockId, ?array $geometry, bool $minimized, int $expectedRevision): ItemChange
    {
        return $this->place($workspaceId, $membershipId, $dashboardId, $blockId, $expectedRevision, $geometry, $minimized, 'restore_item');
    }

    /**
     * The shared body of `AddItem` and `RestoreItem`: the dashboard is held, an existing item answers as it is, the Block must be visible and published,
     * the revision must match, and the item goes to its remembered spot when that is free (clamped to the Block's limits) and otherwise to the first
     * free position at that size. No other item moves.
     *
     * @param  array{col: int, row: int, width: int, height: string}|null  $geometry
     */
    private function place(string $workspaceId, string $membershipId, string $dashboardId, string $blockId, int $expectedRevision, ?array $geometry, bool $minimized, string $command): ItemChange
    {
        $dashboard = $this->lock($workspaceId, $membershipId, $dashboardId);
        $blockId = strtolower($blockId);
        $id = strtolower((string) $dashboard->id);
        $revision = (int) $dashboard->revision;

        // Idempotent: a Block already here is returned as it is, so a delivery repeated after the revision moved is not a conflict.
        $existing = Str::isUuid($blockId)
            ? DB::selectOne('select id from dashboard_items where workspace_id = ? and dashboard_id = ? and block_id = ?', [$workspaceId, $id, $blockId])
            : null;

        if ($existing !== null) {
            return $this->changeOf($workspaceId, $membershipId, $id, strtolower((string) $existing->id), false, $revision);
        }

        if (! Str::isUuid($blockId) || ! ($this->access->canView($workspaceId, $membershipId, RegisterAccessSubject::BLOCK, [$blockId])[$blockId] ?? false)) {
            throw new BlockNotAvailable(BlockNotAvailable::NOT_FOUND);
        }

        $card = $this->cards->forBlocks($workspaceId, [$blockId])[$blockId] ?? null;

        if ($card === null) {
            throw new BlockNotAvailable($this->cards->exists($workspaceId, $blockId) ? BlockNotAvailable::UNPUBLISHED : BlockNotAvailable::NOT_FOUND);
        }

        if ($revision !== $expectedRevision) {
            throw new RevisionConflict($revision);
        }

        /** @var list<\stdClass> $rows */
        $rows = DB::select('select col, row, width, height from dashboard_items where workspace_id = ? and dashboard_id = ?', [$workspaceId, $id]);
        $placed = array_map(fn (\stdClass $r): array => ['col' => (int) $r->col, 'row' => (int) $r->row, 'width' => (int) $r->width, 'height' => (string) $r->height], $rows);

        $size = $card->sizes;
        $want = $geometry ?? ['col' => 0, 'row' => 0, 'width' => $size['width'], 'height' => $size['height']];
        $fit = Grid::clamp($want['col'], $want['row'], $want['width'], $want['height'], $size['min_width'], $size['max_width'], $size['min_height'], $size['max_height']);
        $spot = $geometry !== null && Grid::fits($placed, $fit['col'], $fit['row'], $fit['width'], $fit['height'])
            ? ['col' => $fit['col'], 'row' => $fit['row']]
            : Grid::nextFreeSlot($placed, $fit['width'], $fit['height']);
        $itemId = (string) Str::uuid7();

        DB::insert(
            'insert into dashboard_items (id, workspace_id, dashboard_id, block_id, col, row, width, height, minimized, mandatory, created_at, updated_at) '
            .'values (?, ?, ?, ?, ?, ?, ?, ?, ?, false, now(), now())',
            [$itemId, $workspaceId, $id, $blockId, $spot['col'], $spot['row'], $fit['width'], $fit['height'], $minimized],
        );
        DB::update('update dashboards set revision = revision + 1, updated_at = now() where workspace_id = ? and id = ?', [$workspaceId, $id]);

        $this->outbox->emit(AuditAction::DashboardsDashboardChanged, 'dashboard:'.$id, [
            'dashboard_id' => $id, 'item_id' => $itemId, 'command' => $command, 'revision' => $revision + 1,
        ]);

        return $this->changeOf($workspaceId, $membershipId, $id, $itemId, true, $revision + 1);
    }

    public function removeItem(string $workspaceId, string $membershipId, string $dashboardId, string $blockId, int $expectedRevision): ItemChange
    {
        $dashboard = $this->lock($workspaceId, $membershipId, $dashboardId);
        $id = strtolower((string) $dashboard->id);
        $revision = (int) $dashboard->revision;
        $blockId = strtolower($blockId);

        $item = Str::isUuid($blockId)
            ? DB::selectOne('select id, mandatory from dashboard_items where workspace_id = ? and dashboard_id = ? and block_id = ? for update', [$workspaceId, $id, $blockId])
            : null;

        // Removing what is not there changes nothing and is not an error.
        if ($item === null) {
            return new ItemChange($revision, false);
        }

        if ((bool) $item->mandatory) {
            throw new ItemLocked;
        }

        if ($revision !== $expectedRevision) {
            throw new RevisionConflict($revision);
        }

        $removed = (bool) DB::selectOne('select dashboards_remove_item(?::uuid, ?::uuid) as removed', [$id, strtolower((string) $item->id)])->removed;

        if (! $removed) {
            return new ItemChange($revision, false);
        }

        DB::update('update dashboards set revision = revision + 1, updated_at = now() where workspace_id = ? and id = ?', [$workspaceId, $id]);

        $this->outbox->emit(AuditAction::DashboardsDashboardChanged, 'dashboard:'.$id, [
            'dashboard_id' => $id, 'item_id' => strtolower((string) $item->id), 'command' => 'remove_item', 'revision' => $revision + 1,
        ]);

        return new ItemChange($revision + 1, true);
    }

    /** The member's dashboard, held for the rest of the transaction. */
    private function lock(string $workspaceId, string $membershipId, string $dashboardId): \stdClass
    {
        $dashboard = Str::isUuid($dashboardId)
            ? DB::selectOne(
                'select id, revision from dashboards where workspace_id = ? and membership_id = ? and id = ? for update',
                [$workspaceId, $membershipId, strtolower($dashboardId)],
            )
            : null;

        return $dashboard ?? throw new DashboardNotFound;
    }

    private function changeOf(string $workspaceId, string $membershipId, string $dashboardId, string $itemId, bool $changed, int $revision): ItemChange
    {
        $view = $this->find($workspaceId, $membershipId, $dashboardId);
        $item = null;

        foreach ($view->items as $candidate) {
            if ($candidate['item_id'] === $itemId) {
                $item = $candidate;
            }
        }

        return new ItemChange($revision, $changed, $item, $view->nextFreeSlot);
    }

    public function moveResizeItems(string $workspaceId, string $membershipId, string $dashboardId, array $items, int $expectedRevision): DashboardView
    {
        $dashboard = $this->lock($workspaceId, $membershipId, $dashboardId);
        $id = strtolower((string) $dashboard->id);
        $revision = (int) $dashboard->revision;

        if ($revision !== $expectedRevision) {
            throw new RevisionConflict($revision);
        }

        /** @var list<\stdClass> $rows */
        $rows = DB::select('select id, block_id, col, row, width, height from dashboard_items where workspace_id = ? and dashboard_id = ? for update', [$workspaceId, $id]);
        $known = [];

        foreach ($rows as $row) {
            $known[strtolower((string) $row->id)] = $row;
        }

        $blockIds = array_map(fn (\stdClass $r): string => strtolower((string) $r->block_id), $rows);
        $cards = $this->cards->forBlocks($workspaceId, $blockIds);
        $moved = [];
        $others = [];

        foreach ($items as $request) {
            $itemId = strtolower($request['item_id']);

            if (! isset($known[$itemId]) || isset($moved[$itemId])) {
                throw new InvalidLayout;
            }

            $row = $known[$itemId];
            $card = $cards[strtolower((string) $row->block_id)] ?? null;
            $limits = $card === null ? ['min_width' => 3, 'max_width' => 12, 'min_height' => 'small', 'max_height' => 'large'] : $card->sizes;
            $resizable = $card === null || $card->chrome['allow_resize'];
            $width = $resizable ? $request['width'] : (int) $row->width;
            $height = $resizable ? $request['height'] : (string) $row->height;

            $moved[$itemId] = Grid::clamp($request['col'], $request['row'], $width, $height, $limits['min_width'], $limits['max_width'], $limits['min_height'], $limits['max_height']);
        }

        foreach ($known as $itemId => $row) {
            if (! isset($moved[$itemId])) {
                $others[$itemId] = ['col' => (int) $row->col, 'row' => (int) $row->row, 'width' => (int) $row->width, 'height' => (string) $row->height];
            }
        }

        $settled = Grid::resolve($moved, $others);

        foreach ($settled as $itemId => $geometry) {
            $row = $known[$itemId];

            if ((int) $row->col !== $geometry['col'] || (int) $row->row !== $geometry['row'] || (int) $row->width !== $geometry['width'] || (string) $row->height !== $geometry['height']) {
                DB::update(
                    'update dashboard_items set col = ?, row = ?, width = ?, height = ?, updated_at = now() where workspace_id = ? and dashboard_id = ? and id = ?',
                    [$geometry['col'], $geometry['row'], $geometry['width'], $geometry['height'], $workspaceId, $id, $itemId],
                );
            }
        }

        DB::update('update dashboards set revision = revision + 1, updated_at = now() where workspace_id = ? and id = ?', [$workspaceId, $id]);

        $this->outbox->emit(AuditAction::DashboardsDashboardChanged, 'dashboard:'.$id, [
            'dashboard_id' => $id, 'command' => 'move_resize_items', 'revision' => $revision + 1, 'items' => count($moved),
        ]);

        return $this->find($workspaceId, $membershipId, $id);
    }
}
