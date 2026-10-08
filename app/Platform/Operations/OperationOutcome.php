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

    /**
     * `$stale` is for a result whose subject changed while the handler ran (Story 2.10): the Operation ends as
     * {@see OperationStatus::Stale}, never as a success.
     *
     * @param  array<string, string|int|bool|null>  $summary
     */
    public function __construct(
        public bool $succeeded,
        public array $summary,
        public bool $stale = false,
    ) {
        self::assertSummary($summary);

        if ($stale && $succeeded) {
            throw new InvalidArgumentException('A stale outcome is not a success.');
        }
    }

    /** @param  array<string, string|int|bool|null>  $summary */
    public static function stale(array $summary = []): self
    {
        return new self(false, $summary, true);
    }

    public function status(): OperationStatus
    {
        return $this->stale ? OperationStatus::Stale : ($this->succeeded ? OperationStatus::Succeeded : OperationStatus::Failed);
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
