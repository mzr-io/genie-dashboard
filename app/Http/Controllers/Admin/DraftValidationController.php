<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DraftValidationRequest;
use App\Http\Resources\DraftResource;
use App\Http\Responses\AdminApiError;
use App\Models\User;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftLockLost;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\DraftPublished;
use App\Modules\Blocks\Contracts\Drafts;
use App\Modules\Blocks\Contracts\DraftValidations;
use App\Modules\Blocks\Contracts\ErrorCode;
use App\Modules\Blocks\Contracts\ValidationRefused;
use App\Modules\Blocks\Contracts\ValidationReport;
use App\Modules\BlockTypes\Contracts\UnknownBlockType;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\SampleFetchThrottled;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use App\Platform\EditLock\EditLock;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Validate against the real API (Story 3.18, wizard step 5). Starting is a write to the Draft's report history, so it carries the edit-lock claim of
 * a save (423) and, like Fetch from API, answers `202` with the Operation to poll at `GET /api/v1/operations/{id}`. The report is read back with
 * {@see self::show()}, which a read-only viewer may call. A Draft of another Workspace or a discarded one is a 404, a published one a 409. No
 * response carries a sample or a response value: a mismatch is a path and a type.
 */
final class DraftValidationController extends Controller
{
    private const NO_STORE = 'no-store, private';

    public function __construct(
        private readonly DraftValidations $validations,
        private readonly MembershipLookup $memberships,
        private readonly EditLock $lock,
    ) {}

    /** The Draft's latest report as the API shows it, or `data: null` when it was never validated. */
    public function show(Request $request, string $draft): JsonResponse
    {
        try {
            $report = $this->validations->latest($this->workspaceId($request), $draft);
        } catch (DraftNotFound) {
            abort(404);
        } catch (DraftPublished) {
            return AdminApiError::json($request, ErrorCode::DraftPublished->value, 409, 'This draft was published.');
        }

        return response()->json(['data' => $report === null ? null : self::body($report)], 200, ['Cache-Control' => self::NO_STORE]);
    }

    public function store(DraftValidationRequest $request, string $draft): JsonResponse
    {
        try {
            $actor = $this->actor($request);
            $operation = $this->validations->start($actor, $draft, $request->range(), $request->period(), $request->lockEpoch(), $this->holdsLock($request, $actor, $draft));
        } catch (DraftNotFound) {
            abort(404);
        } catch (DraftLockLost $e) {
            return AdminApiError::json($request, PlatformErrorCode::EditLockLost->value, 423, 'You no longer hold the edit lock on this draft.', extra: ['current' => ['data' => (new DraftResource($e->current, withConfig: true))->resolve($request)]]);
        } catch (DraftPublished) {
            return AdminApiError::json($request, ErrorCode::DraftPublished->value, 409, 'This draft was published.');
        } catch (ValidationRefused $e) {
            return AdminApiError::json(
                $request, ErrorCode::ValidationRefused->value, 422, 'The block was not validated.',
                extra: ['reason' => $e->reason] + ($e->setting === null ? [] : ['setting' => $e->setting]),
            );
        } catch (UnknownBlockType) {
            return AdminApiError::json($request, ErrorCode::ValidationRefused->value, 422, 'This block type cannot be validated.', extra: ['reason' => 'block_type_unavailable']);
        } catch (InvalidDataSource $e) {
            return AdminApiError::json($request, PlatformErrorCode::ValidationFailed->value, 422, errors: $e->errors, extra: $e->reasons === [] ? [] : ['reasons' => $e->reasons]);
        } catch (SampleFetchThrottled $e) {
            return AdminApiError::json($request, PlatformErrorCode::TooManyRequests->value, 429, 'Too many validations.', extra: ['reason' => 'sample-fetch-throttled'], errorExtra: ['retry_after' => $e->retryAfter])
                ->header('Retry-After', (string) $e->retryAfter);
        }

        return response()->json(
            ['data' => ['operation_id' => $operation->id, 'status' => $operation->status->value, 'expires_at' => $operation->expiresAt, 'draft_revision' => $operation->subjectRevision]],
            202,
            ['Cache-Control' => self::NO_STORE],
        );
    }

    /** @return array<string, mixed> */
    private static function body(ValidationReport $report): array
    {
        return [
            'id' => $report->id,
            'status' => $report->status,
            'stored_status' => $report->storedStatus,
            'reason' => $report->reason,
            'subject_revision' => $report->subjectRevision,
            'draft_revision' => $report->draftRevision,
            'mismatches' => $report->mismatches,
            'version' => $report->version,
            'endpoint_id' => $report->endpointId,
            'endpoint_revision' => $report->endpointRevision,
            'validated_at' => $report->validatedAt,
            'can_publish' => $report->allowsPublish(),
        ];
    }

    /**
     * The check that the request's lock token still holds the soft lock, as on a Draft save. Null when the lock is off (the epoch check alone applies).
     *
     * @return (\Closure(): bool)|null
     */
    private function holdsLock(DraftValidationRequest $request, DraftActor $actor, string $draft): ?\Closure
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
