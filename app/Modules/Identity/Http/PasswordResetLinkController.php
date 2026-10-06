<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\ResetLimits;
use App\Modules\Identity\Application\ResetLinks;
use App\Modules\Identity\Application\SignIn;
use App\Modules\Identity\Contracts\PasswordResetMessage;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController as FortifyPasswordResetLinkController;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Fortify's reset-link request, wrapped. A well-formed email always gets the same `reset-requested`
 * answer: unknown account, a per-user resend wait, or a failed mail all look alike, and a reset email is
 * sent only when the account exists. A failed mail is logged with the exception class only, never the
 * address. Requests are throttled per email and IP (HTTP 429, `throttled`).
 */
final class PasswordResetLinkController extends FortifyPasswordResetLinkController
{
    public function __construct()
    {
        // The reset page is reached from an emailed link: no caching, no Referer.
        $this->middleware(InvitationResponseHeaders::class);
        $this->middleware('throttle:'.ResetRequestThrottle::NAME)->only('store');
    }

    /** Validated here (not by Fortify's form request), so a malformed email answers with the catalogue key. */
    public function store(Request $request): Responsable
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:'.SignIn::MAX_EMAIL]], [
            'email.required' => PasswordResetMessage::FieldError->value,
            'email.string' => PasswordResetMessage::FieldError->value,
            'email.email' => PasswordResetMessage::FieldError->value,
            'email.max' => PasswordResetMessage::FieldError->value,
        ]);

        try {
            // The broker answers an unknown email, a resend inside the per-user wait and a sent link
            // alike here: nothing in the answer depends on whether the account exists.
            $email = (string) $request->input('email');
            $this->broker()->sendResetLink(['email' => ResetLinks::user($email)->email ?? $email]);
        } catch (Throwable $e) {
            try {
                Log::error('identity.password_reset.mail_failed', ['exception' => $e::class]);
            } catch (Throwable) {
            }
        }

        return new ResetReply(fn (): Response => $request->wantsJson()
            ? new JsonResponse(['message' => PasswordResetMessage::Requested->value], 200)
            : back()->with('status', PasswordResetMessage::Requested->value));
    }

    protected function broker(): PasswordBroker
    {
        ResetLimits::applyLifetime();

        return parent::broker();
    }
}
