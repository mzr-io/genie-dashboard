<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GroupNameRequest;
use App\Http\Requests\Admin\ListGroupsRequest;
use App\Http\Resources\GroupResource;
use App\Http\Responses\AdminApiError;
use App\Models\User;
use App\Modules\Access\Contracts\GroupDirectory;
use App\Modules\Access\Contracts\GroupManager;
use App\Modules\Access\Contracts\GroupNameTaken;
use App\Modules\Access\Contracts\GroupNotFound;
use App\Modules\Access\Contracts\MemberEditor;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Contracts\MembershipNotFound;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Groups of the Workspace (Story 1.23). Every route sits behind the `admin` middleware (`users.manage`); the Workspace
 * is the session's, never a client-supplied ID. A group or member of another Workspace is invisible (404, under
 * row-level security). Adding a member who is in the group, and removing one who is not, are 200 no-ops.
 */
final class GroupController extends Controller
{
    /** Group data names people: never stored by a cache. */
    private const NO_STORE = 'no-store, private';

    public function __construct(
        private readonly GroupDirectory $groups,
        private readonly GroupManager $manager,
        private readonly MembershipLookup $memberships,
    ) {}

    public function index(ListGroupsRequest $request): JsonResponse
    {
        $query = $request->groupQuery();
        $page = $this->groups->list($this->workspaceId($request), $query);

        return GroupResource::collection($page->rows)->additional(['meta' => [
            'total' => $page->total,
            'matched' => $page->matched,
            'sort' => $query->sort->value,
            'direction' => $query->descending ? 'desc' : 'asc',
        ]])->response()->header('Cache-Control', self::NO_STORE);
    }

    public function store(GroupNameRequest $request): JsonResponse
    {
        $editor = $this->editor($request);

        try {
            $row = $this->manager->create($editor, $request->groupName());
        } catch (GroupNameTaken) {
            return $this->nameTaken($request);
        }

        return (new GroupResource($row))->response()->setStatusCode(201)->header('Cache-Control', self::NO_STORE);
    }

    public function update(GroupNameRequest $request, string $group): JsonResponse
    {
        $editor = $this->editor($request);

        try {
            $row = $this->manager->rename($editor, $group, $request->groupName());
        } catch (GroupNotFound) {
            abort(404);
        } catch (GroupNameTaken) {
            return $this->nameTaken($request);
        }

        return (new GroupResource($row))->response()->header('Cache-Control', self::NO_STORE);
    }

    public function destroy(Request $request, string $group): Response
    {
        try {
            $this->manager->delete($this->editor($request), $group);
        } catch (GroupNotFound) {
            abort(404);
        }

        return response()->noContent();
    }

    public function addMember(Request $request, string $group, string $membership): JsonResponse
    {
        $editor = $this->editor($request);

        try {
            $row = $this->manager->addMember($editor, $group, $membership);
        } catch (GroupNotFound|MembershipNotFound) {
            abort(404);
        }

        return (new GroupResource($row))->response()->header('Cache-Control', self::NO_STORE);
    }

    public function removeMember(Request $request, string $group, string $membership): JsonResponse
    {
        $editor = $this->editor($request);

        try {
            $row = $this->manager->removeMember($editor, $group, $membership);
        } catch (GroupNotFound|MembershipNotFound) {
            abort(404);
        }

        return (new GroupResource($row))->response()->header('Cache-Control', self::NO_STORE);
    }

    private function nameTaken(Request $request): JsonResponse
    {
        return AdminApiError::json($request, PlatformErrorCode::ValidationFailed->value, 422, errors: ['name' => ['A group with this name already exists.']], extra: ['reason' => 'name_taken']);
    }

    /** The Admin's own active membership in the session's Workspace; the `admin` middleware has proven it is an active Admin one. */
    private function editor(Request $request): MemberEditor
    {
        $user = $request->user();
        $workspaceId = $this->workspaceId($request);

        abort_unless($user instanceof User, 404);

        foreach ($this->memberships->forUser($user->id) as $membership) {
            if ($membership->workspaceId === strtolower($workspaceId) && $membership->status === 'active') {
                return new MemberEditor($user->id, $membership->membershipId, $membership->workspaceId);
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
