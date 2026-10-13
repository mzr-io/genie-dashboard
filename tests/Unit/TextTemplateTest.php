<?php

use App\Modules\Mapping\Application\Expressions\TextTemplate;
use App\Modules\Mapping\Contracts\InvalidExpression;

// Story 5.4 (FR-26, AD-12): the Text Template grammar.
it('reads literal text mixed with every kind of token', function () {
    $parts = TextTemplate::parse('{ROW_COUNT} requests · {category} · {revenue|currency} · {period.start|MMM d} – {period.end|MMM d, yyyy} · {user.name}');

    expect(array_column($parts, 't'))->toBe(['row_count', 'lit', 'field', 'lit', 'field', 'lit', 'period', 'lit', 'period', 'lit', 'user'])
        ->and($parts[0]['fmt'])->toBe('integer')
        ->and($parts[4]['fmt'])->toBe('currency')
        ->and($parts[6])->toMatchArray(['end' => false, 'fmt' => 'MMM d'])
        ->and($parts[8])->toMatchArray(['end' => true, 'fmt' => 'MMM d, yyyy'])
        ->and($parts[10]['key'])->toBe('name');
});

it('reads the story\'s example templates', function () {
    foreach (['{ROW_COUNT} requests need your attention', '{category} · {days} days', '{done}/{total} tasks', '{period.start|MMM d} – {period.end|MMM d, yyyy}'] as $source) {
        expect(TextTemplate::parse($source))->not->toBe([]);
    }
});

it('keeps literal braces written doubled and resolves field names to stored paths', function () {
    $parts = TextTemplate::resolve(TextTemplate::parse('{{x}} {Net Revenue}'), ['net revenue' => '$.data[*].net']);

    expect($parts[0])->toBe(['t' => 'lit', 'v' => '{x} '])
        ->and($parts[1]['path'])->toBe('$.data[*].net')
        ->and(TextTemplate::paths($parts))->toBe(['$.data[*].net']);
});

it('refuses an unknown field or format naming the token and its position', function () {
    try {
        TextTemplate::resolve(TextTemplate::parse('Total {ghost|currency}'), []);
        $caught = null;
    } catch (InvalidExpression $e) {
        $caught = $e;
    }

    expect($caught?->getMessage())->toContain('{ghost|currency}')->toContain('isn’t a field')
        ->and($caught?->position)->toBe(7);

    foreach (['{total|bogus}' => 'bogus', '{period.start|dd/MM}' => 'dd/MM', '{ROW_COUNT|currency}' => 'ROW_COUNT'] as $source => $needle) {
        try {
            TextTemplate::parse($source);
            $message = '';
        } catch (InvalidExpression $e) {
            $message = $e->getMessage();
        }

        expect($message)->toContain($needle);
    }
});

it('refuses an open, empty or stray brace and an over-long template', function () {
    foreach (['{total', '{}', 'a } b', '{total|}', '{a|b|c}', '', str_repeat('x', 301), '{user.name|upper}', '{user.Name}'] as $source) {
        expect(fn () => TextTemplate::parse($source))->toThrow(InvalidExpression::class);
    }
});
