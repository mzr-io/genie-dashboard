<?php

namespace Tests\Security\Support;

/**
 * Reads the Pest test definitions of a file with the PHP tokenizer: the full title is the first string
 * argument of `it()` or `test()` (on one line or several), comments and strings holding code are ignored,
 * and the chained modifiers are returned so a skipped test does not count as proof.
 */
final class PestTitles
{
    /** @return list<array{title: string, chain: list<string>}> */
    public static function in(string $path): array
    {
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents($path)),
            fn ($token) => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $found = [];

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_STRING || ! in_array(strtolower($token[1]), ['it', 'test'], true)) {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;

            if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                continue;
            }

            if (($tokens[$i + 1] ?? null) !== '(' || ! is_array($tokens[$i + 2] ?? null) || $tokens[$i + 2][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $end = self::closing($tokens, $i + 1);
            $chain = [];

            while (($tokens[$end + 1] ?? null) !== null && is_array($tokens[$end + 1]) && $tokens[$end + 1][0] === T_OBJECT_OPERATOR && is_array($tokens[$end + 2] ?? null)) {
                $chain[] = strtolower($tokens[$end + 2][1]);
                $end = ($tokens[$end + 3] ?? null) === '(' ? self::closing($tokens, $end + 3) : $end + 2;
            }

            $found[] = ['title' => self::unquote($tokens[$i + 2][1]), 'chain' => $chain];
            $i = $end;
        }

        return $found;
    }

    /** @param  list<mixed>  $tokens */
    private static function closing(array $tokens, int $open): int
    {
        $depth = 0;

        for ($i = $open, $count = count($tokens); $i < $count; $i++) {
            $depth += match ($tokens[$i]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            if ($depth === 0) {
                return $i;
            }
        }

        return $open;
    }

    private static function unquote(string $literal): string
    {
        $inner = substr($literal, 1, -1);

        return $literal[0] === "'"
            ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner)
            : stripcslashes($inner);
    }
}
