<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/**
 * A Fetch as user (Story 2.13) cannot be sent because a value bound to the member could not be produced: a value is missing (an
 * attribute not set, or zero or several groups for `user_group`) or a resolved value does not fit where it goes (a header with CR/LF
 * or a non-visible character, a path value with `/`, `.` or `..`). Nothing was sent. The message and `missing` hold attribute key ids
 * and binding kinds only, never a value.
 */
final class UserContextUnresolved extends RuntimeException
{
    public const MISSING = 'context_missing';

    public const INVALID = 'context_value_invalid';

    /**
     * @param  string  $reason  {@see self::MISSING} or {@see self::INVALID}
     * @param  list<string>  $missing  the attribute key ids (and `user_group`) without a value
     */
    public function __construct(public readonly string $reason, public readonly array $missing = [])
    {
        parent::__construct("The user context cannot be resolved ({$reason}).");
    }
}
