<?php

namespace App\Modules\Access\Infrastructure;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A user's membership of a Workspace: role, status and last activity. A tenant table protected by
 * row-level security, so it is only readable and writable inside a WorkspaceTransaction.
 * The UUIDv7 key is generated here.
 *
 * @property string $id
 * @property string $workspace_id
 * @property int $user_id
 * @property string $role
 * @property string $status
 * @property Carbon|null $last_active_at
 */
#[Fillable(['workspace_id', 'user_id', 'role', 'status', 'last_active_at'])]
class WorkspaceMembership extends Model
{
    use HasUuids;

    protected $table = 'workspace_memberships';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_active_at' => 'datetime'];
    }
}
