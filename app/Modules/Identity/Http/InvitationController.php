<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\AcceptInvitation;
use App\Modules\Identity\Application\InvitationRejected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The invitation-accept pages. Every refusal is the same neutral page with the same status (410), so a
 * visitor cannot tell an unknown link from a used, expired or wrongly-addressed one.
 */
final class InvitationController
{
    public const EXPIRED_STATUS = 410;

    public function __construct(private readonly AcceptInvitation $invitations) {}

    public function show(string $token): InertiaResponse|SymfonyResponse
    {
        try {
            $this->invitations->assertOpen($token);
        } catch (InvitationRejected) {
            return $this->expired();
        }

        return Inertia::render('auth/AcceptInvitation', [
            'token' => $token,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(AcceptInvitationRequest $request, string $token): RedirectResponse|SymfonyResponse
    {
        /** @var array{email: string, name: string, password: string} $data */
        $data = $request->validated();

        try {
            $this->invitations->handle($token, $data['email'], $data['name'], $data['password']);
        } catch (InvitationRejected) {
            // An Inertia visit cannot show a 4xx page inline: send it to the neutral page as a full visit.
            return $request->header('X-Inertia')
                ? Inertia::location(route('invitations.expired'))
                : $this->expired();
        }

        return to_route('login');
    }

    /** The neutral page: also the target of the full-page visit above. */
    public function expired(): SymfonyResponse
    {
        return Inertia::render('auth/InvitationExpired')->toResponse(request())->setStatusCode(self::EXPIRED_STATUS);
    }
}
