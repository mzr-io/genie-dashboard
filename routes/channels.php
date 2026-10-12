<?php

use App\Models\User;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Contracts\RegisterAccessSubject;
use App\Modules\Access\Contracts\SubjectAccess;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\Broadcast;

// Realtime channels (Story 4.13; AD-16, AR-23). Both are private and both re-check the database on every authorisation: the person must have an
// active membership in the channel's Workspace, which must be the session's, and for a Block channel that membership must still be able to see
// the Block. A refused authorisation is a 403 and the browser falls back to polling `/api/v1`.
$membership = function (User $user, string $workspace): ?string {
    $session = request()->hasSession() ? request()->session()->get(WorkspaceTransaction::SESSION_KEY) : null;

    if (! is_string($session) || strtolower($session) !== strtolower($workspace)) {
        return null;
    }

    foreach (app(MembershipLookup::class)->forUser($user->id) as $candidate) {
        if ($candidate->workspaceId === strtolower($workspace) && $candidate->status === 'active') {
            return $candidate->membershipId;
        }
    }

    return null;
};

Broadcast::channel('workspace.{workspace}.block.{block}', function (User $user, string $workspace, string $block) use ($membership): bool {
    $member = $membership($user, $workspace);

    return $member !== null
        && (app(SubjectAccess::class)->canView(strtolower($workspace), $member, RegisterAccessSubject::BLOCK, [strtolower($block)])[strtolower($block)] ?? false);
});

Broadcast::channel('workspace.{workspace}.membership.{membership}', function (User $user, string $workspace, string $target) use ($membership): bool {
    $member = $membership($user, $workspace);

    return $member !== null && strtolower($member) === strtolower($target);
});
