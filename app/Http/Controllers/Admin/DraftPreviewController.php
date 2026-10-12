<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DraftUserSampleRequest;
use App\Http\Responses\AdminApiError;
use App\Models\User;
use App\Modules\Access\Contracts\ErrorCode as AccessErrorCode;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\DraftPreviews;
use App\Modules\Blocks\Contracts\DraftPublished;
use App\Modules\Blocks\Contracts\ErrorCode as BlocksErrorCode;
use App\Modules\Blocks\Contracts\PreviewRefused;
use App\Modules\Blocks\Contracts\SampleUnavailable;
use App\Modules\BlockTypes\Contracts\UnknownBlockType;
use App\Modules\Connector\Contracts\SamplePreviewDenied;
use App\Modules\Mapping\Contracts\ErrorCode;
use App\Modules\Mapping\Contracts\GuardExceeded;
use App\Modules\Mapping\Contracts\InvalidPlan;
use App\Modules\Mapping\Contracts\PreviewThrottled;
use App\Modules\Mapping\Contracts\SettingNotConfigured;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The live preview of a Draft (Story 3.10): the Draft's Query Plan run over its stored sample. The route sits behind the `admin` middleware
 * (`blocks.edit`); a read-only viewer, who does not hold the edit lock, may ask, because it takes no lock and writes nothing. A Draft of another
 * Workspace or a discarded one is a 404, a published one a 409, as on the sample routes. No refusal carries anything of the sample.
 */
final class DraftPreviewController extends Controller
{
    private const NO_STORE = 'no-store, private';

    public function __construct(
        private readonly DraftPreviews $previews,
        private readonly MembershipLookup $memberships,
    ) {}

    /**
     * With no body it is the shared preview. With `as_user_operation`, `data_source_id` and `endpoint_id` (Story 3.17) the same plan runs over the
     * Admin's own Fetch as user result: `data.preview_as_user` is needed (403 and an audited event without it), and a result that is gone is a 422
     * `blocks.preview_as_user_ended`, after which the wizard shows the shared sample again.
     */
    public function store(DraftUserSampleRequest $request, string $draft): JsonResponse
    {
        try {
            $preview = $this->previews->preview($this->actor($request), $draft, $request->ref(true));
        } catch (DraftNotFound) {
            abort(404);
        } catch (DraftPublished) {
            return AdminApiError::json($request, BlocksErrorCode::DraftPublished->value, 409, 'This draft was published.');
        } catch (PreviewRefused $e) {
            return AdminApiError::json($request, $e->reason, 422, match ($e->reason) {
                PreviewRefused::NO_PLAN => 'This draft has no query plan yet.',
                PreviewRefused::AS_USER_ENDED => 'The fetched user data is no longer available.',
                PreviewRefused::AS_USER_MISMATCH => "The fetched user data is for a different data source or endpoint than this draft's.",
                default => 'This draft has no sample yet.',
            });
        } catch (SamplePreviewDenied) {
            return AdminApiError::json($request, AccessErrorCode::NotAuthorized->value, 403, 'Forbidden');
        } catch (SettingNotConfigured $e) {
            return AdminApiError::json($request, ErrorCode::SettingNotConfigured->value, 422, "{$e->setting} is not configured.", extra: ['setting' => $e->setting]);
        } catch (PreviewThrottled $e) {
            return AdminApiError::json($request, PlatformErrorCode::TooManyRequests->value, 429, 'Too many previews.', extra: ['reason' => 'preview-throttled'], errorExtra: ['retry_after' => $e->retryAfter])
                ->header('Retry-After', (string) $e->retryAfter);
        } catch (InvalidPlan $e) {
            return AdminApiError::json($request, BlocksErrorCode::InvalidConfig->value, 422, 'The query plan is invalid.', errors: $e->errors);
        } catch (GuardExceeded $e) {
            return AdminApiError::json($request, ErrorCode::GuardExceeded->value, 422, 'The preview passed a limit and was stopped.', extra: ['guard' => $e->guard]);
        } catch (UnknownBlockType) {
            return AdminApiError::json($request, ErrorCode::BlockTypeUnavailable->value, 422, 'This block type cannot be previewed.');
        } catch (SampleUnavailable) {
            return AdminApiError::json($request, BlocksErrorCode::SampleUnavailable->value, 503, 'The sample cannot be opened right now.');
        }

        return response()->json(
            ['data' => [
                'payload' => $preview->payload,
                'unavailable' => (object) $preview->unavailable,
                'slots' => (object) $preview->slots,
                'sample_revision' => $preview->sampleRevision,
                'draft_revision' => $preview->draftRevision,
                'plan_stale' => $preview->planStale,
                'period' => $preview->period,
            ] + ($preview->asUserExpiresAt === null ? [] : ['as_user' => ['expires_at' => $preview->asUserExpiresAt]])],
            200,
            ['Cache-Control' => self::NO_STORE],
        );
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
