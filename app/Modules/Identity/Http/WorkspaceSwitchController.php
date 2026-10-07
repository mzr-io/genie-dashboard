<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\SwitchWorkspace;
use App\Modules\Identity\Application\WorkspaceSwitchRefused;
use App\Modules\Identity\Contracts\SignInArea;
use App\Support\Observability\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * `POST /workspaces/switch` (Story 1.17). The Workspace ID travels in the body, never in the URL. A forged,
 * stale or deactivated one is a 403 with the Access code `access.workspace_forbidden` (Identity sits below
 * Access, so the code is held as text here; a test pins it to Access's enum).
 */
final class WorkspaceSwitchController
{
    public const FORBIDDEN_CODE = 'access.workspace_forbidden';

    public function __invoke(Request $request, SwitchWorkspace $switch, RequestContext $context): JsonResponse|RedirectResponse
    {
        try {
            $switched = $switch->handle($request, $request->post('workspace_id'));
        } catch (WorkspaceSwitchRefused) {
            return response()->json(['error' => [
                'code' => self::FORBIDDEN_CODE,
                'message' => 'Forbidden',
                'request_id' => $context->requestId(),
            ]], 403);
        }

        // The new Workspace's lists, navigation and permissions are read on this next request; the old
        // page (and any object ID on it) is left behind. A role drop lands on the User Overview.
        if ($switched->downgraded) {
            Inertia::flash('workspace_role', ['workspace' => $switched->workspaceName]);
        }

        return redirect()->route($switched->area === SignInArea::Admin ? 'admin.overview' : 'overview');
    }
}
