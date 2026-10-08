<?php

namespace App\Platform\Json;

use InvalidArgumentException;
use Stringable;

/**
 * A JSON number kept exactly as it was received (Story 2.6): `1.10` stays `1.10`, `12345678901234567890.12` loses no digit.
 * It holds the lexeme and nothing else, never a float, so a total built from it cannot be silently wrong. Two literals are
 * equal when their lexemes are: `1.10` and `1.1` are different literals (the caller that wants numeric equality compares
 * values with a decimal library, not here).
 */
final readonly class DecimalLiteral implements Stringable
{
    private const GRAMMAR = '/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?\z/D';

    public string $lexeme;

    public function __construct(string $lexeme)
    {
        if (preg_match(self::GRAMMAR, $lexeme) !== 1) {
            throw new InvalidArgumentException('Not a JSON number.');
        }

        $this->lexeme = $lexeme;
    }

    public function __toString(): string
    {
        return $this->lexeme;
    }

    /** True when the lexeme is a whole number as written: digits with an optional minus, no fraction and no exponent. */
    public function isInteger(): bool
    {
        return preg_match('/\A-?[0-9]+\z/D', $this->lexeme) === 1;
    }

    /** Equality on the lexeme. */
    public function equals(self $other): bool
    {
        return $this->lexeme === $other->lexeme;
    }
}
