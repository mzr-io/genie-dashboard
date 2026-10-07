<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Contracts\UserMembership;
use App\Modules\Identity\Infrastructure\AvatarStore;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `GET /avatars/{user}`: a profile picture. Only its owner and people who share an active Workspace
 * membership with the owner (both memberships and the Workspace active, read through the Access lookup) may
 * fetch it; anyone else gets a 404, never a 403, so the route does not confirm that a user or picture exists.
 * The `Content-Type` comes from the stored type (never from the bytes or the request), `X-Content-Type-Options:
 * nosniff` stops a browser guessing another one, and another person's picture is never cached. A missing
 * file or picture is a 404.
 */
final class AvatarController extends Controller
{
    public function __invoke(Request $request, User $user, AvatarStore $avatars, MembershipLookup $memberships): Response
    {
        $viewer = $request->user();
        $own = $viewer instanceof User && $viewer->is($user);

        abort_unless($own || ($viewer instanceof User && $this->sharesWorkspace($memberships, $viewer->id, $user->id)), 404);

        $name = $user->avatar_path;
        $type = is_string($name) ? AvatarStore::contentType($name) : null;

        abort_if($type === null, 404);

        $bytes = $avatars->read($name);

        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => $type,
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'inline; filename="avatar.'.AvatarStore::TYPES[$type].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            // The address carries a version, so a person's own picture may be kept; nobody else's is.
            'Cache-Control' => $own ? 'private, max-age=86400' : 'no-store, private',
        ]);
    }

    private function sharesWorkspace(MembershipLookup $memberships, int $viewerId, int $ownerId): bool
    {
        try {
            $mine = $this->activeWorkspaces($memberships->forUser($viewerId));

            return array_intersect($mine, $this->activeWorkspaces($memberships->forUser($ownerId))) !== [];
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<UserMembership>  $all
     * @return list<string>
     */
    private function activeWorkspaces(array $all): array
    {
        $ids = [];

        foreach ($all as $membership) {
            if ($membership->status === 'active' && $membership->workspaceStatus === 'active') {
                $ids[] = $membership->workspaceId;
            }
        }

        return $ids;
    }
}
