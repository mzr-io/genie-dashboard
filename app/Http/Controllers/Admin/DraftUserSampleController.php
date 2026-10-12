<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DraftFetchAsUserRequest;
use App\Http\Requests\Admin\DraftUserSampleRequest;
use App\Http\Requests\Admin\PreviewMembersRequest;
use App\Http\Responses\AdminApiError;
use App\Models\User;
use App\Modules\Access\Contracts\ErrorCode as AccessErrorCode;
use App\Modules\Access\Contracts\MemberDirectory;
use App\Modules\Access\Contracts\MemberQuery;
use App\Modules\Access\Contracts\MemberRow;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Contracts\MembershipNotFound;
use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\DraftPublished;
use App\Modules\Blocks\Contracts\DraftUserSamples;
use App\Modules\Blocks\Contracts\ErrorCode;
use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\EndpointNotFound;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\SampleFetchThrottled;
use App\Modules\Connector\Contracts\SamplePreviewDenied;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Fetch as user from the Create block wizard (Story 3.17). Every route sits behind the `admin` middleware (`blocks.edit`) and its request adds
 * `data.preview_as_user` (403 and an audited security event without it). The member's attribute values are resolved by the worker and never
 * reach this tier or the browser; the response is the Operation's blob, read only by the Admin that started it, and what the browser gets is its
 * value-free structure. Nothing here writes the Draft, so no edit-lock claim is taken (like the preview, a read-only viewer may ask).
 */
final class DraftUserSampleController extends Controller
{
    private const NO_STORE = 'no-store, private';

    private const MEMBER_LIMIT = 50;

    private const MAX_PAGES = 20;

    public function __construct(
        private readonly DraftUserSamples $samples,
        private readonly MemberDirectory $directory,
        private readonly MembershipLookup $memberships,
    ) {}

    /** The active members a Fetch as user can target, with the groups they belong to (names only; never an attribute value). */
    public function members(PreviewMembersRequest $request): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);
        $members = [];
        $cursor = null;

        // Invitations and deactivated rows are skipped, so keep paging until enough active members are collected (bounded).
        for ($pages = 0; $pages < self::MAX_PAGES && count($members) < self::MEMBER_LIMIT; $pages++) {
            $page = $this->directory->page($workspaceId, new MemberQuery($request->search(), pageSize: self::MEMBER_LIMIT, cursor: $cursor));

            foreach ($page->rows as $row) {
                if ($row->kind === MemberRow::MEMBER && $row->status === 'active' && count($members) < self::MEMBER_LIMIT) {
                    $members[] = [
                        'membership_id' => $row->id,
                        'name' => $row->name,
                        'email' => $row->email,
                        'groups' => array_map(fn (array $group): string => $group['name'], $row->groups),
                    ];
                }
            }

            $cursor = $page->nextCursor;

            if ($cursor === null) {
                break;
            }
        }

        return response()->json(['data' => ['members' => $members]], 200, ['Cache-Control' => self::NO_STORE]);
    }

    /**
     * Starts the Fetch as user: `202` with the Operation to poll at `GET /api/v1/operations/{id}`. A target that is not an active member here, or an
     * Endpoint of another Workspace, is a 404; a value that does not fit is a 422 `values.{name}`; over the rate limit a 429 with `retry_after`.
     */
    public function fetch(DraftFetchAsUserRequest $request, string $draft): JsonResponse
    {
        try {
            $operation = $this->samples->start(
                $this->actor($request), $draft, $request->dataSourceId(), $request->endpointId(), $request->membership(),
                $request->bindings(), $request->defaultDateRange(),
            );
        } catch (DraftNotFound|DataSourceNotFound|EndpointNotFound|MembershipNotFound) {
            abort(404);
        } catch (DraftPublished) {
            return AdminApiError::json($request, ErrorCode::DraftPublished->value, 409, 'This draft was published.');
        } catch (InvalidDataSource $e) {
            return AdminApiError::json($request, PlatformErrorCode::ValidationFailed->value, 422, errors: $e->errors, extra: $e->reasons === [] ? [] : ['reasons' => $e->reasons]);
        } catch (SampleFetchThrottled $e) {
            return AdminApiError::json($request, PlatformErrorCode::TooManyRequests->value, 429, 'Too many fetches as a user.', extra: ['reason' => 'sample-fetch-throttled'], errorExtra: ['retry_after' => $e->retryAfter])
                ->header('Retry-After', (string) $e->retryAfter);
        }

        return response()->json(
            ['data' => ['operation_id' => $operation->id, 'status' => $operation->status->value, 'expires_at' => $operation->expiresAt, 'endpoint_revision' => $operation->subjectRevision]],
            202,
            ['Cache-Control' => self::NO_STORE],
        );
    }

    /** The value-free structure of a finished Fetch as user, for the requester alone; anything else (gone, not theirs, failed, stale) is a bare 404. */
    public function shape(DraftUserSampleRequest $request, string $draft, string $operation): JsonResponse|Response
    {
        $ref = $request->ref(true);

        try {
            $view = $ref === null ? null : $this->samples->view($this->actor($request), $draft, $ref);
        } catch (DraftNotFound) {
            abort(404);
        } catch (DraftPublished) {
            return AdminApiError::json($request, ErrorCode::DraftPublished->value, 409, 'This draft was published.');
        } catch (SamplePreviewDenied) {
            return AdminApiError::json($request, AccessErrorCode::NotAuthorized->value, 403, 'Forbidden');
        }

        if ($view === null) {
            return response()->noContent(404);
        }

        return response()->json(
            ['data' => ['status' => $view->status, 'latency_ms' => $view->latencyMs, 'expires_at' => $view->expiresAt, 'shape' => $view->shape]],
            200,
            ['Cache-Control' => self::NO_STORE],
        );
    }

    private function actor(Request $request): DraftActor
    {
        $user = $request->user();
        $workspaceId = $this->workspaceId($request);

        abort_unless($user instanceof User, 404);

        foreach ($this->memberships->forUser($user->id) as $membership) {
            if ($membership->workspaceId === strtolower($workspaceId) && $membership->status === 'active') {
                return new DraftActor($membership->membershipId, $membership->workspaceId);
            }
        }

        abort(404);
    }

    private function workspaceId(Request $request): string
    {
        $id = $request->session()->get(WorkspaceTransaction::SESSION_KEY);

        return is_string($id) ? strtolower($id) : abort(404);
    }
}
