<?php

use BlockTypes\KpiCard\V1\Shaper;

// Story 3.2: the kpi-card Shaper emits decimal strings and format descriptors, never formatted text.
it('emits the payload with decimal-string measures and descriptors', function () {
    $payload = (new Shaper)->shape(
        ['kpi_label' => 'Net revenue', 'headline_value' => '284680.10', 'comparison' => '-12.5', 'comparison_label' => 'vs last month'],
        ['headlineFormat' => ['kind' => 'currency', 'currencyCode' => 'usd'], 'comparisonFormat' => ['kind' => 'percent', 'decimalPlaces' => 1], 'lowerIsBetter' => true],
    );

    expect($payload['state'])->toBe('normal')
        ->and($payload['headline'])->toBe(['available' => true, 'value' => '284680.10', 'format' => [
            'kind' => 'currency', 'decimalPlaces' => null, 'thousandsSeparator' => true, 'currencyCode' => 'USD',
            'currencyDisplay' => 'symbol', 'datePattern' => 'medium', 'unitsSuffix' => null,
        ]])
        ->and($payload['comparison']['value'])->toBeString()->toBe('-12.5')
        ->and($payload['comparison']['direction'])->toBe('down')
        ->and($payload['comparison']['lowerIsBetter'])->toBeTrue()
        ->and($payload['comparison']['format']['decimalPlaces'])->toBe(1)
        ->and($payload['kpiLabel'])->toBe('Net revenue');
});

it('keeps every digit of a long decimal string', function () {
    $payload = (new Shaper)->shape(['headline_value' => '12345678901234567890.123456789']);

    expect($payload['headline']['value'])->toBe('12345678901234567890.123456789');
});

it('turns integers into digit strings and refuses floats and junk as measures', function () {
    expect((new Shaper)->shape(['headline_value' => 42])['headline']['value'])->toBe('42');

    foreach ([0.1, 'abc', '', null, true, ['1'], 'NaN', '1,000'] as $bad) {
        $payload = (new Shaper)->shape(['headline_value' => $bad]);

        expect($payload['state'])->toBe('unavailable')
            ->and($payload['headline'])->toBe(['available' => false, 'field' => 'Headline value']);
    }
});

it('derives the direction from the sign without arithmetic', function () {
    $direction = fn (string $v) => (new Shaper)->shape(['headline_value' => '1', 'comparison' => $v])['comparison']['direction'];

    expect($direction('5'))->toBe('up')
        ->and($direction('-0.001'))->toBe('down')
        ->and($direction('0'))->toBe('flat')
        ->and($direction('-0.00'))->toBe('flat')
        ->and($direction('0e5'))->toBe('flat');
});

it('has no comparison when the slot is missing and falls back on bad options', function () {
    $payload = (new Shaper)->shape(['headline_value' => '1'], ['state' => 'bogus', 'headlineFormat' => ['kind' => 'nope', 'decimalPlaces' => -1, 'currencyCode' => 'DOLLARS']]);

    expect($payload['comparison'])->toBeNull()
        ->and($payload['state'])->toBe('normal')
        ->and($payload['headline']['format']['kind'])->toBe('number')
        ->and($payload['headline']['format']['decimalPlaces'])->toBeNull()
        ->and($payload['headline']['format']['currencyCode'])->toBeNull();
});

it('passes the requested state and text through verbatim, with no formatting or escaping', function () {
    $payload = (new Shaper)->shape(['headline_value' => '1', 'title' => '<img src=x onerror=alert(1)>'], ['state' => 'stale', 'staleSince' => '2026-10-09T08:00:00Z']);

    expect($payload['state'])->toBe('stale')
        ->and($payload['staleSince'])->toBe('2026-10-09T08:00:00Z')
        ->and($payload['title'])->toBe('<img src=x onerror=alert(1)>')
        ->and($payload['headline']['value'])->toBe('1');
});

it('rejects a trailing newline on a decimal or currency code', function () {
    $payload = (new Shaper)->shape(['headline_value' => "1\n"], ['headlineFormat' => ['kind' => 'currency', 'currencyCode' => "USD\n"]]);

    expect($payload['headline']['value'] ?? null)->toBe('1');

    $payload = (new Shaper)->shape(['headline_value' => '1'], ['headlineFormat' => ['kind' => 'currency', 'currencyCode' => "USD\n"]]);

    expect($payload['headline']['format']['currencyCode'])->toBeNull();
});

// Story 3.15a: presentation options reach the payload; existing options leave the existing fields as they were.
it('maps presentation options to the payload and leaves the headline exactly as before when none is given', function () {
    $plain = (new Shaper)->shape(['headline_value' => '1']);

    expect($plain['headline'])->not->toHaveKey('emphasis')
        ->and($plain['statusBadge'])->toBeNull()
        ->and($plain['prefix'])->toBeNull()->and($plain['suffix'])->toBeNull()
        ->and($plain['emptyDisplay'])->toBeNull()->and($plain['emptySlots'])->toBe([]);

    $payload = (new Shaper)->shape(['headline_value' => '284680', 'status_badge' => 'Open'], [
        'headlineEmphasis' => ['role' => 'headline', 'size' => 'lg', 'weight' => 'medium'],
        'prefix' => '~', 'suffix' => ' gross',
        'statusBand' => ['text' => 'On target', 'color' => 'success', 'icon' => 'trending-up'],
        'emptyDisplay' => '—', 'emptySlots' => ['secondary_note', 'secondary_note'],
    ]);

    expect($payload['headline']['emphasis'])->toBe(['role' => 'headline', 'size' => 'lg', 'weight' => 'medium'])
        ->and($payload['prefix'])->toBe('~')->and($payload['suffix'])->toBe(' gross')
        ->and($payload['statusBadge'])->toBe(['text' => 'On target', 'color' => 'success', 'icon' => 'trending-up'])
        ->and($payload['emptyDisplay'])->toBe('—')->and($payload['emptySlots'])->toBe(['secondary_note']);
});

it('lets the status_badge text show only when no band matched, and ignores a malformed band', function () {
    $text = (new Shaper)->shape(['headline_value' => '1', 'status_badge' => 'Open']);
    expect($text['statusBadge'])->toBe('Open');

    foreach ([['text' => 'x', 'color' => 'accent', 'icon' => 'gauge'], ['text' => '', 'color' => 'info', 'icon' => 'gauge'], ['text' => 'x', 'color' => 'info', 'icon' => 'Bad Icon'], 'nope'] as $bad) {
        expect((new Shaper)->shape(['headline_value' => '1', 'status_badge' => 'Open'], ['statusBand' => $bad])['statusBadge'])->toBe('Open');
    }
});

it('omits an emphasis size or weight the rules leave unset', function () {
    $payload = (new Shaper)->shape(['headline_value' => '5'], ['headlineEmphasis' => ['size' => 'lg']]);

    expect($payload['headline']['emphasis'])->toBe(['role' => 'headline', 'size' => 'lg']);
});

it('gives no headline emphasis, and no empty slots, for an unavailable headline or no empty display', function () {
    $payload = (new Shaper)->shape(['headline_value' => null], ['headlineEmphasis' => ['role' => 'headline'], 'emptySlots' => ['comparison']]);

    expect($payload['headline'])->toBe(['available' => false, 'field' => 'Headline value'])
        ->and($payload['emptySlots'])->toBe([]);
});
