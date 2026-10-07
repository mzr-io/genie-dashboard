<?php

namespace App\Modules\Identity\Contracts;

use Illuminate\Database\ConnectionInterface;

/**
 * Creates a hashed, email-bound, single-use invitation and emails the link. Used by the operator
 * command, on the `operator` connection. The plain token exists only in the emailed link.
 */
interface InvitationIssuer
{
    /**
     * Stores the invitation on `$connection` and sends the email; returns the invitation ID.
     *
     * @throws InvitationLifetimeUnset when the lifetime tunable has no value
     */
    public function issue(ConnectionInterface $connection, string $workspaceId, string $workspaceName, string $email, string $createdBy): string;

    /** The configured lifetime in whole hours, or an exception while the tunable is unset. */
    public function lifetimeHours(): int;
}
