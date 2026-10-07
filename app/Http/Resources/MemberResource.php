<?php

namespace App\Http\Resources;

use App\Modules\Access\Contracts\MemberRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member or pending invitation of the Workspace. It lists only the named fields, so nothing else (a password
 * hash, a token, a remember token) can reach a response.
 *
 * @property MemberRow $resource
 */
final class MemberResource extends JsonResource
{
    /**
     * @return array<string, mixed> `kind` is `member` or `invitation`; a member has `membership_id`, an invitation `invitation_id` (never both). Shape: {kind, membership_id|invitation_id, name: string, email: string, role: string, status: string, groups: list<string>, last_active_at: string|null, permissions?: list<string>, revision?: int} (the last two for a member only)
     */
    public function toArray(Request $request): array
    {
        $isMember = $this->resource->kind === MemberRow::MEMBER;

        return [
            'kind' => $this->resource->kind,
            ...($isMember ? ['membership_id' => $this->resource->id] : ['invitation_id' => $this->resource->id]),
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'role' => $this->resource->role,
            'status' => $this->resource->status,
            'groups' => $this->resource->groups,
            'last_active_at' => $this->resource->lastActiveAt,
            // Story 1.22: what the Roles & permissions editor needs; an invitation has neither.
            ...($isMember ? ['permissions' => $this->resource->permissions, 'revision' => $this->resource->revision] : []),
        ];
    }
}
