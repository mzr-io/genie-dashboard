<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DraftPublishRequest;
use App\Http\Resources\DraftResource;
use App\Http\Responses\AdminApiError;
use App\Models\User;
use App\Modules\Access\Contracts\MemberNames;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Blocks\Contracts\BlockPublishing;
use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftLockLost;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\DraftPublished;
use App\Modules\Blocks\Contracts\Drafts;
use App\Modules\Blocks\Contracts\ErrorCode;
use App\Modules\Blocks\Contracts\PublishedBlock;
use App\Modules\Blocks\Contracts\PublishedBlocks;
use App\Modules\Blocks\Contracts\PublishPending;
use App\Modules\Blocks\Contracts\PublishRefused;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use App\Platform\EditLock\EditLock;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Publish v1.0 and the Published blocks list (Story 3.19). Publish sits behind the `admin` middleware with `blocks.publish` (a holder of `blocks.edit`
 * alone is a 403 and an audited denial, from the gate) and carries the edit-lock claim of a save (423). A Draft that is gone or discarded is a 404;
 * one that was published meanwhile (a double click, a second tab) a 409 `blocks.draft_published`; a missing, stale, failed or unreachable report a
 * 409 `blocks.publish_not_ready` with its `reason`. In each case nothing changed. No response carries a config, a sample or a response value.
 */
final class DraftPublishController extends Controller
{
    private const NO_STORE = 'no-store, private';

    public function __construct(
        private readonly BlockPublishing $publishing,
        private readonly PublishedBlocks $published,
        private readonly MembershipLookup $memberships,
        private readonly MemberNames $names,
        private readonly EditLock $lock,
    ) {}

    public function store(DraftPublishRequest $request, string $draft): JsonResponse
    {
        try {
            $actor = $this->actor($request);
            $version = $this->publishing->publish($actor, $draft, $request->lockEpoch(), $this->holdsLock($request, $actor, $draft));
        } catch (DraftNotFound) {
            abort(404);
        } catch (DraftLockLost $e) {
            return AdminApiError::json($request, PlatformErrorCode::EditLockLost->value, 423, 'You no longer hold the edit lock on this draft.', extra: ['current' => ['data' => (new DraftResource($e->current, withConfig: true))->resolve($request)]]);
        } catch (DraftPublished) {
            return AdminApiError::json($request, ErrorCode::DraftPublished->value, 409, 'This draft was published.');
        } catch (PublishPending) {
            // The version is frozen and valid; only its activation is outstanding. Not a save failure: a retry (or the maintenance job) completes it.
            return AdminApiError::json($request, ErrorCode::PublishPending->value, 503, 'Your block was saved as ready; it will go live shortly.');
        } catch (PublishRefused $e) {
            return AdminApiError::json($request, ErrorCode::PublishNotReady->value, 409, 'The block has not passed validation for its current revision.', extra: ['reason' => $e->reason], errorExtra: ['reason' => $e->reason]);
        }

        return response()->json(['data' => [
            'draft_id' => $version->versionId,
            'block_id' => $version->blockId,
            'version_id' => $version->versionId,
            'name' => $version->name,
            'type' => $version->type,
            'category' => $version->category,
            'version' => $version->version,
            'state' => 'published',
            'published_at' => $version->publishedAt,
        ]], 200, ['Cache-Control' => self::NO_STORE]);
    }

    /** The Workspace's published Blocks, newest first. */
    public function index(Request $request): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);
        $rows = $this->published->list($workspaceId);
        $ids = array_values(array_unique(array_filter(array_map(fn (PublishedBlock $row): string => $row->ownerMembershipId, $rows), fn (string $id): bool => $id !== '')));
        $owners = $ids === [] ? [] : $this->names->names($workspaceId, $ids);

        return response()->json([
            'data' => array_map(fn (PublishedBlock $row): array => [
                'block_id' => $row->blockId,
                'version_id' => $row->versionId,
                'name' => $row->name,
                'type' => $row->type,
                'category' => $row->category,
                'version' => $row->version,
                'owner_membership_id' => $row->ownerMembershipId,
                'owner' => $row->ownerMembershipId === '' ? null : ($owners[$row->ownerMembershipId] ?? null),
                'updated_at' => $row->updatedAt,
                'status' => $row->status,
            ], $rows),
            'meta' => ['total' => count($rows)],
        ], 200, ['Cache-Control' => self::NO_STORE]);
    }

    /**
     * The check that the request's lock token still holds the soft lock, as on a Draft save. Null when the lock is off (the epoch check alone applies).
     *
     * @return (\Closure(): bool)|null
     */
    private function holdsLock(DraftPublishRequest $request, DraftActor $actor, string $draft): ?\Closure
    {
        if (! $this->lock->enabled()) {
            return null;
        }

        $token = $request->lockToken();
        $epoch = $request->lockEpoch();

        return fn (): bool => $token !== '' && $this->lock->holds($actor->workspaceId, Drafts::LOCK_TYPE, $draft, $actor->membershipId, $token, $epoch);
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

        return is_string($id) ? $id : abort(404);
    }
}
