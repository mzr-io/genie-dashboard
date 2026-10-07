<?php

namespace App\Modules\Identity\Contracts;

use RuntimeException;

/** `dashflow.tunables.users.invitation_lifetime` is `pending_input`: it fails closed until the environment sets it. */
final class InvitationLifetimeUnset extends RuntimeException
{
    /** The longest lifetime accepted, in hours (one year). */
    public const MAX_HOURS = 8760;

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'The invitation lifetime is not set. Set DASHFLOW_INVITATION_LIFETIME to a whole number of hours (tunable users.invitation_lifetime).');
    }
}
