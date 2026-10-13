<?php

namespace App\Modules\Mapping\Application\Expressions;

use App\Modules\Mapping\Contracts\InvalidExpression;

/**
 * The one expression grammar (Story 5.3, AD-12), shared with Text Templates and URL templates: arithmetic (`+ - * /`), comparisons (`= <> < <= > >=`),
 * `AND`, `OR`, `NOT`, `IF`, `ROUND`, `ABS`, `PCT_CHANGE` and the date parts `YEAR`, `MONTH`, `DAY` and `QUARTER`. A field is a bare name (`revenue`,
 * `region.name`) or a bracketed one (`[net revenue]`). There is no other function, no assignment, no loop and no way to call anything: the parser makes a
 * tree of data and {@see ExpressionEvaluator} walks it.
 *
 * Node: `{t: num, v}`, `{t: str, v}`, `{t: bool, v}`, `{t: null}`, `{t: field, name, path?}`, `{t: neg, x}`, `{t: not, x}`, `{t: bin, op, l, r}` or
 * `{t: call, fn, args}`; each carries `p`, the 1-based position it starts at.
 */
final class ExpressionParser
{
    /** The closed list of functions with their argument counts. */
    public const FUNCTIONS = [
        'IF' => [3, 3],
        'ROUND' => [1, 2],
        'ABS' => [1, 1],
        'PCT_CHANGE' => [2, 2],
        'YEAR' => [1, 1],
        'MONTH' => [1, 1],
        'DAY' => [1, 1],
        'QUARTER' => [1, 1],
    ];

    public const MAX_LENGTH = 500;

    private const MAX_DEPTH = 20;

    private const MAX_NODES = 100;

    /** @var list<array{type: string, value: string, pos: int}> */
    private array $tokens = [];

    private int $at = 0;

    private int $nodes = 0;

    private int $depth = 0;

    /**
     * @return array<string, mixed> the tree
     *
     * @throws InvalidExpression
     */
    public static function parse(string $source): array
    {
        $parser = new self;

        return $parser->run($source);
    }

    /** @return array<string, mixed> */
    private function run(string $source): array
    {
        if (trim($source) === '') {
            throw new InvalidExpression('Enter an expression.', 1);
        }

        if (mb_strlen($source) > self::MAX_LENGTH) {
            throw new InvalidExpression('Use '.self::MAX_LENGTH.' characters or fewer.', self::MAX_LENGTH + 1);
        }

        $this->tokens = $this->lex($source);
        $this->at = 0;

        $tree = $this->or();
        $token = $this->peek();

        if ($token['type'] !== 'end') {
            throw new InvalidExpression("Unexpected {$this->describe($token)}.", $token['pos']);
        }

        return $tree;
    }

    /** @return list<array{type: string, value: string, pos: int}> */
    private function lex(string $source): array
    {
        $out = [];
        $length = mb_strlen($source);
        $i = 0;

        while ($i < $length) {
            $c = mb_substr($source, $i, 1);
            $pos = $i + 1;

            if (ctype_space($c)) {
                $i++;

                continue;
            }

            if (preg_match('/[\p{Cc}\p{Cf}]/u', $c) === 1) {
                throw new InvalidExpression('A control character is not allowed.', $pos);
            }

            if (ctype_digit($c) || ($c === '.' && ctype_digit(mb_substr($source, $i + 1, 1)))) {
                $start = $i;

                while ($i < $length && ctype_digit(mb_substr($source, $i, 1))) {
                    $i++;
                }

                if ($i < $length && mb_substr($source, $i, 1) === '.') {
                    $i++;

                    while ($i < $length && ctype_digit(mb_substr($source, $i, 1))) {
                        $i++;
                    }
                }

                $out[] = ['type' => 'num', 'value' => mb_substr($source, $start, $i - $start), 'pos' => $pos];

                continue;
            }

            if ($c === "'" || $c === '"') {
                $start = $i + 1;
                $i++;

                while ($i < $length && mb_substr($source, $i, 1) !== $c) {
                    $i++;
                }

                if ($i >= $length) {
                    throw new InvalidExpression('This text is not closed. Add the closing quote.', $pos);
                }

                $out[] = ['type' => 'str', 'value' => mb_substr($source, $start, $i - $start), 'pos' => $pos];
                $i++;

                continue;
            }

            if ($c === '[') {
                $start = $i + 1;
                $i++;

                while ($i < $length && mb_substr($source, $i, 1) !== ']') {
                    $i++;
                }

                if ($i >= $length) {
                    throw new InvalidExpression('This field name is not closed. Add the closing ].', $pos);
                }

                $name = trim(mb_substr($source, $start, $i - $start));

                if ($name === '') {
                    throw new InvalidExpression('Name the field between the brackets.', $pos);
                }

                $out[] = ['type' => 'field', 'value' => $name, 'pos' => $pos];
                $i++;

                continue;
            }

            if (preg_match('/[\p{L}_]/u', $c) === 1) {
                $start = $i;

                while ($i < $length && preg_match('/[\p{L}\p{N}_.]/u', mb_substr($source, $i, 1)) === 1) {
                    $i++;
                }

                $word = rtrim(mb_substr($source, $start, $i - $start), '.');
                $i = $start + mb_strlen($word);
                $upper = strtoupper($word);

                if (in_array($upper, ['AND', 'OR', 'NOT', 'TRUE', 'FALSE', 'NULL'], true)) {
                    $out[] = ['type' => 'kw', 'value' => $upper, 'pos' => $pos];
                } elseif (isset(self::FUNCTIONS[$upper]) && $this->nextIs($source, $i, '(')) {
                    $out[] = ['type' => 'fn', 'value' => $upper, 'pos' => $pos];
                } elseif ($this->nextIs($source, $i, '(')) {
                    throw new InvalidExpression("Unknown function {$word}. Use ".implode(', ', array_keys(self::FUNCTIONS)).'.', $pos);
                } else {
                    $out[] = ['type' => 'field', 'value' => $word, 'pos' => $pos];
                }

                continue;
            }

            $two = mb_substr($source, $i, 2);

            if (in_array($two, ['<>', '<=', '>='], true)) {
                $out[] = ['type' => 'op', 'value' => $two, 'pos' => $pos];
                $i += 2;

                continue;
            }

            if (in_array($c, ['+', '-', '*', '/', '(', ')', ',', '=', '<', '>'], true)) {
                $out[] = ['type' => 'op', 'value' => $c, 'pos' => $pos];
                $i++;

                continue;
            }

            throw new InvalidExpression("Unexpected character {$c}.", $pos);
        }

        $out[] = ['type' => 'end', 'value' => '', 'pos' => $length + 1];

        return $out;
    }

    private function nextIs(string $source, int $i, string $char): bool
    {
        $length = mb_strlen($source);

        while ($i < $length && ctype_space(mb_substr($source, $i, 1))) {
            $i++;
        }

        return $i < $length && mb_substr($source, $i, 1) === $char;
    }

    /** @return array{type: string, value: string, pos: int} */
    private function peek(): array
    {
        return $this->tokens[$this->at];
    }

    /** @return array{type: string, value: string, pos: int} */
    private function take(): array
    {
        return $this->tokens[$this->at++];
    }

    private function isOp(string ...$values): bool
    {
        $t = $this->peek();

        return $t['type'] === 'op' && in_array($t['value'], $values, true);
    }

    private function isKeyword(string $word): bool
    {
        $t = $this->peek();

        return $t['type'] === 'kw' && $t['value'] === $word;
    }

    /** @param  array{type: string, value: string, pos: int}  $token */
    private function describe(array $token): string
    {
        return $token['type'] === 'end' ? 'end of the expression' : "'{$token['value']}'";
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function node(array $node, int $pos): array
    {
        if (++$this->nodes > self::MAX_NODES) {
            throw new InvalidExpression('This expression is too long. Split it into two calculated fields.', $pos);
        }

        return $node + ['p' => $pos];
    }

    /** @return array<string, mixed> */
    private function or(): array
    {
        $left = $this->and();

        while ($this->isKeyword('OR')) {
            $pos = $this->take()['pos'];
            $left = $this->node(['t' => 'bin', 'op' => 'OR', 'l' => $left, 'r' => $this->and()], $pos);
        }

        return $left;
    }

    /** @return array<string, mixed> */
    private function and(): array
    {
        $left = $this->not();

        while ($this->isKeyword('AND')) {
            $pos = $this->take()['pos'];
            $left = $this->node(['t' => 'bin', 'op' => 'AND', 'l' => $left, 'r' => $this->not()], $pos);
        }

        return $left;
    }

    /** @return array<string, mixed> */
    private function not(): array
    {
        if ($this->isKeyword('NOT')) {
            $pos = $this->take()['pos'];

            return $this->node(['t' => 'not', 'x' => $this->not()], $pos);
        }

        return $this->comparison();
    }

    /** @return array<string, mixed> */
    private function comparison(): array
    {
        $left = $this->sum();

        if ($this->isOp('=', '<>', '<', '<=', '>', '>=')) {
            $op = $this->take();

            return $this->node(['t' => 'bin', 'op' => $op['value'], 'l' => $left, 'r' => $this->sum()], $op['pos']);
        }

        return $left;
    }

    /** @return array<string, mixed> */
    private function sum(): array
    {
        $left = $this->product();

        while ($this->isOp('+', '-')) {
            $op = $this->take();
            $left = $this->node(['t' => 'bin', 'op' => $op['value'], 'l' => $left, 'r' => $this->product()], $op['pos']);
        }

        return $left;
    }

    /** @return array<string, mixed> */
    private function product(): array
    {
        $left = $this->unary();

        while ($this->isOp('*', '/')) {
            $op = $this->take();
            $left = $this->node(['t' => 'bin', 'op' => $op['value'], 'l' => $left, 'r' => $this->unary()], $op['pos']);
        }

        return $left;
    }

    /** @return array<string, mixed> */
    private function unary(): array
    {
        if ($this->isOp('-')) {
            $pos = $this->take()['pos'];

            return $this->node(['t' => 'neg', 'x' => $this->unary()], $pos);
        }

        return $this->primary();
    }

    /** @return array<string, mixed> */
    private function primary(): array
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw new InvalidExpression('This expression is nested too deeply.', $this->peek()['pos']);
        }

        $token = $this->take();

        try {
            return match ($token['type']) {
                'num' => $this->node(['t' => 'num', 'v' => $token['value']], $token['pos']),
                'str' => $this->node(['t' => 'str', 'v' => $token['value']], $token['pos']),
                'field' => $this->node(['t' => 'field', 'name' => $token['value']], $token['pos']),
                'kw' => match ($token['value']) {
                    'TRUE' => $this->node(['t' => 'bool', 'v' => true], $token['pos']),
                    'FALSE' => $this->node(['t' => 'bool', 'v' => false], $token['pos']),
                    'NULL' => $this->node(['t' => 'null'], $token['pos']),
                    default => throw new InvalidExpression("Unexpected '{$token['value']}'.", $token['pos']),
                },
                'fn' => $this->call($token),
                'op' => $token['value'] === '(' ? $this->group($token) : throw new InvalidExpression("Unexpected '{$token['value']}'.", $token['pos']),
                default => throw new InvalidExpression('The expression ends too early. Finish it.', $token['pos']),
            };
        } finally {
            $this->depth--;
        }
    }

    /**
     * @param  array{type: string, value: string, pos: int}  $open
     * @return array<string, mixed>
     */
    private function group(array $open): array
    {
        $inner = $this->or();

        if (! $this->isOp(')')) {
            throw new InvalidExpression('Close the parenthesis opened here.', $open['pos']);
        }

        $this->take();

        return $inner;
    }

    /**
     * @param  array{type: string, value: string, pos: int}  $name
     * @return array<string, mixed>
     */
    private function call(array $name): array
    {
        $open = $this->take();

        if ($open['value'] !== '(') {
            throw new InvalidExpression("Open the arguments of {$name['value']} with (.", $open['pos']);
        }

        /** @var list<array<string, mixed>> $args */
        $args = [];

        if (! $this->isOp(')')) {
            do {
                $args[] = $this->or();
                $more = $this->isOp(',');

                if ($more) {
                    $this->take();
                }
            } while ($more);
        }

        if (! $this->isOp(')')) {
            $token = $this->peek();

            throw new InvalidExpression($token['type'] === 'end' ? "Close the parenthesis of {$name['value']}." : "Unexpected {$this->describe($token)}.", $token['pos']);
        }

        $this->take();

        [$min, $max] = self::FUNCTIONS[$name['value']];

        if (count($args) < $min || count($args) > $max) {
            $want = $min === $max ? (string) $min : "{$min} or {$max}";

            throw new InvalidExpression("{$name['value']} takes {$want} ".($want === '1' ? 'argument' : 'arguments').'.', $name['pos']);
        }

        return $this->node(['t' => 'call', 'fn' => $name['value'], 'args' => $args], $name['pos']);
    }
}
