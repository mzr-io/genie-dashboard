<?php

namespace App\Platform\Audit;

/** How an audited field is stored: IDs and enums plain, everything else as a keyed hash. */
enum AuditField
{
    /** A UUID or an integer ID, stored as is. */
    case Id;

    /** A short enum-style slug, stored as is. */
    case Enum;

    /** Any other attribute, header or sample value: stored as an HMAC-SHA256 hash, never raw. */
    case Hashed;
}
