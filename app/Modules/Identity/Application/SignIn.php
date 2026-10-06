<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Contracts\SignInArea;
use App\Modules\Identity\Contracts\SignInMembership;
use App\Modules\Identity\Contracts\SignInMemberships;
use App\Modules\Identity\Contracts\SignInMessage;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Role-aware sign-in. Authenticates (Fortify's hashing; the same refusal for an unknown email and a wrong
 * password, with a hash comparison on both paths), picks the active Workspace (latest `last_active_at`, else
 * first by name), checks the chosen area against the role there, then rotates the session ID and stores
 * `workspace_id` and `area` in it. Every outcome is recorded by SignInRecorder.
 *
 * A person with no usable membership cannot sign in to either area and gets the neutral failure.
 */
final class SignIn
{
    /** Stands in for a user's hash when the email is unknown, so both paths cost one hash comparison. */
    private static ?string $dummyHash = null;

    public function __construct(
        private readonly StatefulGuard $guard,
        private readonly SignInMemberships $memberships,
        private readonly SignInRecorder $recorder,
    ) {}

    /** Longest email (RFC 5321) and password accepted before any hashing happens. */
    public const MAX_EMAIL = 254;

    public const MAX_PASSWORD = 1024;

    /**
     * @throws SignInRefused
     */
    public function handle(Request $request, string $email, string $password, SignInArea $area): SignInMembership
    {
        // No session, no sign-in; oversized input is refused before it reaches a hash.
        if (! $request->hasSession() || strlen($email) > self::MAX_EMAIL || strlen($password) > self::MAX_PASSWORD) {
            $this->recorder->failed(null, 'invalid_input');

            throw new SignInRefused(SignInMessage::Failed);
        }

        $user = User::query()->whereRaw('lower(email) = ?', [strtolower($email)])->first();

        $valid = Hash::check($password, $user instanceof User ? $user->password : self::dummyHash());

        if (! $user instanceof User || ! $valid) {
            $this->recorder->failed($user, $user instanceof User ? 'bad_password' : 'unknown_user', $user instanceof User ? $this->activeMembership($user) : null);

            throw new SignInRefused(SignInMessage::Failed);
        }

        $memberships = $this->memberships->forUser($user->id);
        $active = SignInMembership::active($memberships);

        if ($active === null) {
            $this->recorder->failed($user, 'no_membership');

            throw new SignInRefused(SignInMessage::Failed);
        }

        // The Admin card lands in the most recently used usable Admin membership; none: refused.
        $membership = $area === SignInArea::Admin
            ? SignInMembership::active(array_values(array_filter($memberships, fn (SignInMembership $m): bool => $m->isAdmin())))
            : $active;

        if ($membership === null) {
            $this->recorder->areaDenied($user, $active, $area);

            throw new SignInRefused(SignInMessage::RoleDenied);
        }

        // Everything that can fail short of the session itself comes first.
        $this->memberships->markActive($membership->workspaceId, $membership->membershipId);

        $this->start($request, $user, $membership, $area);
        $this->recorder->succeeded($user, $membership, $area);

        return $membership;
    }

    private function activeMembership(User $user): ?SignInMembership
    {
        return SignInMembership::active($this->memberships->forUser($user->id));
    }

    private function start(Request $request, User $user, SignInMembership $membership, SignInArea $area): void
    {
        // Remember me lasts up to the configured maximum; while it is unset it adds nothing.
        $minutes = SignInLimits::rememberMinutes();
        $remember = $request->boolean('remember') && $minutes !== null;

        if ($remember && $this->guard instanceof SessionGuard) {
            $this->guard->setRememberDuration($minutes);
        }

        $this->guard->login($user, $remember);

        try {
            $session = $request->session();
            // A previous person's Workspace and area must not survive the regeneration.
            $session->forget([WorkspaceTransaction::SESSION_KEY, 'area']);
            $session->regenerate();
            $session->put(WorkspaceTransaction::SESSION_KEY, $membership->workspaceId);
            $session->put('area', $area->value);
        } catch (Throwable $e) {
            $this->guard->logout();
            $request->session()->invalidate();

            throw $e;
        }
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make('dashflow-dummy-password');
    }
}
