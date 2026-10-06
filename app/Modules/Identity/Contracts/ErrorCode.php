<?php

namespace App\Modules\Identity\Contracts;

/**
 * Error codes owned by Identity: `identity.{snake_case}`. A generated TypeScript enum
 * (`resources/js/types/error-codes.ts`) mirrors every module's codes.
 */
enum ErrorCode: string
{
    /** An unknown, used, expired or tampered invitation link, or a wrong email: one neutral answer for all. */
    case InvitationInvalid = 'identity.invitation_invalid';
}
