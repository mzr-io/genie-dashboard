<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesMembership;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Dashboards\Contracts\DashboardNotFound;
use App\Modules\Dashboards\Contracts\Dashboards;
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
