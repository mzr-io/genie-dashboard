<?php

namespace App\Platform\Audit;

/** How an audited field is stored: IDs and enums plain, everything else as a keyed hash. */
enum AuditField
{
    /** A UUID or an integer ID, stored as is. */
    case Id;

    /** A short enum-style slug, stored as is. */
    case Enum;

    /** A non-negative integer count, stored as is. */
    case Count;

    /** A list of short enum-style slugs (for example permission names), stored as is, sorted. */
    case EnumList;

    /** Any other attribute, header or sample value: stored as an HMAC-SHA256 hash, never raw. */
    case Hashed;
}
