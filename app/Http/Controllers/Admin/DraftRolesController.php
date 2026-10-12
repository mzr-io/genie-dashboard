<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminApiError;
use App\Models\User;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftFieldRoles;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\ErrorCode as BlocksErrorCode;
use App\Modules\Blocks\Contracts\PreviewRefused;
use App\Modules\Blocks\Contracts\SampleUnavailable;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The field roles and record-path candidates of a Draft's sample (Story 3.11a). Behind the `admin` middleware (`blocks.edit`); a read-only viewer
 * who does not hold the edit lock may ask, because it takes no lock and writes nothing. No refusal carries anything of the sample.
 */
final class DraftRolesController extends Controller
{
    private const NO_STORE = 'no-store, private';

    public function __construct(
        private readonly DraftFieldRoles $roles,
        private readonly MembershipLookup $memberships,
    ) {}

    public function show(Request $request, string $draft): JsonResponse
    {
        try {
            $roles = $this->roles->roles($this->actor($request), $draft);
        } catch (DraftNotFound) {
            abort(404);
        } catch (PreviewRefused $e) {
            return AdminApiError::json($request, $e->reason, 422, 'This draft has no sample yet.');
        } catch (SampleUnavailable) {
            return AdminApiError::json($request, BlocksErrorCode::SampleUnavailable->value, 503, 'The sample cannot be opened right now.');
        }

        return response()->json(['data' => $roles->report->toArray() + ['draft_revision' => $roles->draftRevision, 'sample_revision' => $roles->sampleRevision]], 200, ['Cache-Control' => self::NO_STORE]);
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
