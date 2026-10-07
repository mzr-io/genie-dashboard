<?php

namespace App\Modules\Access\Contracts;

use RuntimeException;

/** The inviter asked to hand out permissions they do not hold themselves (`access.permission_not_held`, HTTP 403). */
final class PermissionNotHeld extends RuntimeException
{
    /** @param  list<string>  $permissions  the permissions the inviter does not hold */
    public function __construct(public readonly array $permissions)
    {
        parent::__construct(ErrorCode::PermissionNotHeld->value);
    }
}
