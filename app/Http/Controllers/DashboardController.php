<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesMembership;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Dashboards\Contracts\BlockNotAvailable;
use App\Modules\Dashboards\Contracts\DashboardNotFound;
use App\Modules\Dashboards\Contracts\Dashboards;
use App\Modules\Dashboards\Contracts\DashboardSummary;
use App\Modules\Dashboards\Contracts\DashboardView;
use App\Modules\Dashboards\Contracts\ErrorCode;
use App\Modules\Dashboards\Contracts\InvalidDashboardName;
use App\Modules\Dashboards\Contracts\InvalidLayout;
use App\Modules\Dashboards\Contracts\ItemLocked;
use App\Modules\Dashboards\Contracts\ItemNotFound;
use App\Modules\Dashboards\Contracts\OverviewUndeletable;
use App\Modules\Dashboards\Contracts\RevisionConflict;
use App\Modules\Results\Contracts\RefreshOutcome;
use App\Modules\Results\Contracts\RowsPage;
use App\Platform\Period\InvalidPeriodInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A member's dashboards (Story 4.4, AD-20). The Inertia page carries the shell and `{dashboard_id, revision}` only; the layout, item states and Block
 * cards come from `GET /api/v1/dashboards/{id}`. Another member's dashboard and another Workspace's are both a 404 that says nothing else.
 */
final class DashboardController extends Controller
{
    use ResolvesMembership;

    public function __construct(private readonly Dashboards $dashboards, private readonly MembershipLookup $memberships) {}

    /** The User Overview: created for the member on first use. */
    public function overview(Request $request): Response
    {
        $membership = $this->activeMembership($request, $this->memberships);

        // A signed-in person with no active membership has no dashboard to open: the shell still renders, with the empty state.
        if ($membership === null) {
            return Inertia::render('Dashboard', ['dashboard_id' => null, 'revision' => 0]);
        }

        $ref = $this->dashboards->overview(...$membership);

        return Inertia::render('Dashboard', ['dashboard_id' => $ref->dashboardId, 'revision' => $ref->revision]);
    }

    /** A member's own dashboard opened by address (Story 4.16): the same page as the Overview, for another dashboard of theirs. */
    public function open(Request $request, string $dashboard): Response
    {
        [$workspaceId, $membershipId] = $this->membership($request, $this->memberships);

        try {
            $view = $this->dashboards->find($workspaceId, $membershipId, $dashboard);
        } catch (DashboardNotFound) {
            abort(404);
        }

        return Inertia::render('Dashboard', ['dashboard_id' => $view->dashboardId, 'revision' => $view->revision]);
    }

    /** `GET /api/v1/dashboards` (Story 4.16): the member's dashboards, Overview first. */
    public function index(Request $request): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->membership($request, $this->memberships);

        return response()->json(
            ['data' => array_map(self::summary(...), $this->dashboards->list($workspaceId, $membershipId))],
            200,
            ['Cache-Control' => 'no-store, private'],
        );
    }

    /** `CreateDashboard` (Story 4.16, AD-14): `{name}`. An empty or over-long name is a 422 with a field error. */
    public function create(Request $request): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->membership($request, $this->memberships);

        try {
            $summary = $this->dashboards->create($workspaceId, $membershipId, self::nameOf($request));
        } catch (InvalidDashboardName) {
            return $this->invalidName($request);
        }

        return response()->json(['data' => self::summary($summary)], 201);
    }

    /** `RenameDashboard` (Story 4.16): `{name}`. */
    public function rename(Request $request, string $dashboard): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->membership($request, $this->memberships);

        try {
            $summary = $this->dashboards->rename($workspaceId, $membershipId, $dashboard, self::nameOf($request));
        } catch (DashboardNotFound) {
            abort(404);
        } catch (InvalidDashboardName) {
            return $this->invalidName($request);
        }

        return response()->json(['data' => self::summary($summary)]);
    }

    /** `DuplicateDashboard` (Story 4.16): the new dashboard and how many items were left out. */
    public function duplicate(Request $request, string $dashboard): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->membership($request, $this->memberships);

        try {
            $outcome = $this->dashboards->duplicate($workspaceId, $membershipId, $dashboard);
        } catch (DashboardNotFound) {
            abort(404);
        }

        return response()->json(['data' => self::summary($outcome->dashboard) + ['omitted' => $outcome->omitted]], 201);
    }

    /** `DeleteDashboard` (Story 4.16): an Overview is a 422 `dashboards.overview_undeletable`. */
    public function destroy(Request $request, string $dashboard): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->membership($request, $this->memberships);

        try {
            $this->dashboards->delete($workspaceId, $membershipId, $dashboard);
        } catch (DashboardNotFound) {
            abort(404);
        } catch (OverviewUndeletable) {
            return $this->error($request, ErrorCode::OverviewUndeletable->value, 422, 'The Overview cannot be deleted.', []);
        }

        return response()->json(['data' => ['deleted' => true]]);
    }

    private static function nameOf(Request $request): string
    {
        $name = $request->input('name');

        return is_string($name) ? $name : '';
    }

    /** The 422 of a name that is empty, over-long or holds a control character, with the field error the form shows under the name. */
    private function invalidName(Request $request): JsonResponse
    {
        return $this->error($request, ErrorCode::InvalidName->value, 422, 'Enter a name of 1 to 120 characters.', ['fields' => ['name' => ['msg:dashboard-name-invalid']]]);
    }

    /** @return array{dashboard_id: string, name: string, is_overview: bool, item_count: int, updated_at: string} */
    private static function summary(DashboardSummary $s): array
    {
        return ['dashboard_id' => $s->dashboardId, 'name' => $s->name, 'is_overview' => $s->isOverview, 'item_count' => $s->itemCount, 'updated_at' => $s->updatedAt];
    }

    /**
     * The current results of the dashboard's Blocks (Story 4.5): what is stored, immediately. `?range=<preset>` is the dashboard Date Range as a
     * preset token; a token the Blocks do not offer is a 422 and a date is never accepted.
     */
    public function results(Request $request, string $dashboard): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->membership($request, $this->memberships);
        $range = $request->query('range');
        $only = $request->query('items');

        if ($range !== null && ! is_string($range)) {
            abort(422, 'Choose one of the periods this block offers.');
        }

        if ($only !== null && (! is_array($only) || count($only) > 200 || array_filter($only, fn ($id): bool => ! is_string($id) || ! Str::isUuid($id)) !== [])) {
            abort(422, 'Name items by their id.');
        }

        try {
            $items = $this->dashboards->results($workspaceId, $membershipId, $dashboard, $range === '' ? null : $range, $only === null ? null : array_values(array_map('strtolower', $only)));
        } catch (DashboardNotFound) {
            abort(404);
        }

        return response()->json(['data' => ['items' => $items]], 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * `AddItem` (Story 4.9, AD-14): `{block_id, expected_revision}`. 201 with the item when it was placed, 200 with the item that was already there
     * (idempotent). A Block the member cannot see is a 404, one with no published version a 422, a stale revision a 409; nothing is placed then.
     */
    public function addBlock(Request $request, string $dashboard): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->membership($request, $this->memberships);
        $data = $request->validate([
            'block_id' => ['required', 'string', 'uuid'],
            'expected_revision' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $change = $this->dashboards->addItem($workspaceId, $membershipId, $dashboard, $data['block_id'], (int) $data['expected_revision']);
        } catch (DashboardNotFound) {
            abort(404);
        } catch (BlockNotAvailable $e) {
            if ($e->reason === BlockNotAvailable::NOT_FOUND) {
                abort(404);
            }

            return $this->error($request, ErrorCode::BlockUnavailable->value, 422, 'This block is no longer available.', []);
        } catch (RevisionConflict $e) {
            return $this->error($request, ErrorCode::RevisionConflict->value, 409, 'The dashboard has changed.', ['revision' => $e->currentRevision]);
        }

        return response()->json(
            ['data' => ['item' => $change->item, 'revision' => $change->revision, 'next_free_slot' => $change->nextFreeSlot]],
            $change->changed ? 201 : 200,
            ['Cache-Control' => 'no-store, private'],
        );
    }

    /**
     * `MoveResizeItems` (Story 4.11, AD-14): `{items: [{item_id, col, row, width, height}], expected_revision}`. The geometry is clamped and the layout
     * settled by the Layout kernel on the server; the saved layout comes back. An item that is not on the dashboard is a 422 and a stale revision a 409.
     */
    public function layout(Request $request, string $dashboard): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->membership($request, $this->memberships);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.item_id' => ['required', 'string', 'uuid', 'distinct:strict'],
            'items.*.col' => ['required', 'integer', 'min:0', 'max:11'],
            'items.*.row' => ['required', 'integer', 'min:0', 'max:100000'],
            'items.*.width' => ['required', 'integer', 'in:3,4,6,8,12'],
            'items.*.height' => ['required', 'string', 'in:small,medium,large'],
        ]);

        $items = array_map(fn (array $i): array => [
            'item_id' => (string) $i['item_id'], 'col' => (int) $i['col'], 'row' => (int) $i['row'], 'width' => (int) $i['width'], 'height' => (string) $i['height'],
        ], array_values($data['items']));

        try {
            $view = $this->dashboards->moveResizeItems($workspaceId, $membershipId, $dashboard, $items, (int) $data['expected_revision']);
        } catch (DashboardNotFound) {
            abort(404);
        } catch (InvalidLayout) {
            return $this->error($request, ErrorCode::InvalidLayout->value, 422, 'The layout names a block that is not on this dashboard.', []);
        } catch (RevisionConflict $e) {
            return $this->error($request, ErrorCode::RevisionConflict->value, 409, 'The dashboard has changed.', ['revision' => $e->currentRevision]);
        }

        return response()->json(['data' => [
            'dashboard_id' => $view->dashboardId,
            'name' => $view->name,
            'is_overview' => $view->isOverview,
            'revision' => $view->revision,
            'items' => $view->items,
            'next_free_slot' => $view->nextFreeSlot,
            'compact_order' => $view->compactOrder,
        ]], 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * `SetDateRange` (Story 4.15, AD-14): `{range, from?, to?, expected_revision}`. `range` is a preset token, `custom` with `from` and `to` (the only
     * dates this accepts) or null for each Block's own default. The saved dashboard comes back; a refused range is a 422 and nothing is stored.
     */
    public function dateRange(Request $request, string $dashboard): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->membership($request, $this->memberships);
        $data = $request->validate([
            'range' => ['present', 'nullable', 'string', 'max:40'],
            'from' => ['nullable', 'string', 'max:10'],
            'to' => ['nullable', 'string', 'max:10'],
            'expected_revision' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $view = $this->dashboards->setDateRange($workspaceId, $membershipId, $dashboard, $data['range'] ?? null, $data['from'] ?? null, $data['to'] ?? null, (int) $data['expected_revision']);
        } catch (DashboardNotFound) {
            abort(404);
        } catch (InvalidPeriodInput) {
            return $this->error($request, ErrorCode::InvalidPeriod->value, 422, 'Choose one of the periods this dashboard offers.', []);
        } catch (RevisionConflict $e) {
            return $this->error($request, ErrorCode::RevisionConflict->value, 409, 'The dashboard has changed.', ['revision' => $e->currentRevision]);
        }

        return $this->saved($view);
    }

    /**
     * `SetPeriodSelection` (Story 4.15, AD-14): `{selection, expected_revision}` for one Own-selector Block. `selection` is a preset token or null to
     * go back to the Block's default; any other Block, or a token that is not a preset, is a 422.
     */
    public function periodSelection(Request $request, string $dashboard, string $block): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->membership($request, $this->memberships);
        $data = $request->validate([
            'selection' => ['present', 'nullable', 'string', 'max:40'],
            'expected_revision' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $view = $this->dashboards->setPeriodSelection($workspaceId, $membershipId, $dashboard, $block, $data['selection'] ?? null, (int) $data['expected_revision']);
        } catch (DashboardNotFound|ItemNotFound) {
            abort(404);
        } catch (InvalidPeriodInput) {
            return $this->error($request, ErrorCode::InvalidPeriod->value, 422, 'Choose one of the periods this block offers.', []);
        } catch (RevisionConflict $e) {
            return $this->error($request, ErrorCode::RevisionConflict->value, 409, 'The dashboard has changed.', ['revision' => $e->currentRevision]);
        }

        return $this->saved($view);
    }

    private function saved(DashboardView $view): JsonResponse
    {
        return response()->json(['data' => [
            'dashboard_id' => $view->dashboardId,
            'revision' => $view->revision,
            'date_range' => $view->dateRange,
            'items' => $view->items,
        ]], 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * `SetCompactOrder` (Story 4.12, AD-14): `{order: [item_id], expected_revision}`. Stores the stacking order of a single-column layout; the saved
     * desktop positions never change. The order must name exactly the dashboard's items.
     */
    public function compactOrder(Request $request, string $dashboard): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->membership($request, $this->memberships);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'order' => ['required', 'array', 'min:1', 'max:200'],
            'order.*' => ['required', 'string', 'uuid', 'distinct:strict'],
        ]);

        try {
            $view = $this->dashboards->setCompactOrder($workspaceId, $membershipId, $dashboard, array_values(array_map('strval', $data['order'])), (int) $data['expected_revision']);
        } catch (DashboardNotFound) {
            abort(404);
        } catch (InvalidLayout) {
            return $this->error($request, ErrorCode::InvalidLayout->value, 422, 'The order must name every block on this dashboard once.', []);
        } catch (RevisionConflict $e) {
            return $this->error($request, ErrorCode::RevisionConflict->value, 409, 'The dashboard has changed.', ['revision' => $e->currentRevision]);
        }

        return response()->json(['data' => [
            'dashboard_id' => $view->dashboardId,
            'name' => $view->name,
            'is_overview' => $view->isOverview,
            'revision' => $view->revision,
            'items' => $view->items,
            'next_free_slot' => $view->nextFreeSlot,
            'compact_order' => $view->compactOrder,
        ]], 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * `RestoreItem` (Story 4.10, AD-14): `{geometry?: {col, row, width, height}, minimized?, expected_revision}`. The same answers as `AddItem`; the item
     * goes back to its remembered spot when free, else the first free one.
     */
    public function restoreBlock(Request $request, string $dashboard, string $block): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->membership($request, $this->memberships);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'minimized' => ['sometimes', 'boolean'],
            'geometry' => ['sometimes', 'nullable', 'array'],
            'geometry.col' => ['required_with:geometry', 'integer', 'min:0', 'max:11'],
            'geometry.row' => ['required_with:geometry', 'integer', 'min:0', 'max:100000'],
            'geometry.width' => ['required_with:geometry', 'integer', 'in:3,4,6,8,12'],
            'geometry.height' => ['required_with:geometry', 'string', 'in:small,medium,large'],
        ]);

        $geometry = is_array($data['geometry'] ?? null)
            ? ['col' => (int) $data['geometry']['col'], 'row' => (int) $data['geometry']['row'], 'width' => (int) $data['geometry']['width'], 'height' => (string) $data['geometry']['height']]
            : null;

        try {
            $change = $this->dashboards->restoreItem($workspaceId, $membershipId, $dashboard, $block, $geometry, (bool) ($data['minimized'] ?? false), (int) $data['expected_revision']);
        } catch (DashboardNotFound) {
            abort(404);
        } catch (BlockNotAvailable $e) {
            if ($e->reason === BlockNotAvailable::NOT_FOUND) {
                abort(404);
            }

            return $this->error($request, ErrorCode::BlockUnavailable->value, 422, 'This block is no longer available.', []);
        } catch (RevisionConflict $e) {
            return $this->error($request, ErrorCode::RevisionConflict->value, 409, 'The dashboard has changed.', ['revision' => $e->currentRevision]);
        }

        return response()->json(
            ['data' => ['item' => $change->item, 'revision' => $change->revision, 'next_free_slot' => $change->nextFreeSlot]],
            $change->changed ? 201 : 200,
            ['Cache-Control' => 'no-store, private'],
        );
    }

    /**
     * `RemoveItem` (Story 4.9, AD-14): by Block, `{expected_revision}`. A Mandatory item is a 403, a stale revision a 409, and a Block that is not on
     * the dashboard a no-op 200.
     */
    public function removeBlock(Request $request, string $dashboard, string $block): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->membership($request, $this->memberships);
        $data = $request->validate(['expected_revision' => ['required', 'integer', 'min:0']]);

        try {
            $change = $this->dashboards->removeItem($workspaceId, $membershipId, $dashboard, $block, (int) $data['expected_revision']);
        } catch (DashboardNotFound) {
            abort(404);
        } catch (ItemLocked) {
            return $this->error($request, ErrorCode::ItemLocked->value, 403, 'Required by your admin.', []);
        } catch (RevisionConflict $e) {
            return $this->error($request, ErrorCode::RevisionConflict->value, 409, 'The dashboard has changed.', ['revision' => $e->currentRevision]);
        }

        return response()->json(['data' => ['block_id' => strtolower($block), 'removed' => $change->changed, 'revision' => $change->revision]], 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * `SetMinimized` (Story 4.6, AD-14): `{minimized, expected_revision}`. A stale revision is a 409 that carries the current one, so the client
     * reloads the layout and retries once.
     */
    public function minimize(Request $request, string $dashboard, string $item): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->membership($request, $this->memberships);
        $data = $request->validate([
            'minimized' => ['required', 'boolean'],
            'expected_revision' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $revision = $this->dashboards->setMinimized($workspaceId, $membershipId, $dashboard, $item, (bool) $data['minimized'], (int) $data['expected_revision']);
        } catch (DashboardNotFound|ItemNotFound) {
            abort(404);
        } catch (RevisionConflict $e) {
            return $this->error($request, ErrorCode::RevisionConflict->value, 409, 'The dashboard has changed.', ['revision' => $e->currentRevision]);
        }

        return response()->json(['data' => ['item_id' => strtolower($item), 'minimized' => (bool) $data['minimized'], 'revision' => $revision]], 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * A manual refresh of one Block (Story 4.6). Inside the manual-refresh window it is a 429 with `Retry-After` and nothing is requested; the
     * User's own Block is the only one touched, and no source is called here.
     */
    public function refresh(Request $request, string $dashboard, string $item): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->membership($request, $this->memberships);

        try {
            $outcome = $this->dashboards->refresh($workspaceId, $membershipId, $dashboard, $item);
        } catch (DashboardNotFound|ItemNotFound) {
            abort(404);
        }

        if ($outcome->status === RefreshOutcome::LIMITED) {
            return $this->error($request, ErrorCode::RefreshLimited->value, 429, 'Refreshed a moment ago.', ['retry_after' => $outcome->retryAfter])
                ->header('Retry-After', (string) $outcome->retryAfter);
        }

        return response()->json(['data' => ['item_id' => strtolower($item), 'status' => $outcome->status]], 202, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * The rows behind an item's Block (Story 5.9), server-paginated: `?page=2&page_size=25`. Only mapped fields, measures as decimal strings; access to
     * the published Block is judged on every request and no source API is called. A set that left the cache is a retryable 503 (a compute is queued).
     */
    public function rows(Request $request, string $dashboard, string $item): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->membership($request, $this->memberships);
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $outcome = $this->dashboards->rows($workspaceId, $membershipId, $dashboard, $item, (int) ($data['page'] ?? 1), (int) ($data['page_size'] ?? 25));
        } catch (DashboardNotFound|ItemNotFound) {
            abort(404);
        }

        $page = $outcome['page'];

        if ($page === null) {
            return $this->error($request, ErrorCode::RowsUnavailable->value, 403, 'This block is no longer available to you.', ['reason' => $outcome['status']]);
        }

        if ($page->status === RowsPage::EXPIRED) {
            return $this->error($request, ErrorCode::RowsetExpired->value, 503, 'The rows are being prepared. Try again.', ['retry_after' => 2])
                ->header('Retry-After', '2');
        }

        return response()->json(['data' => [
            'status' => $page->status,
            'columns' => $page->columns,
            'rows' => $page->rows,
            'total' => $page->total,
            'page' => $page->page,
            'page_size' => $page->pageSize,
            'pages' => $page->pages,
            'clamped' => $page->clamped,
            'data_as_of' => $page->dataAsOf,
            'result_etag' => $page->resultEtag,
        ]], 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function error(Request $request, string $code, int $status, string $message, array $extra): JsonResponse
    {
        return response()->json(['error' => [
            'code' => $code,
            'message' => $message,
            'request_id' => $request->attributes->get('request_id'),
        ] + $extra], $status);
    }

    public function show(Request $request, string $dashboard): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->membership($request, $this->memberships);

        try {
            $view = $this->dashboards->find($workspaceId, $membershipId, $dashboard);
        } catch (DashboardNotFound) {
            abort(404);
        }

        return response()->json(['data' => [
            'dashboard_id' => $view->dashboardId,
            'name' => $view->name,
            'is_overview' => $view->isOverview,
            'revision' => $view->revision,
            'items' => $view->items,
            'next_free_slot' => $view->nextFreeSlot,
            'compact_order' => $view->compactOrder,
            'date_range' => $view->dateRange,
            'live' => $this->live(),
        ]], 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * How the browser stays up to date (Story 4.13): the public realtime connection details (the Reverb app key and where the socket is) and the
     * polling interval used when the socket is not connected. Null parts mean "not available": the page then polls or waits.
     *
     * @return array{poll_interval_seconds: int|null, realtime: array{key: string, host: string, port: int, scheme: string}|null}
     */
    private function live(): array
    {
        $poll = config('dashflow.live.poll_interval.value');
        $interval = is_int($poll) ? $poll : (is_string($poll) && preg_match('/\A[1-9][0-9]{0,5}\z/D', trim($poll)) === 1 ? (int) trim($poll) : null);
        $connection = (array) config('broadcasting.connections.reverb', []);
        $key = $connection['key'] ?? null;
        $host = $connection['options']['host'] ?? null;
        $enabled = config('broadcasting.default') === 'reverb' && is_string($key) && $key !== '' && is_string($host) && $host !== '';

        return [
            'poll_interval_seconds' => $interval,
            'realtime' => $enabled ? [
                'key' => $key,
                'host' => $host,
                'port' => (int) ($connection['options']['port'] ?? 443),
                'scheme' => (string) ($connection['options']['scheme'] ?? 'https'),
            ] : null,
        ];
    }
}
