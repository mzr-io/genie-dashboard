<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\SessionClock;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** The session's idle clock for the client: how long is left, and a user-initiated way to extend it. */
final class SessionController
{
    public function __construct(private readonly Audit $audit) {}

    /** Never extends the session: IdleTimeout does not write the clock for this route. */
    public function status(Request $request): JsonResponse
    {
        return $this->reply($request);
    }

    public function extend(Request $request): JsonResponse
    {
        SessionClock::touch($request->session());

        $this->record($request);

        return $this->reply($request);
    }

    private function reply(Request $request): JsonResponse
    {
        $area = $request->session()->get('area');

        return response()->json([
            'remaining_seconds' => SessionClock::remaining($request->session()),
            'area' => is_string($area) ? $area : null,
        ])->header('Cache-Control', 'no-store');
    }

    private function record(Request $request): void
    {
        $workspaceId = $request->session()->get(WorkspaceTransaction::SESSION_KEY);

        if (! is_string($workspaceId)) {
            return;
        }

        try {
            $area = $request->session()->get('area');

            // A savepoint inside the request's transaction: a failure rolls back only this, never the request.
            DB::transaction(fn () => $this->audit->recordSecurityEvent(
                AuditAction::IdentitySessionExtended,
                ['user_id' => $request->user()?->id, 'area' => is_string($area) ? $area : null],
                $workspaceId,
            ));
        } catch (Throwable) {
            // An audit failure must not end the person's session extension.
            Log::error('identity.session.record_failed');
        }
    }
}
