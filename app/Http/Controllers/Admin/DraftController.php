<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DraftRequest;
use App\Http\Resources\DraftResource;
use App\Http\Responses\AdminApiError;
use App\Models\User;
use App\Modules\Access\Contracts\MemberNames;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Blocks\Contracts\BlockCategories;
use App\Modules\Blocks\Contracts\Category;
use App\Modules\Blocks\Contracts\Draft;
use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftExists;
use App\Modules\Blocks\Contracts\DraftLockLost;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\DraftPublished;
use App\Modules\Blocks\Contracts\DraftRevisionConflict;
use App\Modules\Blocks\Contracts\Drafts;
use App\Modules\Blocks\Contracts\ErrorCode;
use App\Modules\Blocks\Contracts\InvalidDraft;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Draft blocks (Story 3.3). Every route sits behind the `admin` middleware (`blocks.edit`); the Workspace is the session's, never a
 * client-supplied ID. A Draft of another Workspace, or a discarded one, is a 404. A stale `expected_revision` is a 409 and a stale
 * `lock_epoch` a 423, each with the current state and nothing written. The create and save routes refuse secret-valued fields
 * (`RejectsSecretValues`, 422) before this runs.
 */
final class DraftController extends Controller
{
    /** Drafts are Admin-only work in progress: never stored by a cache. */
    private const NO_STORE = 'no-store, private';

    public function __construct(
        private readonly Drafts $drafts,
        private readonly BlockCategories $categories,
        private readonly MembershipLookup $memberships,
        private readonly MemberNames $names,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);
        $search = $request->query('q');
        $page = $this->drafts->list($workspaceId, is_string($search) ? mb_substr($search, 0, 100) : null);
        $owners = $this->names->names($workspaceId, array_values(array_unique(array_map(fn (Draft $row): string => $row->ownerMembershipId, $page->rows))));

        return response()->json([
            'data' => array_map(fn (Draft $row): array => (new DraftResource($row, $owners[$row->ownerMembershipId] ?? null))->resolve($request), $page->rows),
            'meta' => ['total' => $page->total, 'matched' => $page->matched],
        ], 200, ['Cache-Control' => self::NO_STORE]);
    }

    /** One Draft with its config, for the wizard (Story 3.4). A discarded, unknown or other Workspace's Draft is a 404. */
    public function show(Request $request, string $draft): JsonResponse
    {
        try {
            $found = $this->drafts->find($this->workspaceId($request), $draft);
        } catch (DraftNotFound) {
            abort(404);
        }

        return $this->one($request, $found, 200);
    }

    /** The Workspace's block categories; "General" is created on the first read (Story 3.4). */
    public function categories(Request $request): JsonResponse
    {
        $all = $this->categories->all($this->workspaceId($request));

        return response()->json(['data' => array_map(fn (Category $category): array => $category->toArray(), $all)], 200, ['Cache-Control' => self::NO_STORE]);
    }

    public function store(DraftRequest $request): JsonResponse
    {
        try {
            $created = $this->drafts->create($this->actor($request), $request->name(), (string) $request->type(), $request->blockId());
        } catch (InvalidDraft $e) {
            return $this->invalid($request, $e);
        } catch (DraftExists $e) {
            return AdminApiError::json($request, ErrorCode::DraftExists->value, 409, 'This block already has a draft.', extra: ['draft_id' => $e->draftId], errorExtra: ['draft_id' => $e->draftId]);
        }

        return $this->one($request, $created, 201);
    }

    public function update(DraftRequest $request, string $draft): JsonResponse
    {
        try {
            $saved = $this->drafts->save($this->actor($request), $draft, $request->name(), $request->type(), $request->expectedRevision(), $request->lockEpoch(), $request->config(), $request->step(), $request->categoryId(), $request->uiState());
        } catch (DraftNotFound) {
            abort(404);
        } catch (DraftLockLost $e) {
            // 423: the soft lock was taken over. Nothing was written.
            return AdminApiError::json($request, PlatformErrorCode::EditLockLost->value, 423, 'You no longer hold the edit lock on this draft.', extra: ['current' => ['data' => $this->resource($request, $e->current)]]);
        } catch (DraftPublished) {
            // 409 with its own code: the wizard stops saving and points to Published blocks; a revision conflict is a different choice.
            return AdminApiError::json($request, ErrorCode::DraftPublished->value, 409, 'This draft was published.');
        } catch (DraftRevisionConflict $e) {
            return AdminApiError::json($request, ErrorCode::RevisionConflict->value, 409, 'This draft was changed by someone else.', extra: ['current' => ['data' => $this->resource($request, $e->current)]]);
        } catch (InvalidDraft $e) {
            return $this->invalid($request, $e);
        }

        return $this->one($request, $saved, 200);
    }

    /** Discards the Draft: it is hidden and cannot be saved at once; its rows are deleted afterwards by the `maintenance` worker. */
    public function destroy(Request $request, string $draft): JsonResponse
    {
        try {
            $this->drafts->discard($this->actor($request), $draft);
        } catch (DraftNotFound) {
            abort(404);
        }

        return response()->json(['data' => ['draft_id' => strtolower($draft), 'state' => 'discarded']], 200, ['Cache-Control' => self::NO_STORE]);
    }

    private function invalid(Request $request, InvalidDraft $e): JsonResponse
    {
        return AdminApiError::json($request, $e->errorCode ?? PlatformErrorCode::ValidationFailed->value, 422, errors: $e->errors);
    }

    /** @return array<string, mixed> */
    private function resource(Request $request, Draft $draft): array
    {
        return (new DraftResource($draft, withConfig: true))->resolve($request);
    }

    private function one(Request $request, Draft $draft, int $status): JsonResponse
    {
        return response()->json(['data' => $this->resource($request, $draft)], $status, ['Cache-Control' => self::NO_STORE]);
    }

    /** The Admin's own active membership in the session's Workspace; the `admin` middleware has proven it is an active Admin one. */
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

        return is_string($id) ? $id : abort(404);
    }
}
