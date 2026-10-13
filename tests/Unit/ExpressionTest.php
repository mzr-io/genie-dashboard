<?php

use App\Modules\Mapping\Application\DecimalMath;
use App\Modules\Mapping\Application\Expressions\ExpressionEvaluator;
use App\Modules\Mapping\Application\Expressions\ExpressionNumber;
use App\Modules\Mapping\Application\Expressions\ExpressionParser;
use App\Modules\Mapping\Application\Expressions\ExpressionResolver;
use App\Modules\Mapping\Application\Expressions\ExpressionTypeError;
use App\Modules\Mapping\Contracts\InvalidExpression;
use Illuminate\Config\Repository;

// Story 5.3 (AD-12): the one expression grammar, closed function list, decimal arithmetic, safe division, no script execution.
function exEval(string $source, array $values = []): string|bool|null
{
    $math = new DecimalMath(new Repository(['dashflow' => ['tunables' => ['numbers' => ['decimal_scale' => ['value' => '6']]]]]));
    $names = [];

    foreach (array_keys($values) as $name) {
        $names[strtolower($name)] = '$.'.$name;
    }

    $tree = ExpressionResolver::resolve(ExpressionParser::parse($source), $names);

    return (new ExpressionEvaluator($math))->evaluate($tree, function (string $path) use ($values) {
        $value = $values[substr($path, 2)] ?? null;

        return is_int($value) || (is_string($value) && is_numeric($value) && false) ? new ExpressionNumber((string) $value) : (is_float($value) ? new ExpressionNumber((string) $value) : $value);
    });
}

function exFail(string $source): InvalidExpression
{
    try {
        ExpressionParser::parse($source);
    } catch (InvalidExpression $e) {
        return $e;
    }

    throw new RuntimeException('Expected the expression to be refused.');
}

it('does arithmetic with the usual precedence and exact decimals', function () {
    expect(exEval('1 + 2 * 3'))->toBe('7')
        ->and(exEval('(1 + 2) * 3'))->toBe('9')
        ->and(exEval('0.1 + 0.2'))->toBe('0.3')
        ->and(exEval('10 / 4'))->toBe('2.5')
        ->and(exEval('-3 + 5'))->toBe('2')
        ->and(exEval('12345678901234567.89 + 0.01'))->toBe('12345678901234567.9');
});

it('computes a margin from named fields', function () {
    expect(exEval('(revenue - cost) / revenue', ['revenue' => 200, 'cost' => 150]))->toBe('0.25')
        ->and(exEval('([revenue] - [cost]) / [revenue]', ['revenue' => 200, 'cost' => 150]))->toBe('0.25');
});

it('returns null for a division by 0 or by null, never 0 and never an error', function () {
    expect(exEval('10 / 0'))->toBeNull()
        ->and(exEval('a / b', ['a' => 5, 'b' => null]))->toBeNull()
        ->and(exEval('a / b', ['a' => null, 'b' => 5]))->toBeNull()
        ->and(exEval('(a - b) / 0', ['a' => 5, 'b' => 1]))->toBeNull();
});

it('gives PCT_CHANGE as a percentage and null for a previous value of 0 or null', function () {
    expect(exEval('PCT_CHANGE(284680, 249195)'))->toBe('14.239852')
        ->and(exEval('ROUND(PCT_CHANGE(284680, 249195), 1)'))->toBe('14.2')
        ->and(exEval('PCT_CHANGE(5, 0)'))->toBeNull()
        ->and(exEval('PCT_CHANGE(5, p)', ['p' => null]))->toBeNull()
        ->and(exEval('PCT_CHANGE(c, 100)', ['c' => 150]))->toBe('50');
});

it('rounds half away from zero, takes absolute values and reads date parts', function () {
    expect(exEval('ROUND(2.5)'))->toBe('3')
        ->and(exEval('ROUND(-2.5)'))->toBe('-3')
        ->and(exEval('ROUND(3.14159, 2)'))->toBe('3.14')
        ->and(exEval('ABS(-4.5)'))->toBe('4.5')
        ->and(exEval('YEAR("2026-10-12T09:30:00Z")'))->toBe('2026')
        ->and(exEval('MONTH("2026-10-12")'))->toBe('10')
        ->and(exEval('DAY("2026-10-12")'))->toBe('12')
        ->and(exEval('QUARTER("2026-10-12")'))->toBe('4')
        ->and(exEval('YEAR("not a date")'))->toBeNull();
});

it('evaluates IF lazily with comparisons and logic', function () {
    expect(exEval('IF(a > 10, "high", "low")', ['a' => 11]))->toBe('high')
        ->and(exEval('IF(a > 10, "high", "low")', ['a' => 10]))->toBe('low')
        ->and(exEval('IF(a > 10 AND NOT (a = 12), 1, 0)', ['a' => 11]))->toBe('1')
        ->and(exEval('IF(b > 0, a / b, 0)', ['a' => 4, 'b' => 0]))->toBe('0')
        ->and(exEval('IF(a, 1, 2)', ['a' => null]))->toBe('2')
        ->and(exEval('1 <> 2'))->toBeTrue()
        ->and(exEval('"x" = "x"'))->toBeTrue();
});

it('propagates null through arithmetic and reads a missing value as null, not 0', function () {
    expect(exEval('a + 1', ['a' => null]))->toBeNull()
        ->and(exEval('a * b', ['a' => 2, 'b' => null]))->toBeNull()
        ->and(exEval('ABS(a)', ['a' => null]))->toBeNull();
});

it('refuses text used as a number', function () {
    expect(fn () => exEval('a + 1', ['a' => 'hello']))->toThrow(ExpressionTypeError::class)
        ->and(exEval('a + 1', ['a' => '41']))->toBe('42');
});

it('refuses any function outside the closed list, and nothing in it can run code', function () {
    foreach (['SUM(a)', 'eval("1")', 'system("ls")', 'file_get_contents("x")', 'LEN(a)', 'MAX(1, 2)'] as $source) {
        expect(fn () => ExpressionParser::parse($source))->toThrow(InvalidExpression::class);
    }

    // No assignment, loop, block, member call or statement separator is in the grammar.
    foreach (['a = 1; b', 'x => 1', '{ 1 }', 'a.b()', '1 2', 'while(1)', '$a', 'a ? b : c'] as $source) {
        expect(fn () => ExpressionParser::parse($source))->toThrow(InvalidExpression::class);
    }
});

it('names the position of a syntax error', function () {
    expect(exFail('ROUND(revenue,')->position)->toBe(15)
        ->and(exFail('ROUND(revenue,')->getMessage())->toContain('too early')
        ->and(exFail('(1 + 2')->position)->toBe(1)
        ->and(exFail('1 + * 2')->position)->toBe(5)
        ->and(exFail('revenue @ 2')->position)->toBe(9)
        ->and(exFail('"open')->position)->toBe(1)
        ->and(exFail('IF(1, 2)')->getMessage())->toContain('3 arguments')
        ->and(exFail('')->position)->toBe(1)
        ->and(exFail(str_repeat('1+', 300).'1')->getMessage())->toContain('500');
});

it('refuses a field that is not in the response, naming where it is', function () {
    $tree = ExpressionParser::parse('revenue - cost');

    try {
        ExpressionResolver::resolve($tree, ['revenue' => '$.revenue']);
        $caught = null;
    } catch (InvalidExpression $e) {
        $caught = $e;
    }

    expect($caught?->position)->toBe(11)
        ->and($caught?->getMessage())->toContain('cost');

    try {
        ExpressionResolver::resolve(ExpressionParser::parse('name'), ['name' => '']);
        $ambiguous = null;
    } catch (InvalidExpression $e) {
        $ambiguous = $e;
    }

    expect($ambiguous?->getMessage())->toContain('more than one field');
});

it('lists the stored paths a resolved expression reads', function () {
    $tree = ExpressionResolver::resolve(ExpressionParser::parse('IF(a > 0, b / a, c)'), ['a' => '$.a', 'b' => '$.b', 'c' => '$.c']);

    expect(ExpressionResolver::paths($tree))->toBe(['$.a', '$.b', '$.a', '$.c']);
});
