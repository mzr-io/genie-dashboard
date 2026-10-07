<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AddHostRequest;
use App\Http\Requests\Admin\ListHostAllowlistRequest;
use App\Http\Requests\Admin\RemoveHostRequest;
use App\Http\Resources\HostEntryResource;
use App\Http\Responses\AdminApiError;
use App\Models\User;
use App\Modules\Access\Contracts\MemberNames;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Connector\Contracts\AllowlistActor;
use App\Modules\Connector\Contracts\AllowlistPage;
use App\Modules\Connector\Contracts\AllowlistQuery;
use App\Modules\Connector\Contracts\AllowlistRevisionConflict;
use App\Modules\Connector\Contracts\ErrorCode;
use App\Modules\Connector\Contracts\HostAllowlist;
use App\Modules\Connector\Contracts\HostAlreadyAllowed;
use App\Modules\Connector\Contracts\HostEntryNotFound;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Workspace host allowlist (Story 2.1). Every route sits behind the `admin` middleware (`settings.manage`); the
 * Workspace is the session's, never a client-supplied ID. An entry of another Workspace is invisible (404, under
 * row-level security). A stale `revision` is a 409 with the current list. Nothing here contacts any host.
 */
final class HostAllowlistController extends Controller
{
    /** Entries name the people who added them: never stored by a cache. */
    private const NO_STORE = 'no-store, private';

    public function __construct(
        private readonly HostAllowlist $allowlist,
        private readonly MemberNames $names,
        private readonly MembershipLookup $memberships,
    ) {}

    public function index(ListHostAllowlistRequest $request): JsonResponse
    {
        $query = $request->allowlistQuery();
        $page = $this->allowlist->list($this->workspaceId($request), $query);

        return response()->json($this->body($request, $page, $query), 200, ['Cache-Control' => self::NO_STORE]);
    }

    public function store(AddHostRequest $request): JsonResponse
    {
        $actor = $this->actor($request);

        try {
            $added = $this->allowlist->add($actor, $request->allowedHost(), $request->revision());
        } catch (HostAlreadyAllowed) {
            return AdminApiError::json($request, PlatformErrorCode::ValidationFailed->value, 422, errors: ['host' => ['This host and port are already on the allowlist.']], extra: ['reasons' => ['host' => 'duplicate']]);
        } catch (AllowlistRevisionConflict $e) {
            return $this->conflict($request, $e);
        }

        $name = $this->names->names($actor->workspaceId, [$added->entry->addedBy])[$added->entry->addedBy] ?? null;

        return response()->json([
            'data' => (new HostEntryResource($added->entry, $name))->resolve($request),
            'meta' => ['revision' => $added->revision],
        ], 201, ['Cache-Control' => self::NO_STORE]);
    }

    public function destroy(RemoveHostRequest $request, string $entry): JsonResponse
    {
        try {
            $revision = $this->allowlist->remove($this->actor($request), $entry, $request->revision());
        } catch (HostEntryNotFound) {
            abort(404);
        } catch (AllowlistRevisionConflict $e) {
            return $this->conflict($request, $e);
        }

        return response()->json(['data' => ['entry_id' => strtolower($entry)], 'meta' => ['revision' => $revision]], 200, ['Cache-Control' => self::NO_STORE]);
    }

    /** The Data Sources that removing the entry would block on their next call (the removal dialog lists them). */
    public function dependents(Request $request, string $entry): JsonResponse
    {
        try {
            $dependents = $this->allowlist->dependents($this->workspaceId($request), $entry);
        } catch (HostEntryNotFound) {
            abort(404);
        }

        return response()->json(
            ['data' => array_map(fn ($source): array => ['id' => $source->id, 'name' => $source->name], $dependents)],
            200,
            ['Cache-Control' => self::NO_STORE],
        );
    }

    private function conflict(Request $request, AllowlistRevisionConflict $e): JsonResponse
    {
        return AdminApiError::json($request, ErrorCode::RevisionConflict->value, 409, 'The host allowlist was changed by someone else.', extra: [
            'current' => $this->body($request, $e->current, new AllowlistQuery),
        ]);
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function body(Request $request, AllowlistPage $page, AllowlistQuery $query): array
    {
        $names = $this->names->names($this->workspaceId($request), array_map(fn ($row): string => $row->addedBy, $page->rows));

        return [
            'data' => array_map(fn ($row): array => (new HostEntryResource($row, $names[$row->addedBy] ?? null))->resolve($request), $page->rows),
            'meta' => [
                'revision' => $page->revision,
                'total' => $page->total,
                'matched' => $page->matched,
                'sort' => $query->sort->value,
                'direction' => $query->descending ? 'desc' : 'asc',
            ],
        ];
    }

    /** The Admin's own active membership in the session's Workspace; the `admin` middleware has proven it is an active Admin one. */
    private function actor(Request $request): AllowlistActor
    {
        $user = $request->user();
        $workspaceId = $this->workspaceId($request);

        abort_unless($user instanceof User, 404);

        foreach ($this->memberships->forUser($user->id) as $membership) {
            if ($membership->workspaceId === strtolower($workspaceId) && $membership->status === 'active') {
                return new AllowlistActor($membership->membershipId, $membership->workspaceId);
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
