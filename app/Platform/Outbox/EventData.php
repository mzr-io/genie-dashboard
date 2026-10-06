<?php

namespace App\Platform\Outbox;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Validation of the values that reach the outbox and the audit log. Event data holds integers,
 * booleans, null, UUID strings and short enum-style slugs only: free text, floats, lists and objects
 * are refused, so no raw value or secret can travel in an envelope.
 */
final class EventData
{
    public const SLUG = '/\A[a-z][a-z0-9_.-]{0,47}\z/D';

    public const OPERATOR_ACTOR = '/\Aoperator:[a-z0-9_.-]{1,40}\z/D';

    public const KEY = '/\A[a-z][a-z0-9_]{0,47}\z/D';

    /** `{noun}:{id}`, where the ID is a UUID or an integer: `membership:018f...`. */
    public const SUBJECT = '/\A[a-z][a-z0-9_]{0,26}:[0-9a-fA-F-]{1,36}\z/D';

    public const COLUMN = 64;

    /**
     * @param  array<mixed>  $data
     * @return array<string, int|bool|string|null>
     */
    public static function data(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (! is_string($key) || preg_match(self::KEY, $key) !== 1) {
                throw new InvalidArgumentException('Event data keys must be lowercase snake_case names.');
            }

            if (! self::isAllowedValue($value)) {
                throw new InvalidArgumentException("Event data field {$key} must be an integer, boolean, null, UUID or short slug.");
            }

            $clean[$key] = is_string($value) && Str::isUuid($value) ? strtolower($value) : $value;
        }

        return $clean;
    }

    public static function subject(string $subject): string
    {
        [, $id] = array_pad(explode(':', $subject, 2), 2, '');

        if (preg_match(self::SUBJECT, $subject) !== 1 || (! Str::isUuid($id) && preg_match('/\A[0-9]{1,18}\z/', $id) !== 1)) {
            throw new InvalidArgumentException('An event subject must look like `noun:id` with a UUID or integer ID.');
        }

        return strtolower($subject);
    }

    /** A request ID that fits its `varchar(64)` column: an over-long one is cut, never allowed to abort the transaction. */
    public static function requestId(?string $requestId): ?string
    {
        return $requestId === null ? null : substr($requestId, 0, self::COLUMN);
    }

    public static function actor(?string $actor): ?string
    {
        if ($actor === null) {
            return null;
        }

        if (Str::isUuid($actor)) {
            return strtolower($actor);
        }

        // `operator:<os user>`: who ran an operator command.
        if (preg_match(self::SLUG, $actor) !== 1 && preg_match(self::OPERATOR_ACTOR, $actor) !== 1) {
            throw new InvalidArgumentException('An actor must be a UUID, a short slug or operator:<user>.');
        }

        return $actor;
    }

    private static function isAllowedValue(mixed $value): bool
    {
        if ($value === null || is_int($value) || is_bool($value)) {
            return true;
        }

        return is_string($value) && (Str::isUuid($value) || preg_match(self::SLUG, $value) === 1);
    }
}
