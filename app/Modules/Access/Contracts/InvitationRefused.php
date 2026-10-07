<?php

namespace App\Modules\Access\Contracts;

use RuntimeException;

/** The email cannot be invited: it already belongs to a member of the Workspace, or already has a pending invitation. */
final class InvitationRefused extends RuntimeException
{
    public const MEMBER = 'member';

    public const PENDING = 'pending';

    /**
     * @param  string  $reason  `member` or `pending`
     * @param  string|null  $invitationId  the pending invitation (the Admin may Resend it)
     */
    public function __construct(public readonly string $reason, public readonly ?string $invitationId = null)
    {
        parent::__construct('invitation_refused:'.$reason);
    }
}
