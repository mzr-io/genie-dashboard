<?php

namespace App\Http\Resources;

use App\Modules\Access\Contracts\GroupRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A group with its members. It lists only the named fields; a member shows name, email and status (a deactivated
 * member keeps their place and is reported as `deactivated`).
 *
 * @property GroupRow $resource
 */
final class GroupResource extends JsonResource
{
    /**
     * @return array<string, mixed> {group_id, name, member_count, members: list<{membership_id, name, email, status}>, created_at, updated_at}
     */
    public function toArray(Request $request): array
    {
        return [
            'group_id' => $this->resource->id,
            'name' => $this->resource->name,
            'member_count' => $this->resource->memberCount,
            'members' => array_map(fn ($member): array => [
                'membership_id' => $member->membershipId,
                'name' => $member->name,
                'email' => $member->email,
                'status' => $member->status,
            ], $this->resource->members),
            'created_at' => $this->resource->createdAt,
            'updated_at' => $this->resource->updatedAt,
        ];
    }
}
