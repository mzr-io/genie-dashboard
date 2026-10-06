<?php

namespace App\Modules\Access\Infrastructure;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One Admin permission held by a membership. A tenant table protected by row-level security.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $membership_id
 * @property string $permission
 */
#[Fillable(['workspace_id', 'membership_id', 'permission'])]
class MembershipPermission extends Model
{
    use HasUuids;

    protected $table = 'membership_permissions';
}
