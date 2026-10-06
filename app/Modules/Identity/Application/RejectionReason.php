<?php

namespace App\Modules\Identity\Application;

/** Why an invitation was refused. Audited as an enum slug; never shown to the visitor. */
enum RejectionReason: string
{
    case Unknown = 'unknown';
    case Used = 'used';
    case Expired = 'expired';
    case EmailMismatch = 'email_mismatch';
    case UnsupportedRole = 'unsupported_role';
    case MembershipInactive = 'membership_inactive';
}
