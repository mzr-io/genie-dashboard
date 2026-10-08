<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EndpointRequest;
use App\Http\Requests\Admin\ListEndpointsRequest;
use App\Http\Resources\EndpointResource;
use App\Http\Responses\AdminApiError;
use App\Models\User;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Connector\Contracts\DataSourceActor;
use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\Endpoint;
use App\Modules\Connector\Contracts\EndpointNotFound;
use App\Modules\Connector\Contracts\EndpointRevisionConflict;
use App\Modules\Connector\Contracts\Endpoints;
use App\Modules\Connector\Contracts\ErrorCode;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints of a Data Source (Story 2.9). Every route sits behind the `admin` middleware (`data_sources.manage`); the
 * Workspace is the session's, never a client-supplied ID. A Data Source or Endpoint of another Workspace is invisible (404,
 * under row-level security). A stale `revision` is a 409 with the current state. Nothing here sends a request.
 */
final class EndpointController extends Controller
{
    /** Endpoints are Admin-only configuration: never stored by a cache. */
    private const NO_STORE = 'no-store, private';

    public function __construct(
        private readonly Endpoints $endpoints,
        private readonly MembershipLookup $memberships,
    ) {}

    public function index(ListEndpointsRequest $request, string $dataSource): JsonResponse
    {
        try {
            $page = $this->endpoints->list($this->workspaceId($request), $dataSource, $request->search());
        } catch (DataSourceNotFound) {
            abort(404);
        }

        return response()->json([
            'data' => array_map(fn (Endpoint $row): array => (new EndpointResource($row))->resolve($request), $page->rows),
            'meta' => ['total' => $page->total, 'matched' => $page->matched],
        ], 200, ['Cache-Control' => self::NO_STORE]);
    }

    public function show(Request $request, string $dataSource, string $endpoint): JsonResponse
    {
        try {
            $found = $this->endpoints->find($this->workspaceId($request), $dataSource, $endpoint);
        } catch (DataSourceNotFound|EndpointNotFound) {
            abort(404);
        }

        return $this->one($request, $found, 200);
    }

    public function store(EndpointRequest $request, string $dataSource): JsonResponse
    {
        try {
            $created = $this->endpoints->create($this->actor($request), $dataSource, $request->endpointInput());
        } catch (DataSourceNotFound) {
            abort(404);
        }

        return $this->one($request, $created, 201);
    }

    public function update(EndpointRequest $request, string $dataSource, string $endpoint): JsonResponse
    {
        try {
            $updated = $this->endpoints->revise($this->actor($request), $dataSource, $endpoint, $request->endpointInput(), $request->revision());
        } catch (DataSourceNotFound|EndpointNotFound) {
            abort(404);
        } catch (EndpointRevisionConflict $e) {
            return AdminApiError::json($request, ErrorCode::RevisionConflict->value, 409, 'This endpoint was changed by someone else.', extra: [
                'current' => ['data' => (new EndpointResource($e->current))->resolve($request)],
            ]);
        }

        return $this->one($request, $updated, 200);
    }

    private function one(Request $request, Endpoint $endpoint, int $status): JsonResponse
    {
        return response()->json(['data' => (new EndpointResource($endpoint))->resolve($request)], $status, ['Cache-Control' => self::NO_STORE]);
    }

    /** The Admin's own active membership in the session's Workspace; the `admin` middleware has proven it is an active Admin one. */
    private function actor(Request $request): DataSourceActor
    {
        $user = $request->user();
        $workspaceId = $this->workspaceId($request);

        abort_unless($user instanceof User, 404);

        foreach ($this->memberships->forUser($user->id) as $membership) {
            if ($membership->workspaceId === strtolower($workspaceId) && $membership->status === 'active') {
                return new DataSourceActor($membership->membershipId, $membership->workspaceId);
            }
        }

        abort(404);
    }

    private function workspaceId(Request $request): string
    {
        $id = $request->session()->get(WorkspaceTransaction::SESSION_KEY);

        return is_string($id) ? $id : abort(404);
    }
}
