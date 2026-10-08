<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EndpointRequest;
use App\Http\Requests\Admin\ListEndpointsRequest;
use App\Http\Requests\Admin\TestEndpointRequest;
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
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\SampleFetches;
use App\Modules\Connector\Contracts\SampleFetchThrottled;
use App\Modules\Connector\Contracts\Samples;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Endpoints of a Data Source (Story 2.9). Every route sits behind the `admin` middleware (`data_sources.manage`); the
 * Workspace is the session's, never a client-supplied ID. A Data Source or Endpoint of another Workspace is invisible (404,
 * under row-level security). A stale `revision` is a 409 with the current state. Saving sends no request; a test (Story 2.10)
 * starts an Operation that `worker-connector` runs, and its Sample Response is read back by the requester alone.
 */
final class EndpointController extends Controller
{
    /** Endpoints are Admin-only configuration: never stored by a cache. */
    private const NO_STORE = 'no-store, private';

    public function __construct(
        private readonly Endpoints $endpoints,
        private readonly MembershipLookup $memberships,
        private readonly SampleFetches $tests,
        private readonly Samples $samples,
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

    /**
     * Tests an Endpoint (Story 2.10): `202` with the Operation to poll and the Endpoint revision tested. The values are checked
     * before anything is queued (a 422 names the parameter); nothing is called from this tier. Over the rate limit it is a 429
     * with `retry_after` and nothing is enqueued.
     */
    public function test(TestEndpointRequest $request, string $dataSource, string $endpoint): JsonResponse
    {
        try {
            $operation = $this->tests->start($this->actor($request), $dataSource, $endpoint, $request->values());
        } catch (DataSourceNotFound|EndpointNotFound) {
            abort(404);
        } catch (InvalidDataSource $e) {
            return AdminApiError::json($request, PlatformErrorCode::ValidationFailed->value, 422, errors: $e->errors, extra: $e->reasons === [] ? [] : ['reasons' => $e->reasons]);
        } catch (SampleFetchThrottled $e) {
            return AdminApiError::json($request, PlatformErrorCode::TooManyRequests->value, 429, 'Too many endpoint tests.', extra: ['reason' => 'sample-fetch-throttled'], errorExtra: ['retry_after' => $e->retryAfter])
                ->header('Retry-After', (string) $e->retryAfter);
        }

        return response()->json(
            ['data' => ['operation_id' => $operation->id, 'status' => $operation->status->value, 'expires_at' => $operation->expiresAt, 'endpoint_revision' => $operation->subjectRevision]],
            202,
            ['Cache-Control' => self::NO_STORE],
        );
    }

    /**
     * The Sample Response of a test (Story 2.10), only for the membership that asked and only while it is current. Everyone
     * else (another member, another Workspace, an expired, failed or stale Operation, a missing blob) gets a bare 404 with no body.
     */
    public function sample(Request $request, string $dataSource, string $endpoint, string $operation): JsonResponse|Response
    {
        $workspaceId = $request->session()->get(WorkspaceTransaction::SESSION_KEY);
        $membershipId = $this->membershipId($request);

        $sample = is_string($workspaceId) && $membershipId !== null
            ? $this->samples->read(strtolower($workspaceId), $membershipId, $dataSource, $endpoint, $operation)
            : null;

        if ($sample === null) {
            return response()->noContent(404);
        }

        return response()->json(
            ['data' => ['status' => $sample->status, 'latency_ms' => $sample->latencyMs, 'body' => $sample->body, 'expires_at' => $sample->expiresAt]],
            200,
            ['Cache-Control' => self::NO_STORE],
        );
    }

    private function one(Request $request, Endpoint $endpoint, int $status): JsonResponse
    {
        return response()->json(['data' => (new EndpointResource($endpoint))->resolve($request)], $status, ['Cache-Control' => self::NO_STORE]);
    }

    /** The Admin's own active membership in the session's Workspace; the `admin` middleware has proven it is an active Admin one. */
    private function actor(Request $request): DataSourceActor
    {
        $membershipId = $this->membershipId($request);

        abort_if($membershipId === null, 404);

        return new DataSourceActor($membershipId, strtolower($this->workspaceId($request)));
    }

    /** The id of the signed-in user's active membership in the session's Workspace, or null. */
    private function membershipId(Request $request): ?string
    {
        $user = $request->user();
        $workspaceId = $request->session()->get(WorkspaceTransaction::SESSION_KEY);

        if (! $user instanceof User || ! is_string($workspaceId)) {
            return null;
        }

        foreach ($this->memberships->forUser($user->id) as $membership) {
            if ($membership->workspaceId === strtolower($workspaceId) && $membership->status === 'active') {
                return $membership->membershipId;
            }
        }

        return null;
    }

    private function workspaceId(Request $request): string
    {
        $id = $request->session()->get(WorkspaceTransaction::SESSION_KEY);

        return is_string($id) ? $id : abort(404);
    }
}
