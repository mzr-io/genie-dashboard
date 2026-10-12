<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesMembership;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Dashboards\Contracts\BlockNotAvailable;
use App\Modules\Dashboards\Contracts\DashboardNotFound;
use App\Modules\Dashboards\Contracts\Dashboards;
use App\Modules\Dashboards\Contracts\ErrorCode;
use App\Modules\Dashboards\Contracts\InvalidLayout;
use App\Modules\Dashboards\Contracts\ItemLocked;
use App\Modules\Dashboards\Contracts\ItemNotFound;
use App\Modules\Dashboards\Contracts\RevisionConflict;
use App\Modules\Results\Contracts\RefreshOutcome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /**
     * The current results of the dashboard's Blocks (Story 4.5): what is stored, immediately. `?range=<preset>` is the dashboard Date Range as a
     * preset token; a token the Blocks do not offer is a 422 and a date is never accepted.
     */
    public function results(Request $request, string $dashboard): JsonResponse
    {
        [$workspaceId, $membershipId] = $this->membership($request, $this->memberships);
        $range = $request->query('range');

        if ($range !== null && ! is_string($range)) {
            abort(422, 'Choose one of the periods this block offers.');
        }

        try {
            $items = $this->dashboards->results($workspaceId, $membershipId, $dashboard, $range === '' ? null : $range);
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
        ]], 200, ['Cache-Control' => 'no-store, private']);
    }
}
