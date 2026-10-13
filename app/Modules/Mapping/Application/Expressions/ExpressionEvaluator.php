<?php

namespace App\Modules\Mapping\Application\Expressions;

use App\Modules\Mapping\Application\DecimalMath;
use App\Modules\Mapping\Contracts\ExecutionResult;
use BcMath\Number;
use Closure;

/**
 * Walks a parsed expression (Story 5.3) with decimal arithmetic at the platform's one scale: no float, no `eval`, no function the grammar does not name.
 * Values are {@see ExpressionNumber}, text, booleans and null. A null reaches the end as null, never 0: arithmetic with a null is null, and a division
 * by 0 or by null is null (safe division), as is PCT_CHANGE with a previous value of 0 or null. Text used as a number, when it is not one, is an
 * {@see ExpressionTypeError}.
 */
final class ExpressionEvaluator
{
    public function __construct(private readonly DecimalMath $math) {}

    /**
     * @param  array<string, mixed>  $node  a resolved tree
     * @param  Closure(string): (string|bool|null|ExpressionNumber)  $field  the value of a stored path in the row
     * @return string|bool|null a decimal string for a number, text, a boolean, or null
     *
     * @throws ExpressionTypeError
     */
    public function evaluate(array $node, Closure $field): string|bool|null
    {
        $value = $this->run($node, $field);

        return $value instanceof ExpressionNumber ? self::trimmed($value->value) : $value;
    }

    /**
     * The value as the engine keeps it: a number stays an {@see ExpressionNumber}, so a column of results keeps numbers apart from text.
     *
     * @param  array<string, mixed>  $node
     * @param  Closure(string): (string|bool|null|ExpressionNumber)  $field
     *
     * @throws ExpressionTypeError
     */
    public function value(array $node, Closure $field): string|bool|null|ExpressionNumber
    {
        return $this->run($node, $field);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  Closure(string): (string|bool|null|ExpressionNumber)  $field
     */
    private function run(array $node, Closure $field): string|bool|null|ExpressionNumber
    {
        switch ($node['t']) {
            case 'num':
                return new ExpressionNumber((string) $node['v']);
            case 'str':
                return (string) $node['v'];
            case 'bool':
                return (bool) $node['v'];
            case 'null':
                return null;
            case 'field':
                return $field((string) ($node['path'] ?? ''));
            case 'neg':
                $x = $this->number($this->run($node['x'], $field));

                return $x === null ? null : new ExpressionNumber($this->math->sub('0', $x));
            case 'not':
                $x = $this->run($node['x'], $field);

                return $x === null ? null : ! $this->truthy($x);
            case 'bin':
                return $this->binary($node, $field);
            case 'call':
                return $this->call($node, $field);
        }

        throw new ExpressionTypeError('Unknown expression node.');
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  Closure(string): (string|bool|null|ExpressionNumber)  $field
     */
    private function binary(array $node, Closure $field): bool|null|ExpressionNumber
    {
        $op = (string) $node['op'];
        $left = $this->run($node['l'], $field);

        if ($op === 'AND' || $op === 'OR') {
            $right = $this->run($node['r'], $field);
            $l = $left === null ? null : $this->truthy($left);
            $r = $right === null ? null : $this->truthy($right);

            if ($op === 'AND') {
                return ($l === false || $r === false) ? false : (($l === null || $r === null) ? null : true);
            }

            return ($l === true || $r === true) ? true : (($l === null || $r === null) ? null : false);
        }

        $right = $this->run($node['r'], $field);

        if (in_array($op, ['+', '-', '*', '/'], true)) {
            $a = $this->number($left);
            $b = $this->number($right);

            if ($a === null || $b === null) {
                return null;
            }

            return match ($op) {
                '+' => new ExpressionNumber($this->math->add($a, $b)),
                '-' => new ExpressionNumber($this->math->sub($a, $b)),
                '*' => new ExpressionNumber($this->math->mul($a, $b)),
                default => $this->divide($a, $b),
            };
        }

        if ($left === null || $right === null) {
            return null;
        }

        $order = $this->compare($left, $right, $op);

        return match ($op) {
            '=' => $order === 0,
            '<>' => $order !== 0,
            '<' => $order < 0,
            '<=' => $order <= 0,
            '>' => $order > 0,
            default => $order >= 0,
        };
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  Closure(string): (string|bool|null|ExpressionNumber)  $field
     */
    private function call(array $node, Closure $field): string|bool|null|ExpressionNumber
    {
        $args = $node['args'];
        $fn = (string) $node['fn'];

        if ($fn === 'IF') {
            $condition = $this->run($args[0], $field);

            return $this->run($condition !== null && $this->truthy($condition) ? $args[1] : $args[2], $field);
        }

        $values = array_map(fn (array $arg): string|bool|null|ExpressionNumber => $this->run($arg, $field), $args);

        switch ($fn) {
            case 'ROUND':
                $x = $this->number($values[0]);

                if ($x === null) {
                    return null;
                }

                $places = isset($values[1]) ? $this->number($values[1]) : '0';

                if ($places === null || preg_match('/\A\d{1,2}\z/', $places) !== 1) {
                    throw new ExpressionTypeError('ROUND needs a whole number of places from 0 to 99.');
                }

                return new ExpressionNumber((string) ($this->num($x))->round((int) $places));
            case 'ABS':
                $x = $this->number($values[0]);

                return $x === null ? null : new ExpressionNumber(str_starts_with($x, '-') ? ltrim($x, '-') : $x);
            case 'PCT_CHANGE':
                $current = $this->number($values[0]);
                $previous = $this->number($values[1]);

                if ($current === null || $previous === null || $this->isZero($previous)) {
                    return null;
                }

                // Scaled before the division, so the platform scale is kept for the percentage rather than for the fraction.
                $difference = $this->ns($this->math->mul($this->ns($this->math->sub($current, $previous)), '100'));

                return new ExpressionNumber($this->math->div($difference, $previous));
            default:
                return $this->datePart($fn, $values[0]);
        }
    }

    private function datePart(string $fn, string|bool|null|ExpressionNumber $value): ?ExpressionNumber
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || preg_match('/\A(\d{4})-(\d{2})-(\d{2})/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        $part = match ($fn) {
            'YEAR' => (int) $m[1],
            'MONTH' => (int) $m[2],
            'DAY' => (int) $m[3],
            default => (int) ceil(((int) $m[2]) / 3),
        };

        return new ExpressionNumber((string) $part);
    }

    /**
     * @param  numeric-string  $a
     * @param  numeric-string  $b
     */
    private function divide(string $a, string $b): ?ExpressionNumber
    {
        return $this->isZero($b) ? null : new ExpressionNumber($this->math->div($a, $b));
    }

    private function num(string $digits): Number
    {
        /** @var numeric-string $digits */
        return new Number($digits);
    }

    /**
     * @return numeric-string
     */
    private function ns(string $digits): string
    {
        /** @var numeric-string $digits */
        return $digits;
    }

    private function isZero(string $number): bool
    {
        return $this->num($number)->compare($this->num('0')) === 0;
    }

    /**
     * A value as a number: null stays null, a number is itself, text that is a number is that number, and anything else cannot be one.
     *
     * @return numeric-string|null
     */
    private function number(string|bool|null|ExpressionNumber $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof ExpressionNumber) {
            return $this->ns($value->value);
        }

        if (is_string($value) && preg_match(ExecutionResult::DECIMAL, trim($value)) === 1 && ! preg_match('/[eE]/', $value)) {
            return $this->ns(ltrim(trim($value), '+'));
        }

        throw new ExpressionTypeError('A value is not a number.');
    }

    private function truthy(string|bool|ExpressionNumber $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        throw new ExpressionTypeError('A condition must be true or false.');
    }

    private function compare(string|bool|ExpressionNumber $a, string|bool|ExpressionNumber $b, string $op): int
    {
        $ordering = ! in_array($op, ['=', '<>'], true);
        $numericA = $a instanceof ExpressionNumber || (is_string($a) && preg_match(ExecutionResult::DECIMAL, trim($a)) === 1);
        $numericB = $b instanceof ExpressionNumber || (is_string($b) && preg_match(ExecutionResult::DECIMAL, trim($b)) === 1);

        if ($numericA && $numericB && ! (is_string($a) && is_string($b))) {
            return $this->num((string) $this->number($a))->compare($this->num((string) $this->number($b)));
        }

        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) <=> 0;
        }

        if (is_bool($a) && is_bool($b)) {
            return (int) $a <=> (int) $b;
        }

        if ($ordering) {
            throw new ExpressionTypeError('These values cannot be ordered.');
        }

        return 1;
    }

    public static function trimmed(string $decimal): string
    {
        if (! str_contains($decimal, '.')) {
            return $decimal === '-0' ? '0' : $decimal;
        }

        $trimmed = rtrim(rtrim($decimal, '0'), '.');

        return $trimmed === '' || $trimmed === '-' || $trimmed === '-0' ? '0' : $trimmed;
    }
}
