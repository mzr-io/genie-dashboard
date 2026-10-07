<?php

namespace App\Platform\Operations;

use InvalidArgumentException;

/**
 * What a handler reports: whether the Operation succeeded and a small summary. The summary holds scalars only (strings of at
 * most {@see self::VALUE_MAX} characters, integers, booleans, null) under lower-case snake_case keys, so a body, a list or a
 * secret cannot be carried in it by accident.
 */
final readonly class OperationOutcome
{
    public const VALUE_MAX = 160;

    public const KEY = '/\A[a-z][a-z0-9_]{0,31}\z/D';

    /** @param  array<string, string|int|bool|null>  $summary */
    public function __construct(
        public bool $succeeded,
        public array $summary,
    ) {
        self::assertSummary($summary);
    }

    /**
     * @param  array<mixed>  $summary
     *
     * @throws InvalidArgumentException
     */
    public static function assertSummary(array $summary): void
    {
        foreach ($summary as $key => $value) {
            if (! is_string($key) || preg_match(self::KEY, $key) !== 1) {
                throw new InvalidArgumentException('An Operation summary key must be a lower-case snake_case name.');
            }

            if (! (is_null($value) || is_bool($value) || is_int($value) || (is_string($value) && mb_strlen($value) <= self::VALUE_MAX))) {
                throw new InvalidArgumentException("The Operation summary field {$key} must be a short string, an integer, a boolean or null.");
            }
        }
    }
}
