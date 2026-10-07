<?php

namespace App\Modules\Identity\Contracts;

use RuntimeException;

/** An invitation email could not be sent after the commit. The invitation stays `invited`, so Resend retries it. */
final class InvitationDeliveryFailed extends RuntimeException
{
    /** @param  string  $invitationId  the invitation whose email failed (the first one, when several) */
    public function __construct(public readonly string $invitationId)
    {
        // No address, no token: the ID only.
        parent::__construct('The invitation email could not be sent.');
    }
}
