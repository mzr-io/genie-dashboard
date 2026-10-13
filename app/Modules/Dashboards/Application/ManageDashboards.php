<?php

namespace App\Modules\Dashboards\Application;

use App\Modules\Access\Contracts\RegisterAccessSubject;
use App\Modules\Access\Contracts\SubjectAccess;
use App\Modules\Blocks\Contracts\BlockCards;
use App\Modules\Dashboards\Contracts\BlockNotAvailable;
use App\Modules\Dashboards\Contracts\DashboardNotFound;
use App\Modules\Dashboards\Contracts\DashboardRef;
use App\Modules\Dashboards\Contracts\Dashboards;
use App\Modules\Dashboards\Contracts\DashboardSummary;
use App\Modules\Dashboards\Contracts\DashboardView;
use App\Modules\Dashboards\Contracts\DuplicateOutcome;
use App\Modules\Dashboards\Contracts\InvalidDashboardName;
use App\Modules\Dashboards\Contracts\InvalidLayout;
use App\Modules\Dashboards\Contracts\ItemChange;
use App\Modules\Dashboards\Contracts\ItemLocked;
use App\Modules\Dashboards\Contracts\ItemNotFound;
use App\Modules\Dashboards\Contracts\OverviewUndeletable;
use App\Modules\Dashboards\Contracts\RevisionConflict;
use App\Modules\Results\Contracts\ManualRefresh;
use App\Modules\Results\Contracts\RefreshOutcome;
use App\Modules\Results\Contracts\ResultState;
use App\Modules\Results\Contracts\ViewedResults;
use App\Modules\Results\Contracts\ViewRequest;
use App\Platform\Audit\AuditAction;
use App\Platform\Layout\Grid;
use App\Platform\Outbox\Outbox;
use App\Platform\Period\InvalidPeriodInput;
use App\Platform\Period\PeriodBehaviour;
use App\Platform\Period\PeriodPreset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * {@see Dashboards}. The layout read gives each item its geometry clamped to the Block's own limits, its state (`unpublished` when the Block has no
 * published current version, `access_removed` when the member may no longer see it: access is re-checked on every read) and, for an item that can be
 * shown, the User-safe Block card. An item that cannot be shown carries no name and no data. `next_free_slot` is where a default-sized Block would go.
 */
final class ManageDashboards implements Dashboards
{
    private const COPY_SUFFIX = ' (copy)';

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

    public function list(string $workspaceId, string $membershipId): array
    {
        $this->overview($workspaceId, $membershipId);

        /** @var list<\stdClass> $rows */
        $rows = DB::select(
            'select d.id, d.name, d.is_overview, '
            ."to_char(d.updated_at at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') as updated_at, "
            .'(select count(*) from dashboard_items i where i.workspace_id = d.workspace_id and i.dashboard_id = d.id) as item_count '
            .'from dashboards d where d.workspace_id = ? and d.membership_id = ? order by d.is_overview desc, d.created_at, d.id',
            [$workspaceId, $membershipId],
        );

        return array_map(fn (\stdClass $r): DashboardSummary => $this->summary($r), $rows);
    }

    public function create(string $workspaceId, string $membershipId, string $name): DashboardSummary
    {
        $name = self::validName($name);
        $id = (string) Str::uuid7();

        DB::insert(
            'insert into dashboards (id, workspace_id, membership_id, name, is_overview, revision, created_at, updated_at) values (?, ?, ?, ?, false, 0, now(), now())',
            [$id, $workspaceId, $membershipId, $name],
        );

        $this->outbox->emit(AuditAction::DashboardsDashboardChanged, 'dashboard:'.$id, [
            'dashboard_id' => $id, 'membership_id' => strtolower($membershipId), 'command' => 'create', 'revision' => 0,
        ]);

        return $this->summaryOf($workspaceId, $membershipId, $id);
    }

    public function rename(string $workspaceId, string $membershipId, string $dashboardId, string $name): DashboardSummary
    {
        $dashboard = $this->lock($workspaceId, $membershipId, $dashboardId);
        $name = self::validName($name);
        $id = strtolower((string) $dashboard->id);

        DB::update('update dashboards set name = ?, updated_at = now() where workspace_id = ? and id = ?', [$name, $workspaceId, $id]);

        $this->outbox->emit(AuditAction::DashboardsDashboardChanged, 'dashboard:'.$id, [
            'dashboard_id' => $id, 'membership_id' => strtolower($membershipId), 'command' => 'rename', 'revision' => (int) $dashboard->revision,
        ]);

        return $this->summaryOf($workspaceId, $membershipId, $id);
    }

    public function duplicate(string $workspaceId, string $membershipId, string $dashboardId): DuplicateOutcome
    {
        $source = $this->lock($workspaceId, $membershipId, $dashboardId);
        $sourceId = strtolower((string) $source->id);

        $head = DB::selectOne('select name, compact_order::text as compact_order from dashboards where workspace_id = ? and id = ?', [$workspaceId, $sourceId]);

        /** @var list<\stdClass> $rows */
        $rows = DB::select(
            'select id, block_id, col, row, width, height, minimized, mandatory, period_selection from dashboard_items where workspace_id = ? and dashboard_id = ? order by row, col, id',
            [$workspaceId, $sourceId],
        );
        $blockIds = array_map(fn (\stdClass $r): string => strtolower((string) $r->block_id), $rows);
        $cards = $this->cards->forBlocks($workspaceId, $blockIds);
        $visible = $this->access->canView($workspaceId, $membershipId, RegisterAccessSubject::BLOCK, $blockIds);

        $newId = (string) Str::uuid7();
        $name = mb_substr((string) $head->name, 0, 120 - mb_strlen(self::COPY_SUFFIX)).self::COPY_SUFFIX;

        DB::insert(
            'insert into dashboards (id, workspace_id, membership_id, name, is_overview, revision, date_range, range_from, range_to, created_at, updated_at) '
            .'select ?, workspace_id, membership_id, ?, false, 0, date_range, range_from, range_to, now(), now() from dashboards where workspace_id = ? and id = ?',
            [$newId, $name, $workspaceId, $sourceId],
        );

        $map = [];
        $omitted = 0;

        foreach ($rows as $row) {
            $blockId = strtolower((string) $row->block_id);

            if (! ($visible[$blockId] ?? false) || ! isset($cards[$blockId])) {
                $omitted++;

                continue;
            }

            $itemId = (string) Str::uuid7();
            $map[strtolower((string) $row->id)] = $itemId;

            DB::insert(
                'insert into dashboard_items (id, workspace_id, dashboard_id, block_id, col, row, width, height, minimized, mandatory, period_selection, created_at, updated_at) '
                .'values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now(), now())',
                [$itemId, $workspaceId, $newId, $blockId, (int) $row->col, (int) $row->row, (int) $row->width, (string) $row->height, (bool) $row->minimized, (bool) $row->mandatory, $row->period_selection],
            );

            $this->outbox->emit(AuditAction::DashboardsDashboardChanged, 'dashboard:'.$newId, [
                'dashboard_id' => $newId, 'item_id' => $itemId, 'block_id' => $blockId, 'membership_id' => strtolower($membershipId), 'command' => 'add_item', 'revision' => 0,
            ]);
        }

        $stored = is_string($head->compact_order) ? json_decode($head->compact_order, true) : null;

        if (is_array($stored)) {
            $order = [];

            foreach ($stored as $old) {
                $key = strtolower((string) $old);

                if (isset($map[$key])) {
                    $order[] = $map[$key];
                }
            }

            DB::update('update dashboards set compact_order = ?::jsonb where workspace_id = ? and id = ?', [json_encode($order), $workspaceId, $newId]);
        }

        $this->outbox->emit(AuditAction::DashboardsDashboardChanged, 'dashboard:'.$newId, [
            'dashboard_id' => $newId, 'source_dashboard_id' => $sourceId, 'membership_id' => strtolower($membershipId), 'command' => 'duplicate', 'revision' => 0,
        ]);

        return new DuplicateOutcome($this->summaryOf($workspaceId, $membershipId, $newId), $omitted);
    }

    public function delete(string $workspaceId, string $membershipId, string $dashboardId): void
    {
        $this->lock($workspaceId, $membershipId, $dashboardId);
        $id = strtolower($dashboardId);

        $row = DB::selectOne('select is_overview from dashboards where workspace_id = ? and id = ?', [$workspaceId, $id]);

        if ((bool) $row->is_overview) {
            throw new OverviewUndeletable;
        }

        /** @var list<\stdClass> $items */
        $items = DB::select('select id, block_id from dashboard_items where workspace_id = ? and dashboard_id = ?', [$workspaceId, $id]);

        if (! (bool) DB::selectOne('select dashboards_delete(?::uuid, ?::uuid) as removed', [$id, $membershipId])->removed) {
            throw new DashboardNotFound;
        }

        foreach ($items as $item) {
            $this->outbox->emit(AuditAction::DashboardsDashboardChanged, 'dashboard:'.$id, [
                'dashboard_id' => $id, 'item_id' => strtolower((string) $item->id), 'block_id' => strtolower((string) $item->block_id),
                'membership_id' => strtolower($membershipId), 'command' => 'remove_item', 'revision' => 0,
            ]);
        }

        $this->outbox->emit(AuditAction::DashboardsDashboardChanged, 'dashboard:'.$id, [
            'dashboard_id' => $id, 'membership_id' => strtolower($membershipId), 'command' => 'delete', 'revision' => 0,
        ]);
    }

    private function summaryOf(string $workspaceId, string $membershipId, string $dashboardId): DashboardSummary
    {
        foreach ($this->list($workspaceId, $membershipId) as $summary) {
            if ($summary->dashboardId === $dashboardId) {
                return $summary;
            }
        }

        throw new DashboardNotFound;
    }

    private function summary(\stdClass $r): DashboardSummary
    {
        return new DashboardSummary(strtolower((string) $r->id), (string) $r->name, (bool) $r->is_overview, (int) $r->item_count, (string) $r->updated_at);
    }

    /** A name as stored: trimmed, 1 to 120 characters, with no control or format characters. */
    private static function validName(string $name): string
    {
        $name = trim($name);

        if ($name === '' || ! mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 120 || preg_match('/[\p{Cc}\p{Cf}]/u', $name) !== 0) {
            throw new InvalidDashboardName;
        }

        return $name;
    }

    public function find(string $workspaceId, string $membershipId, string $dashboardId): DashboardView
    {
        $dashboard = Str::isUuid($dashboardId)
            ? DB::selectOne(
                'select id, name, is_overview, revision, compact_order::text as compact_order, date_range, range_from::text as range_from, range_to::text as range_to from dashboards where workspace_id = ? and membership_id = ? and id = ?',
                [$workspaceId, $membershipId, strtolower($dashboardId)],
            )
            : null;

        if ($dashboard === null) {
            throw new DashboardNotFound;
        }

        /** @var list<\stdClass> $rows */
        $rows = DB::select(
            'select id, block_id, col, row, width, height, minimized, period_selection from dashboard_items where workspace_id = ? and dashboard_id = ? order by row, col, id',
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
                'period' => $state === 'ok' ? [
                    'behaviour' => PeriodBehaviour::of($card->about['period'] ?? null)->value,
                    'selection' => $row->period_selection === null ? null : (string) $row->period_selection,
                    'default' => $card->defaultPeriod,
                ] : null,
                'block' => $state === 'ok' ? [
                    'name' => $card->name,
                    'subtitle' => $card->subtitle,
                    'type' => $card->type,
                    'icon' => $card->icon,
                    'chrome' => $card->chrome,
                    'about' => $card->about,
                    'sizes' => $card->sizes,
                    'footer' => $card->footer === null ? null : ['target' => $card->footer['target'], 'label' => $card->footer['label'], 'url' => $card->footer['url']],
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
            $this->compactOrderOf($dashboard->compact_order ?? null, array_column($items, 'item_id')),
            $dashboard->date_range === null ? null : ['range' => (string) $dashboard->date_range, 'from' => $dashboard->range_from, 'to' => $dashboard->range_to],
        );
    }

    public function results(string $workspaceId, string $membershipId, string $dashboardId, ?string $range, ?array $items = null): array
    {
        $dashboard = Str::isUuid($dashboardId)
            ? DB::selectOne('select id, date_range, range_from::text as range_from, range_to::text as range_to from dashboards where workspace_id = ? and membership_id = ? and id = ?', [$workspaceId, $membershipId, strtolower($dashboardId)])
            : null;

        if ($dashboard === null) {
            throw new DashboardNotFound;
        }

        // The stored Date Range is the dashboard's; a preset in the request is a one-off override (a preview) and is never stored.
        $range ??= $dashboard->date_range === null ? null : (string) $dashboard->date_range;
        $custom = $range === 'custom' && $dashboard->range_from !== null ? ['from' => (string) $dashboard->range_from, 'to' => (string) $dashboard->range_to] : null;

        /** @var list<\stdClass> $rows */
        $rows = DB::select('select id, block_id, period_selection from dashboard_items where workspace_id = ? and dashboard_id = ? order by row, col, id', [$workspaceId, strtolower((string) $dashboard->id)]);
        $blockIds = array_map(fn (\stdClass $r): string => strtolower((string) $r->block_id), $rows);
        $cards = $this->cards->forBlocks($workspaceId, $blockIds);
        $visible = $this->access->canView($workspaceId, $membershipId, RegisterAccessSubject::BLOCK, $blockIds);
        $out = [];

        foreach ($rows as $row) {
            if ($items !== null && ! in_array(strtolower((string) $row->id), $items, true)) {
                continue;
            }

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

            $own = PeriodBehaviour::hasSelector($card->about['period'] ?? null);
            $viewed = $this->results->view(new ViewRequest(
                $workspaceId,
                $membershipId,
                $card->versionId,
                $own && $row->period_selection !== null ? (string) $row->period_selection : null,
                $range,
                dashboardCustom: $custom,
            ));
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
        DB::update(
            'update dashboards set revision = revision + 1, '
            .'compact_order = case when compact_order is null then null else compact_order || to_jsonb(?::text) end, updated_at = now() where workspace_id = ? and id = ?',
            [$itemId, $workspaceId, $id],
        );

        $this->outbox->emit(AuditAction::DashboardsDashboardChanged, 'dashboard:'.$id, [
            'dashboard_id' => $id, 'item_id' => $itemId, 'block_id' => $blockId, 'membership_id' => strtolower($membershipId), 'command' => $command, 'revision' => $revision + 1,
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

        DB::update(
            'update dashboards set revision = revision + 1, '
            .'compact_order = case when compact_order is null then null else compact_order - ?::text end, updated_at = now() where workspace_id = ? and id = ?',
            [strtolower((string) $item->id), $workspaceId, $id],
        );

        $this->outbox->emit(AuditAction::DashboardsDashboardChanged, 'dashboard:'.$id, [
            'dashboard_id' => $id, 'item_id' => strtolower((string) $item->id), 'block_id' => $blockId, 'membership_id' => strtolower($membershipId), 'command' => 'remove_item', 'revision' => $revision + 1,
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

    public function setCompactOrder(string $workspaceId, string $membershipId, string $dashboardId, array $order, int $expectedRevision): DashboardView
    {
        $dashboard = $this->lock($workspaceId, $membershipId, $dashboardId);
        $id = strtolower((string) $dashboard->id);
        $revision = (int) $dashboard->revision;

        if ($revision !== $expectedRevision) {
            throw new RevisionConflict($revision);
        }

        /** @var list<\stdClass> $rows */
        $rows = DB::select('select id from dashboard_items where workspace_id = ? and dashboard_id = ?', [$workspaceId, $id]);
        $known = array_map(fn (\stdClass $r): string => strtolower((string) $r->id), $rows);
        $given = array_map(strtolower(...), $order);
        $sortedKnown = $known;
        $sortedGiven = $given;
        sort($sortedKnown);
        sort($sortedGiven);

        // Exactly the dashboard's items, each once: it orders them, it never adds or drops one.
        if ($sortedKnown !== $sortedGiven) {
            throw new InvalidLayout;
        }

        DB::update(
            'update dashboards set compact_order = ?::jsonb, revision = revision + 1, updated_at = now() where workspace_id = ? and id = ?',
            [json_encode($given, JSON_THROW_ON_ERROR), $workspaceId, $id],
        );

        $this->outbox->emit(AuditAction::DashboardsDashboardChanged, 'dashboard:'.$id, [
            'dashboard_id' => $id, 'command' => 'set_compact_order', 'revision' => $revision + 1, 'items' => count($given),
        ]);

        return $this->find($workspaceId, $membershipId, $id);
    }

    public function setDateRange(string $workspaceId, string $membershipId, string $dashboardId, ?string $range, ?string $from, ?string $to, int $expectedRevision): DashboardView
    {
        $dashboard = $this->lock($workspaceId, $membershipId, $dashboardId);
        $id = strtolower((string) $dashboard->id);
        $revision = (int) $dashboard->revision;

        if ($revision !== $expectedRevision) {
            throw new RevisionConflict($revision);
        }

        // A date is accepted only here, with `custom`; a preset takes none, and nothing is stored when the range is refused.
        if ($range === null) {
            if ($from !== null || $to !== null) {
                throw new InvalidPeriodInput;
            }
        } elseif ($range === 'custom') {
            $start = self::day($from);
            $last = self::day($to);

            if ($start === null || $last === null || $start > $last || $start->diff($last)->days > 3660) {
                throw new InvalidPeriodInput;
            }
        } elseif (PeriodPreset::tryFrom($range) === null || $from !== null || $to !== null) {
            throw new InvalidPeriodInput;
        }

        DB::update(
            'update dashboards set date_range = ?, range_from = ?, range_to = ?, revision = revision + 1, updated_at = now() where workspace_id = ? and id = ?',
            [$range, $range === 'custom' ? $from : null, $range === 'custom' ? $to : null, $workspaceId, $id],
        );

        $this->outbox->emit(AuditAction::DashboardsDashboardChanged, 'dashboard:'.$id, [
            'dashboard_id' => $id, 'command' => 'set_date_range', 'revision' => $revision + 1,
        ]);

        return $this->find($workspaceId, $membershipId, $id);
    }

    public function setPeriodSelection(string $workspaceId, string $membershipId, string $dashboardId, string $blockId, ?string $selection, int $expectedRevision): DashboardView
    {
        $dashboard = $this->lock($workspaceId, $membershipId, $dashboardId);
        $id = strtolower((string) $dashboard->id);
        $revision = (int) $dashboard->revision;

        if ($revision !== $expectedRevision) {
            throw new RevisionConflict($revision);
        }

        $item = Str::isUuid($blockId)
            ? DB::selectOne('select id, block_id from dashboard_items where workspace_id = ? and dashboard_id = ? and block_id = ? for update', [$workspaceId, $id, strtolower($blockId)])
            : null;

        if ($item === null) {
            throw new ItemNotFound;
        }

        $card = ($this->cards->forBlocks($workspaceId, [strtolower($blockId)]))[strtolower($blockId)] ?? null;

        // Only an Own-selector Block has a selector; any other ignores every period, so a selection for it is refused rather than stored.
        if ($card === null || ! PeriodBehaviour::hasSelector($card->about['period'] ?? null)) {
            throw new InvalidPeriodInput;
        }

        if ($selection !== null && PeriodPreset::tryFrom($selection) === null) {
            throw new InvalidPeriodInput;
        }

        DB::update('update dashboard_items set period_selection = ?, updated_at = now() where workspace_id = ? and id = ?', [$selection, $workspaceId, strtolower((string) $item->id)]);
        DB::update('update dashboards set revision = revision + 1, updated_at = now() where workspace_id = ? and id = ?', [$workspaceId, $id]);

        $this->outbox->emit(AuditAction::DashboardsDashboardChanged, 'dashboard:'.$id, [
            'dashboard_id' => $id, 'item_id' => strtolower((string) $item->id), 'block_id' => strtolower((string) $item->block_id), 'command' => 'set_period_selection', 'revision' => $revision + 1,
        ]);

        return $this->find($workspaceId, $membershipId, $id);
    }

    /** A real `Y-m-d` day, or null. */
    private static function day(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * The stored order with items that have since been added appended in reading order and items that are gone left out; null while nobody
     * reordered.
     *
     * @param  list<string>  $itemIds  the dashboard's items in reading order
     * @return list<string>|null
     */
    private function compactOrderOf(mixed $stored, array $itemIds): ?array
    {
        if (! is_string($stored)) {
            return null;
        }

        $decoded = json_decode($stored, true);

        if (! is_array($decoded)) {
            return null;
        }

        $order = array_values(array_filter(array_map(fn ($v): string => strtolower((string) $v), $decoded), fn (string $v): bool => in_array($v, $itemIds, true)));

        return array_values(array_unique([...$order, ...array_values(array_diff($itemIds, $order))]));
    }
}
