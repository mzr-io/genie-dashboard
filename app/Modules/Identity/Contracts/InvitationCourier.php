<?php

namespace App\Modules\Identity\Contracts;

use DateTimeInterface;

/**
 * Creates invitation tokens and emails invitation links for Admin invitations (Story 1.21). Mail is never sent
 * inside the database transaction: `deliverAfterCommit` only queues the message, and the API sends it once the
 * request's transaction has committed (nothing is sent when the request fails and rolls back).
 */
interface InvitationCourier
{
    /** A new 256-bit token with its SHA-256 hash. */
    public function newSecret(): InvitationSecret;

    /**
     * Queues the invitation email to be sent after the transaction commits.
     *
     * @param  string  $invitationId  the invitation the link belongs to (named in a delivery failure)
     * @param  string  $role  `user` or `admin`
     */
    public function deliverAfterCommit(string $invitationId, string $email, InvitationSecret $secret, string $workspaceName, string $role, DateTimeInterface $expiresAt): void;
}
