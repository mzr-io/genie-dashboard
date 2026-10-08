<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EndpointRequest;
use App\Http\Requests\Admin\FetchAsUserRequest;
use App\Http\Requests\Admin\ListEndpointsRequest;
use App\Http\Requests\Admin\TestEndpointRequest;
use App\Http\Resources\EndpointResource;
use App\Http\Responses\AdminApiError;
use App\Models\User;
use App\Modules\Access\Contracts\AttributeKeyRow;
use App\Modules\Access\Contracts\AttributeKeys;
use App\Modules\Access\Contracts\ErrorCode as AccessErrorCode;
use App\Modules\Access\Contracts\MemberDirectory;
use App\Modules\Access\Contracts\MemberQuery;
use App\Modules\Access\Contracts\MemberRow;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Contracts\MembershipNotFound;
use App\Modules\Access\Contracts\MembershipPermissions;
use App\Modules\Access\Contracts\Permission;
use App\Modules\Connector\Contracts\DataSourceActor;
use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\DataSources;
use App\Modules\Connector\Contracts\Endpoint;
use App\Modules\Connector\Contracts\EndpointInput;
use App\Modules\Connector\Contracts\EndpointNotFound;
use App\Modules\Connector\Contracts\EndpointRevisionConflict;
use App\Modules\Connector\Contracts\Endpoints;
use App\Modules\Connector\Contracts\ErrorCode;
use App\Modules\Connector\Contracts\FetchesAsUser;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\SampleFetches;
use App\Modules\Connector\Contracts\SampleFetchThrottled;
use App\Modules\Connector\Contracts\SamplePreviewDenied;
use App\Modules\Connector\Contracts\Samples;
use App\Modules\Ingestion\Contracts\SyncStatuses;
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
        private readonly FetchesAsUser $asUser,
        private readonly AttributeKeys $attributeKeys,
        private readonly MemberDirectory $directory,
        private readonly MembershipPermissions $permissions,
        private readonly DataSources $sources,
        private readonly SyncStatuses $syncStatuses,
    ) {}

    public function index(ListEndpointsRequest $request, string $dataSource): JsonResponse
    {
        try {
            $page = $this->endpoints->list($this->workspaceId($request), $dataSource, $request->search());
        } catch (DataSourceNotFound) {
            abort(404);
        }

        $workspaceId = $this->workspaceId($request);
        $statuses = $this->syncStatuses->forEndpoints($workspaceId, array_map(fn (Endpoint $row): string => $row->id, $page->rows));

        return response()->json([
            'data' => array_map(fn (Endpoint $row): array => (new EndpointResource($row))->withSync($statuses[$row->id] ?? null)->resolve($request), $page->rows),
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
                'current' => ['data' => $this->resource($request, $e->current)],
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
     * Fetch as user (Story 2.13): the Admin (who holds `data.preview_as_user`, checked by the request) chooses an active member of the
     * Workspace; `202` with the Operation to poll. A target that is not an active member here is a 404, a `values` entry for a
     * user-bound name is a 422 `values.{name}`, and nothing is called from this tier: the values of the member are resolved by the
     * worker. The response is read back through {@see self::sample()} by the requester alone.
     */
    public function fetchAsUser(FetchAsUserRequest $request, string $dataSource, string $endpoint): JsonResponse
    {
        try {
            $operation = $this->asUser->start($this->actor($request), $dataSource, $endpoint, $request->membership(), $request->values());
        } catch (DataSourceNotFound|EndpointNotFound|MembershipNotFound) {
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
     * What the Binding select lists (Story 2.13): the four user-context bindings and each attribute key the Workspace defines (id,
     * label, type; never a value), and, for an Admin who holds `data.preview_as_user`, the active members a Fetch as user can target
     * (`?search=` narrows them). Nothing here reads a member's attribute values.
     */
    public function bindingOptions(Request $request, string $dataSource): JsonResponse
    {
        $workspaceId = strtolower($this->workspaceId($request));

        try {
            $this->sources->find($workspaceId, $dataSource);
        } catch (DataSourceNotFound) {
            abort(404);
        }

        $user = $request->user();
        $mayPreview = $user instanceof User && in_array(Permission::DataPreviewAsUser, $this->permissions->forUser($user->id, $workspaceId), true);
        $search = $request->query('search');

        $members = [];

        if ($mayPreview) {
            $page = $this->directory->page($workspaceId, new MemberQuery(is_string($search) && $search !== '' ? mb_substr($search, 0, 100) : null, pageSize: 50));

            foreach ($page->rows as $row) {
                if ($row->kind === MemberRow::MEMBER && $row->status === 'active') {
                    $members[] = ['membership_id' => $row->id, 'name' => $row->name, 'email' => $row->email];
                }
            }
        }

        return response()->json(['data' => [
            'bindings' => EndpointInput::USER_BINDINGS,
            'attributes' => array_map(fn (AttributeKeyRow $key): array => ['key_id' => $key->keyId, 'label' => $key->label, 'value_type' => $key->valueType], $this->attributeKeys->list($workspaceId)),
            'members' => $members,
            'may_preview' => $mayPreview,
        ]], 200, ['Cache-Control' => self::NO_STORE]);
    }

    /**
     * The Sample Response of a test (Story 2.10), only for the membership that asked and only while it is current. Everyone
     * else (another member, another Workspace, an expired, failed or stale Operation, a missing blob) gets a bare 404 with no body.
     */
    public function sample(Request $request, string $dataSource, string $endpoint, string $operation): JsonResponse|Response
    {
        $workspaceId = $request->session()->get(WorkspaceTransaction::SESSION_KEY);
        $membershipId = $this->membershipId($request);

        $user = $request->user();
        $mayPreview = is_string($workspaceId) && $user instanceof User
            && in_array(Permission::DataPreviewAsUser, $this->permissions->forUser($user->id, strtolower($workspaceId)), true);

        try {
            $sample = is_string($workspaceId) && $membershipId !== null
                ? $this->samples->read(strtolower($workspaceId), $membershipId, $dataSource, $endpoint, $operation, $mayPreview)
                : null;
        } catch (SamplePreviewDenied) {
            return AdminApiError::json($request, AccessErrorCode::NotAuthorized->value, 403, 'Forbidden');
        }

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
        return response()->json(['data' => $this->resource($request, $endpoint)], $status, ['Cache-Control' => self::NO_STORE]);
    }

    /**
     * One Endpoint with its scheduled-fetch status (Story 2.14), composed here from Ingestion's contract: Connector cannot call Ingestion.
     *
     * @return array<string, mixed>
     */
    private function resource(Request $request, Endpoint $endpoint): array
    {
        $statuses = $this->syncStatuses->forEndpoints($this->workspaceId($request), [$endpoint->id]);

        return (new EndpointResource($endpoint))->withSync($statuses[$endpoint->id] ?? null)->resolve($request);
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
