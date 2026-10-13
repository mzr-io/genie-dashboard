<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DraftRequest;
use App\Http\Resources\DraftResource;
use App\Http\Responses\AdminApiError;
use App\Models\User;
use App\Modules\Access\Contracts\MemberNames;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Blocks\Contracts\BlockArchived;
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
use App\Modules\Blocks\Contracts\DraftSlots;
use App\Modules\Blocks\Contracts\ErrorCode;
use App\Modules\Blocks\Contracts\InvalidDraft;
use App\Modules\Blocks\Contracts\PreviewRefused;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use App\Platform\EditLock\EditLock;
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
        private readonly DraftSlots $slots,
        private readonly BlockCategories $categories,
        private readonly MembershipLookup $memberships,
        private readonly MemberNames $names,
        private readonly EditLock $lock,
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
            return AdminApiError::json($request, ErrorCode::DraftExists->value, 409, 'This block already has a draft.', extra: ['draft_id' => $e->draftId, 'current' => ['data' => $this->currentOf($request, $e->draftId)]], errorExtra: ['draft_id' => $e->draftId]);
        } catch (BlockArchived) {
            return AdminApiError::json($request, ErrorCode::BlockArchived->value, 409, 'An archived block is read-only.');
        }

        return $this->one($request, $created, 201);
    }

    public function update(DraftRequest $request, string $draft): JsonResponse
    {
        try {
            $actor = $this->actor($request);
            $holdsLock = $this->holdsLock($request, $actor, $draft);
            // What a save of an unknown or published Draft answers is the save's own to say; this read only asks what the type was.
            try {
                $was = $request->type() === null ? null : $this->drafts->find($actor->workspaceId, $draft)->type;
            } catch (DraftNotFound) {
                $was = null;
            }

            $saved = $this->drafts->save($actor, $draft, $request->name(), $request->type(), $request->expectedRevision(), $request->lockEpoch(), $request->config(), $request->step(), $request->categoryId(), $request->uiState(), $holdsLock);

            // A new Block Type re-reads the mapping at once (Story 5.15): slots with the same name keep their mapping, rules and Confirmed state, the
            // plan is written for the new type, and a slot with no counterpart is left unmapped. The stored decisions of slots the new type lacks
            // stay, so switching back restores them. Without a sample there is nothing to map yet.
            if ($was !== null && $saved->type !== $was) {
                try {
                    $this->slots->rerun($actor, $draft, $saved->revision, $request->lockEpoch(), $holdsLock);
                    $saved = $this->drafts->find($actor->workspaceId, $draft);
                } catch (PreviewRefused) {
                    // No live sample: the mapping is read when there is one.
                }
            }
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

    /**
     * The check that the save's lock token still holds the soft lock (Story 3.6), as on the Data source form: with the lock enabled a
     * missing token is a lost lock, and the server never trusts the claim. Null when the lock is off (the epoch check alone applies).
     *
     * @return (\Closure(): bool)|null
     */
    private function holdsLock(DraftRequest $request, DraftActor $actor, string $draft): ?\Closure
    {
        if (! $this->lock->enabled()) {
            return null;
        }

        return $this->claim($request->lockToken(), $request->lockEpoch(), $actor, $draft);
    }

    /** @return (\Closure(): bool)|null */
    private function claim(mixed $token, ?int $epoch, DraftActor $actor, string $draft): ?\Closure
    {
        if (! $this->lock->enabled()) {
            return null;
        }

        $token = is_string($token) && strlen($token) <= 128 ? $token : '';

        return fn (): bool => $token !== '' && $epoch !== null && $this->lock->holds($actor->workspaceId, Drafts::LOCK_TYPE, $draft, $actor->membershipId, $token, $epoch);
    }

    /** Discards the Draft: it is hidden and cannot be saved at once; its rows are deleted afterwards by the `maintenance` worker. */
    public function destroy(Request $request, string $draft): JsonResponse
    {
        try {
            $actor = $this->actor($request);
            $epoch = $request->input('lock_epoch');
            $epoch = is_numeric($epoch) ? (int) $epoch : null;
            $this->drafts->discard($actor, $draft, $epoch, $this->claim($request->input('lock_token'), $epoch, $actor, $draft));
        } catch (DraftNotFound) {
            abort(404);
        } catch (DraftLockLost $e) {
            return AdminApiError::json($request, PlatformErrorCode::EditLockLost->value, 423, 'You no longer hold the edit lock on this draft.', extra: ['current' => ['data' => $this->resource($request, $e->current)]]);
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

    /**
     * The Draft that won the race, for the loser of a second create (Story 6.1): its state is in the 409.
     *
     * @return array<string, mixed>|null
     */
    private function currentOf(Request $request, string $draftId): ?array
    {
        try {
            return $this->resource($request, $this->drafts->find($this->workspaceId($request), $draftId));
        } catch (DraftNotFound) {
            return null;
        }
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
