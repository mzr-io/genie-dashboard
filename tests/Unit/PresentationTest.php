<?php

use App\Modules\BlockTypes\Application\LoadBlockTypes;
use App\Modules\Mapping\Contracts\DecimalValue;
use App\Modules\Mapping\Contracts\Presentation;

// Story 3.15a: the presentation document is judged whole; every fault is keyed config.presentation.<path>.
function presType()
{
    return (new LoadBlockTypes(dirname(__DIR__, 2).'/block-types'))->resolve('kpi-card', 1);
}

/** @return array{0: array<string, mixed>, 1: array<string, list<string>>} */
function presCheck(mixed $input): array
{
    $errors = [];

    return [Presentation::check($input, presType(), $errors), $errors];
}

it('stores descriptors only: no locale-formatted strings, an upper-cased currency, and nothing for null or an empty object', function () {
    [$out, $errors] = presCheck(['slots' => ['headline_value' => ['format' => ['kind' => 'currency', 'currencyCode' => 'eur', 'currencyDisplay' => 'code', 'decimalPlaces' => 2, 'thousandsSeparator' => false]]]]);

    expect($errors)->toBe([])
        ->and($out['slots']['headline_value']['format'])->toBe(['kind' => 'currency', 'decimalPlaces' => 2, 'thousandsSeparator' => false, 'currencyCode' => 'EUR', 'currencyDisplay' => 'code']);
    expect(presCheck(null))->toBe([[], []])->and(presCheck([]))->toBe([[], []]);
});

it('accepts every format kind and refuses unknown kinds, unknown keys, bad places and unsupported currencies', function () {
    foreach (Presentation::KINDS as $kind) {
        $format = $kind === 'currency' ? ['kind' => $kind, 'currencyCode' => 'USD'] : ['kind' => $kind];
        expect(presCheck(['slots' => ['headline_value' => ['format' => $format + ['unitsSuffix' => 'kg', 'datePattern' => 'long']]]])[1])->toBe([], $kind);
    }

    foreach ([
        ['kind' => 'bogus'], ['kind' => 'number', 'decimalPlaces' => -1], ['kind' => 'number', 'decimalPlaces' => 21], ['kind' => 'number', 'decimalPlaces' => 1.5], ['kind' => 'number', 'decimalPlaces' => '2'],
        ['kind' => 'currency'], ['kind' => 'currency', 'currencyCode' => 'XX1'], ['kind' => 'currency', 'currencyCode' => 'ZZZ'], ['kind' => 'currency', 'currencyCode' => 'DOLLARS'],
        ['kind' => 'number', 'locale' => 'en'], ['kind' => 'number', 'unitsSuffix' => str_repeat('x', 17)], ['kind' => 'number', 'datePattern' => 'yyyy'], ['kind' => 'number', 'thousandsSeparator' => 'yes'],
        'number', [],
    ] as $format) {
        [$out, $errors] = presCheck(['slots' => ['headline_value' => ['format' => $format]]]);
        expect($errors)->not->toBe([], json_encode($format))->and($out)->toBe([]);

        foreach (array_keys($errors) as $path) {
            expect($path)->toStartWith('config.presentation.slots.headline_value.format');
        }
    }
});

it('judges bands: ops, decimal strings, between order, colours without accent, and a required icon and label', function () {
    $ok = ['op' => 'between', 'value' => '1E2', 'to' => '2.5e2', 'color' => 'neutral', 'icon' => 'gauge', 'label' => ' Fine '];
    [$out, $errors] = presCheck(['bands' => [$ok]]);
    expect($errors)->toBe([])->and($out['bands'][0]['label'])->toBe('Fine')->and($out['bands'][0]['value'])->toBe('1E2');

    foreach ([
        ['color' => 'accent'], ['icon' => null], ['label' => '  '], ['label' => null], ['op' => '>'], ['value' => 100], ['value' => '1,000'], ['to' => '10', 'value' => '20'], ['to' => null], ['icon' => 'Bad Icon'], ['extra' => 1],
    ] as $change) {
        [$out, $errors] = presCheck(['bands' => [array_merge($ok, $change)]]);
        expect($errors)->not->toBe([], json_encode($change))->and($out)->toBe([]);
    }

    expect(presCheck(['bands' => ['a' => $ok]])[1])->toHaveKey('config.presentation.bands')
        ->and(presCheck(['bands' => array_fill(0, 11, $ok)])[1])->toHaveKey('config.presentation.bands')
        ->and(presCheck(['bands' => [['op' => '>=', 'value' => '1', 'to' => '2'] + $ok]])[1])->toHaveKey('config.presentation.bands.0.to');
});

it('allows direction on the comparison slot only and at most one headline', function () {
    expect(presCheck(['slots' => ['comparison' => ['direction' => 'lower_is_better']]])[1])->toBe([])
        ->and(presCheck(['slots' => ['headline_value' => ['direction' => 'lower_is_better']]])[1])->toHaveKey('config.presentation.slots.headline_value.direction');

    [, $errors] = presCheck(['slots' => ['headline_value' => ['emphasis' => ['role' => 'headline']], 'title' => ['emphasis' => ['role' => 'primary']], 'subtitle' => ['emphasis' => ['role' => 'headline']]]]);
    expect(array_keys($errors))->toBe(['config.presentation.slots.subtitle.emphasis']);
});

it('compares decimals exactly, lexemes by value, and gives no value to junk or huge exponents', function () {
    expect(DecimalValue::compare('1E2', '100'))->toBe(0)
        ->and(DecimalValue::compare('0.1', '0.10'))->toBe(0)
        ->and(DecimalValue::compare('12345678901234567890.10', '12345678901234567890.09'))->toBe(1)
        ->and(DecimalValue::compare('-1.5e-3', '-0.0015'))->toBe(0)
        ->and(DecimalValue::compare('-0', '0'))->toBe(0)
        ->and(DecimalValue::compare('abc', '1'))->toBeNull()
        ->and(DecimalValue::compare('1e99999', '1'))->toBeNull()
        ->and(DecimalValue::plain('.5'))->toBe('0.5')
        ->and(DecimalValue::plain('5.'))->toBe('5');
});
