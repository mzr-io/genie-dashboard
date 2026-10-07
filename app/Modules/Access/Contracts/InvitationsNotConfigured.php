<?php

namespace App\Modules\Access\Contracts;

use RuntimeException;

/** The invitation lifetime tunable is unset, so no invitation can be created (`access.invitations_not_configured`, HTTP 422). */
final class InvitationsNotConfigured extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Invitations are not configured: the invitation lifetime is not set.');
    }
}
