<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DraftSlotRequest;
use App\Http\Resources\DraftResource;
use App\Http\Responses\AdminApiError;
use App\Models\User;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftChecklist;
use App\Modules\Blocks\Contracts\DraftLockLost;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\DraftPublished;
use App\Modules\Blocks\Contracts\DraftRevisionConflict;
use App\Modules\Blocks\Contracts\Drafts;
use App\Modules\Blocks\Contracts\DraftSlots;
use App\Modules\Blocks\Contracts\ErrorCode as BlocksErrorCode;
use App\Modules\Blocks\Contracts\InvalidDraft;
use App\Modules\Blocks\Contracts\PreviewRefused;
use App\Modules\Blocks\Contracts\SampleUnavailable;
use App\Modules\Blocks\Contracts\SlotNotFound;
use App\Modules\Blocks\Contracts\SlotRefused;
use App\Modules\BlockTypes\Contracts\UnknownBlockType;
use App\Modules\Mapping\Contracts\ErrorCode as MappingErrorCode;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use App\Platform\EditLock\EditLock;
use App\Platform\Tenancy\WorkspaceTransaction;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Auto-map and the slot checklist of a Draft (Story 3.12a). Behind the `admin` middleware (`blocks.edit`). The read takes no lock, so a read-only
 * viewer may ask. Re-run, confirm, override and undo need the edit-lock claim of a Draft save (a stale epoch, or a token that does not hold the lock,
 * is a 423 with nothing written). No refusal carries anything of the sample.
 */
final class DraftSlotsController extends Controller
{
    private const NO_STORE = 'no-store, private';

    public function __construct(
        private readonly DraftSlots $slots,
        private readonly MembershipLookup $memberships,
        private readonly EditLock $lock,
    ) {}

    public function show(Request $request, string $draft): JsonResponse
    {
        return $this->respond($request, fn (): DraftChecklist => $this->slots->checklist($this->actor($request), $draft));
    }

    /** The field picker of a slot (Story 3.13a): read-only, like the checklist. */
    public function fields(Request $request, string $draft, string $slot): JsonResponse
    {
        return $this->respond(
            $request,
            fn (): array => $this->slots->fields($this->actor($request), $draft, $slot),
            fn (array $fields): array => ['slot' => $slot, 'fields' => $fields],
        );
    }

    public function undo(DraftSlotRequest $request, string $draft): JsonResponse
    {
        return $this->respond($request, function () use ($request, $draft): DraftChecklist {
            $actor = $this->actor($request);

            return $this->slots->undo($actor, $draft, $request->undoPayload(), $request->lockEpoch(), $this->holdsLock($request, $actor, $draft));
        });
    }

    public function rerun(DraftSlotRequest $request, string $draft): JsonResponse
    {
        return $this->respond($request, function () use ($request, $draft): DraftChecklist {
            $actor = $this->actor($request);

            return $this->slots->rerun($actor, $draft, $request->lockEpoch(), $this->holdsLock($request, $actor, $draft));
        });
    }

    public function confirm(DraftSlotRequest $request, string $draft, string $slot): JsonResponse
    {
        return $this->respond($request, function () use ($request, $draft, $slot): DraftChecklist {
            $actor = $this->actor($request);

            return $this->slots->confirm($actor, $draft, $slot, $request->lockEpoch(), $this->holdsLock($request, $actor, $draft));
        });
    }

    public function override(DraftSlotRequest $request, string $draft, string $slot): JsonResponse
    {
        return $this->respond($request, function () use ($request, $draft, $slot): DraftChecklist {
            $actor = $this->actor($request);

            return $this->slots->override($actor, $draft, $slot, $request->slotPath(), $request->lockEpoch(), $this->holdsLock($request, $actor, $draft));
        });
    }

    /**
     * @param  Closure(): mixed  $call
     * @param  (Closure(mixed): array<string, mixed>)|null  $render  the response data; the checklist's by default
     */
    private function respond(Request $request, Closure $call, ?Closure $render = null): JsonResponse
    {
        try {
            $result = $call();
        } catch (DraftNotFound|SlotNotFound) {
            abort(404);
        } catch (DraftLockLost $e) {
            return AdminApiError::json($request, PlatformErrorCode::EditLockLost->value, 423, 'You no longer hold the edit lock on this draft.', extra: ['current' => ['data' => (new DraftResource($e->current, withConfig: true))->resolve($request)]]);
        } catch (DraftPublished) {
            return AdminApiError::json($request, BlocksErrorCode::DraftPublished->value, 409, 'This draft was published.');
        } catch (DraftRevisionConflict $e) {
            return AdminApiError::json($request, BlocksErrorCode::RevisionConflict->value, 409, 'This slot was changed after that override.', extra: ['current' => ['data' => (new DraftResource($e->current, withConfig: true))->resolve($request)]]);
        } catch (PreviewRefused $e) {
            return AdminApiError::json($request, $e->reason, 422, 'This draft has no sample yet.');
        } catch (SampleUnavailable) {
            return AdminApiError::json($request, BlocksErrorCode::SampleUnavailable->value, 503, 'The sample cannot be opened right now.');
        } catch (UnknownBlockType) {
            return AdminApiError::json($request, MappingErrorCode::BlockTypeUnavailable->value, 422, 'This block type cannot be mapped.');
        } catch (SlotRefused $e) {
            return AdminApiError::json($request, PlatformErrorCode::ValidationFailed->value, 422, $e->problem, errors: ["slot.{$e->slot}" => [$e->problem]], extra: ['slot' => $e->slot] + ($e->compatible === [] ? [] : ['compatible' => $e->compatible]));
        } catch (InvalidDraft $e) {
            return AdminApiError::json($request, $e->errorCode ?? PlatformErrorCode::ValidationFailed->value, 422, 'The undo is not valid.', errors: $e->errors);
        }

        if ($render !== null) {
            return response()->json(['data' => $render($result)], 200, ['Cache-Control' => self::NO_STORE]);
        }

        /** @var DraftChecklist $checklist */
        $checklist = $result;

        return response()->json(
            ['data' => $checklist->result->toArray() + ['draft_revision' => $checklist->revision] + ($checklist->queryPlan === null ? [] : ['query_plan' => $checklist->queryPlan]) + ($checklist->roleChange === null ? [] : ['role_change' => $checklist->roleChange]) + ($checklist->undo === null ? [] : ['undo' => $checklist->undo])],
            200,
            ['Cache-Control' => self::NO_STORE],
        );
    }

    /**
     * The check that the request's lock token still holds the soft lock, as on a Draft save: with the lock enabled a missing token is a lost lock.
     * Null when the lock is off (the epoch check alone applies).
     *
     * @return (Closure(): bool)|null
     */
    private function holdsLock(DraftSlotRequest $request, DraftActor $actor, string $draft): ?Closure
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
        $id = $request->session()->get(WorkspaceTransaction::SESSION_KEY);
        $workspaceId = is_string($id) ? $id : abort(404);

        abort_unless($user instanceof User, 404);

        foreach ($this->memberships->forUser($user->id) as $membership) {
            if ($membership->workspaceId === strtolower($workspaceId) && $membership->status === 'active') {
                return new DraftActor($membership->membershipId, $membership->workspaceId);
            }
        }

        abort(404);
    }
}
