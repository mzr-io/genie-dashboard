<?php

namespace App\Modules\Access\Application;

/** Why an `access.membership.invited` event was written: the closed set behind its `reason` field. */
enum InvitationAction: string
{
    case Created = 'created';
    case Resent = 'resent';
}
