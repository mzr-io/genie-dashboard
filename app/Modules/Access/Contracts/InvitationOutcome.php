<?php

namespace App\Modules\Access\Contracts;

/** A pending invitation after it was created or re-sent. It carries no token and no hash. */
final readonly class InvitationOutcome
{
    /**
     * @param  string  $role  `user` or `admin`
     */
    public function __construct(
        public string $invitationId,
        public string $email,
        public string $role,
        public string $status = 'invited',
    ) {}
}
