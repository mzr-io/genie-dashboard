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
use App\Modules\Blocks\Contracts\DraftMapping;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\DraftPresentation;
use App\Modules\Blocks\Contracts\DraftPublished;
use App\Modules\Blocks\Contracts\DraftRevisionConflict;
use App\Modules\Blocks\Contracts\Drafts;
use App\Modules\Blocks\Contracts\DraftSlots;
use App\Modules\Blocks\Contracts\DraftTransforms;
use App\Modules\Blocks\Contracts\ErrorCode as BlocksErrorCode;
use App\Modules\Blocks\Contracts\InvalidDraft;
use App\Modules\Blocks\Contracts\PreviewRefused;
use App\Modules\Blocks\Contracts\SampleUnavailable;
use App\Modules\Blocks\Contracts\SlotChanged;
use App\Modules\Blocks\Contracts\SlotNotFound;
use App\Modules\Blocks\Contracts\SlotRefused;
use App\Modules\BlockTypes\Contracts\UnknownBlockType;
use App\Modules\Mapping\Contracts\ErrorCode as MappingErrorCode;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use App\Platform\EditLock\EditLock;
use App\Platform\Tenancy\WorkspaceTransaction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auto-map and the slot checklist of a Draft (Story 3.12a) and the mapping writes (Story 3.13c). Behind the `admin` middleware (`blocks.edit`). The read takes no
 * lock, so a read-only viewer may ask. Every write carries the Draft's `draft_revision` (a stale one is a 409, nothing written) and needs the edit-lock claim of a Draft save (a stale epoch, or a token that does not hold the lock,
 * is a 423 with nothing written). No refusal carries anything of the sample; the read (field examples) and the preview (slot values) return bounded sample examples by design, to `blocks.edit` holders.
 */
final class DraftSlotsController extends Controller
{
    private const NO_STORE = 'no-store, private';

    public function __construct(
        private readonly DraftSlots $slots,
        private readonly MembershipLookup $memberships,
        private readonly EditLock $lock,
    ) {}

    /** The one consistent read (Story 3.13c), with an ETag of both revisions and a 304 for a matching `If-None-Match`. */
    public function show(Request $request, string $draft): Response
    {
        return $this->respond(
            $request,
            fn (): DraftMapping => $this->slots->read($this->actor($request), $draft),
            function (DraftMapping $mapping) use ($request): Response {
                $headers = ['Cache-Control' => self::NO_STORE, 'ETag' => $mapping->etag()];

                if (self::matches($request->header('If-None-Match'), $mapping->etag())) {
                    return response('', 304, $headers);
                }

                return response()->json(['data' => [
                    'checklist' => $mapping->checklist?->toArray(),
                    'roles' => $mapping->roles?->toArray(),
                    'slot_map' => (object) $mapping->slotMap,
                    'query_plan' => $mapping->queryPlan,
                    'draft_revision' => $mapping->draftRevision,
                    'sample_revision' => $mapping->sampleRevision,
                    'plan_stale' => $mapping->planStale,
                    'sample' => $mapping->sample,
                    'dataset_suggestion' => $mapping->datasetSuggestion,
                    'presentation' => (object) $mapping->presentation,
                    'transforms' => $mapping->transforms,
                ]], 200, $headers);
            },
        );
    }

    /** Whether an `If-None-Match` header names the validator (a list, weak tags and `*` included). */
    private static function matches(?string $header, string $etag): bool
    {
        if ($header === null) {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '*' || (str_starts_with($candidate, 'W/') ? substr($candidate, 2) : $candidate) === $etag) {
                return true;
            }
        }

        return false;
    }

    public function recordPath(DraftSlotRequest $request, string $draft): Response
    {
        return $this->respond($request, function () use ($request, $draft): DraftChecklist {
            $actor = $this->actor($request);

            return $this->slots->setRecordPath($actor, $draft, $request->recordPath(), $request->draftRevision(), $request->lockEpoch(), $this->holdsLock($request, $actor, $draft));
        });
    }

    public function fieldRole(DraftSlotRequest $request, string $draft): Response
    {
        return $this->respond($request, function () use ($request, $draft): DraftChecklist {
            $actor = $this->actor($request);

            return $this->slots->setFieldRole($actor, $draft, (string) $request->slotPath(), $request->role(), $request->draftRevision(), $request->lockEpoch(), $this->holdsLock($request, $actor, $draft));
        });
    }

    public function fieldType(DraftSlotRequest $request, string $draft): Response
    {
        return $this->respond($request, function () use ($request, $draft): DraftChecklist {
            $actor = $this->actor($request);

            return $this->slots->setFieldType($actor, $draft, (string) $request->slotPath(), $request->fieldType(), $request->draftRevision(), $request->lockEpoch(), $this->holdsLock($request, $actor, $draft));
        });
    }

    /** The presentation rules (Story 3.15a): the validated rules, the Draft's new revision and the plan it stores. */
    public function presentation(DraftSlotRequest $request, string $draft): Response
    {
        return $this->respond(
            $request,
            function () use ($request, $draft): DraftPresentation {
                $actor = $this->actor($request);

                return $this->slots->setPresentation($actor, $draft, $request->presentation(), $request->draftRevision(), $request->lockEpoch(), $this->holdsLock($request, $actor, $draft));
            },
            fn (DraftPresentation $saved): array => ['presentation' => (object) $saved->presentation, 'draft_revision' => $saved->revision, 'query_plan' => $saved->queryPlan],
        );
    }

    /** The Filter, Sort and Top-N steps (Story 5.1): the validated steps in stage order, the Draft's new revision, the plan it stores and the steps the sample cannot satisfy. */
    public function transforms(DraftSlotRequest $request, string $draft): Response
    {
        return $this->respond(
            $request,
            function () use ($request, $draft): DraftTransforms {
                $actor = $this->actor($request);

                return $this->slots->setTransforms($actor, $draft, $request->transforms(), $request->draftRevision(), $request->lockEpoch(), $this->holdsLock($request, $actor, $draft));
            },
            fn (DraftTransforms $saved): array => ['transforms' => $saved->steps, 'draft_revision' => $saved->revision, 'query_plan' => $saved->queryPlan, 'transform_faults' => $saved->faults],
        );
    }

    /** The field picker of a slot (Story 3.13a): read-only, like the checklist. */
    public function fields(Request $request, string $draft, string $slot): Response
    {
        return $this->respond(
            $request,
            fn (): array => $this->slots->fields($this->actor($request), $draft, $slot),
            fn (array $fields): array => ['slot' => $slot, 'fields' => $fields],
        );
    }

    public function undo(DraftSlotRequest $request, string $draft): Response
    {
        return $this->respond($request, function () use ($request, $draft): DraftChecklist {
            $actor = $this->actor($request);

            return $this->slots->undo($actor, $draft, $request->undoPayload(), $request->draftRevision(), $request->lockEpoch(), $this->holdsLock($request, $actor, $draft));
        });
    }

    public function rerun(DraftSlotRequest $request, string $draft): Response
    {
        return $this->respond($request, function () use ($request, $draft): DraftChecklist {
            $actor = $this->actor($request);

            return $this->slots->rerun($actor, $draft, $request->draftRevision(), $request->lockEpoch(), $this->holdsLock($request, $actor, $draft));
        });
    }

    public function confirm(DraftSlotRequest $request, string $draft, string $slot): Response
    {
        return $this->respond($request, function () use ($request, $draft, $slot): DraftChecklist {
            $actor = $this->actor($request);

            return $this->slots->confirm($actor, $draft, $slot, $request->draftRevision(), $request->lockEpoch(), $this->holdsLock($request, $actor, $draft));
        });
    }

    public function override(DraftSlotRequest $request, string $draft, string $slot): Response
    {
        return $this->respond($request, function () use ($request, $draft, $slot): DraftChecklist {
            $actor = $this->actor($request);

            return $this->slots->override($actor, $draft, $slot, $request->slotPath(), $request->draftRevision(), $request->lockEpoch(), $this->holdsLock($request, $actor, $draft));
        });
    }

    /**
     * @param  Closure(): mixed  $call
     * @param  (Closure(mixed): (array<string, mixed>|Response))|null  $render  the response data (or the whole response); a write's checklist by default
     */
    private function respond(Request $request, Closure $call, ?Closure $render = null): Response
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
            return AdminApiError::json($request, BlocksErrorCode::RevisionConflict->value, 409, 'This draft was changed after you loaded it.', extra: ['current' => ['data' => (new DraftResource($e->current, withConfig: true))->resolve($request)]]);
        } catch (SlotChanged $e) {
            return AdminApiError::json($request, BlocksErrorCode::SlotChanged->value, 409, 'This slot was changed after that override.', extra: ['current' => ['data' => (new DraftResource($e->current, withConfig: true))->resolve($request)]]);
        } catch (PreviewRefused $e) {
            return AdminApiError::json($request, $e->reason, 422, 'This draft has no sample yet.');
        } catch (SampleUnavailable) {
            return AdminApiError::json($request, BlocksErrorCode::SampleUnavailable->value, 503, 'The sample cannot be opened right now.');
        } catch (UnknownBlockType) {
            return AdminApiError::json($request, MappingErrorCode::BlockTypeUnavailable->value, 422, 'This block type cannot be mapped.');
        } catch (SlotRefused $e) {
            return AdminApiError::json($request, PlatformErrorCode::ValidationFailed->value, 422, $e->problem, errors: ["slot.{$e->slot}" => [$e->problem]], extra: ['slot' => $e->slot] + ($e->compatible === [] ? [] : ['compatible' => $e->compatible]));
        } catch (InvalidDraft $e) {
            return AdminApiError::json($request, $e->errorCode ?? PlatformErrorCode::ValidationFailed->value, 422, 'The mapping change is not valid.', errors: $e->errors);
        }

        if ($render !== null) {
            $rendered = $render($result);

            return $rendered instanceof Response ? $rendered : response()->json(['data' => $rendered], 200, ['Cache-Control' => self::NO_STORE]);
        }

        /** @var DraftChecklist $checklist */
        $checklist = $result;

        return response()->json(
            ['data' => $checklist->result->toArray() + ['draft_revision' => $checklist->revision, 'sample_revision' => $checklist->sampleRevision, 'query_plan' => $checklist->queryPlan] + ($checklist->roleChange === null ? [] : ['role_change' => $checklist->roleChange]) + ($checklist->undo === null ? [] : ['undo' => $checklist->undo])],
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
