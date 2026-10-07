<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\QueuedInvitationCourier;
use App\Modules\Identity\Contracts\ErrorCode;
use App\Modules\Identity\Contracts\InvitationDeliveryFailed;
use App\Support\Observability\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * API middleware placed just outside `WorkspaceTransaction`: when the inner request has committed (a response below
 * 400), it sends the invitation emails the request queued (only on a 2xx answer with no transaction left open). A delivery failure turns the response into 503 with
 * `identity.invitation_delivery_failed` and the invitation's ID; the invitation itself stays `invited`, so the
 * Admin retries with Resend. A failed request sends nothing.
 */
final class SendInvitationsAfterCommit
{
    public function __construct(private readonly QueuedInvitationCourier $courier, private readonly RequestContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->courier->activate();

        try {
            $response = $next($request);

            // Only a successful answer whose transaction has committed sends mail.
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                return $response;
            }

            if (DB::transactionLevel() !== 0) {
                Log::error('identity.invitation.flush_inside_transaction');

                return $response;
            }

            try {
                $this->courier->flush();
            } catch (InvitationDeliveryFailed $failed) {
                return response()->json([
                    'error' => [
                        'code' => ErrorCode::InvitationDeliveryFailed->value,
                        'message' => 'The invitation was saved but its email could not be sent.',
                        'request_id' => $request->attributes->get('request_id') ?? $this->context->requestId(),
                    ],
                    'invitation_id' => $failed->invitationId,
                ], 503, ['Cache-Control' => 'no-store, private']);
            }

            return $response;
        } finally {
            $this->courier->discard();
        }
    }
}
