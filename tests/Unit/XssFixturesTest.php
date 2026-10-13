<?php

use App\Modules\BlockTypes\Application\LoadBlockTypes;
use App\Modules\BlockTypes\Infrastructure\PackageShapers;

// Story 5.16: every slot of every Block Type is filled with hostile text and shaped; the payloads are the fixtures the browser tests render. The fixture
// file is generated from the schemas, so a slot added to any package is in it (or this test fails naming it).
const XSS_FIXTURE = __DIR__.'/../Fixtures/xss-payloads.json';

const XSS_STRINGS = [
    '"><img src=x onerror=window.__xss=1>',
    '<script>window.__xss=1</script>',
    "javascript:window.__xss=1//{{user.name}}\u{202E}\u{0000}",
];

/** @return array<string, array{slots: list<string>, payload: array<string, mixed>}> */
function xssBuild(): array
{
    $types = (new LoadBlockTypes(dirname(__DIR__, 2).'/block-types'))->all();
    $shapers = new PackageShapers;
    $out = [];

    foreach ($types as $type) {
        $slots = [];

        foreach ($type->slots as $slot) {
            $rows = $slot->cardinality === 'rows';
            $one = match ($slot->valueType) {
                'number' => '12.5',
                'boolean' => true,
                default => XSS_STRINGS[0],
            };
            $slots[$slot->key] = $rows
                ? ($slot->valueType === 'number' || $slot->valueType === 'boolean' ? [$one, $one, $one] : XSS_STRINGS)
                : $one;
        }

        $payload = $shapers->shape($type, $slots, ['state' => 'normal']);
        $out[$type->key] = ['slots' => array_map(fn ($s): string => $s->key, $type->slots), 'payload' => $payload];
    }

    ksort($out);

    return $out;
}

it('has a fixture for every slot of every Block Type, current with the schemas', function () {
    $built = xssBuild();
    $json = json_encode($built, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

    // Delete the file to regenerate it; the test then fails once, asking for the new file to be committed.
    if (! file_exists(XSS_FIXTURE)) {
        file_put_contents(XSS_FIXTURE, $json);
        $this->fail('The XSS fixtures were written to tests/Fixtures/xss-payloads.json: review and commit them.');
    }

    expect(array_keys($built))->toBe(['activity-feed', 'bar', 'calendar-agenda', 'kpi-card', 'kpi-chart', 'line-area', 'list', 'pie-donut', 'progress-list', 'table', 'text-status']);

    $stored = json_decode((string) file_get_contents(XSS_FIXTURE), true, 512, JSON_THROW_ON_ERROR);

    foreach ($built as $key => $entry) {
        $missing = array_values(array_diff($entry['slots'], $stored[$key]['slots'] ?? []));
        expect($missing)->toBe([], "{$key} has slots with no XSS fixture: ".implode(', ', $missing));
    }

    // Compared as data: the formatter of the repository may lay the file out as it likes.
    expect($stored)->toBe(json_decode($json, true, 512, JSON_THROW_ON_ERROR), 'the XSS fixtures are stale: delete tests/Fixtures/xss-payloads.json and run this test once');
});

it('carries the hostile text through the Shapers as it came, never as markup of their own', function () {
    $built = xssBuild();

    foreach ($built as $key => $entry) {
        $encoded = json_encode($entry['payload'], JSON_THROW_ON_ERROR);

        // The Shapers hand text on untouched (the browser writes it as text); none of them wraps it in markup.
        expect($encoded)->not->toContain('<b>')->and($encoded)->not->toContain('<div', "{$key} wrote markup");
    }
});
