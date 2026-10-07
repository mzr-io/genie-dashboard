<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Contracts\ErrorCode;
use RuntimeException;

/** The one neutral failure for an unknown, used, expired, tampered or wrongly-addressed invitation. */
final class InvitationRejected extends RuntimeException
{
    public function __construct(public readonly RejectionReason $reason, public readonly ?string $invitationId = null, public readonly ?string $workspaceId = null)
    {
        parent::__construct(ErrorCode::InvitationInvalid->value);
    }
}
