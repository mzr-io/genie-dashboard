<?php

use App\Modules\BlockTypes\Application\LoadBlockTypes;
use App\Modules\BlockTypes\Contracts\BlockTypeSlot;
use App\Modules\Mapping\Application\AutoMapper;
use App\Modules\Mapping\Application\FieldRoleInference;
use App\Modules\Mapping\Contracts\AutoMapResult;
use App\Modules\Mapping\Contracts\ExecutionResult;
use App\Platform\Json\LosslessJson;
use App\Platform\Json\SampleShape;

// Story 3.12a: auto-map of the kpi-card slots over a sample's field report, with confidence, decisions and the plan.
function amMap(string $json, array $slotMap = [], ?string $recordPath = null, array $roles = [], array $types = []): AutoMapResult
{
    $type = (new LoadBlockTypes(dirname(__DIR__, 2).'/block-types'))->resolve('kpi-card', 1);
    $report = (new FieldRoleInference)->infer(SampleShape::of(LosslessJson::decode($json)), $recordPath, $roles);

    return (new AutoMapper)->map($report, $type, $slotMap, $types);
}

function amSlot($result, string $key): array
{
    return $result->slot($key);
}

it('maps a unique numeric value as High and writes the plan', function () {
    $r = amMap('{"total":284680.50,"label":"Revenue"}');

    expect(amSlot($r, 'headline_value'))->toMatchArray(['state' => 'auto_high', 'confidence' => 'high', 'path' => '$.total'])
        ->and(amSlot($r, 'kpi_label'))->toMatchArray(['state' => 'auto_medium', 'confidence' => 'medium', 'path' => '$.label'])
        ->and($r->plan)->toBe(['v' => 1, 'extract' => ['fields' => ['kpi_label' => '$.label', 'headline_value' => '$.total']], 'shape' => ['kpi_label' => 'kpi_label', 'headline_value' => 'headline_value']])
        ->and($r->baseline)->toBe([]);
});

it('picks the value not named previous_* as Medium with the reason, and reports the previous one as baseline, unmapped', function () {
    $r = amMap('{"total":10,"previous_total":8}');

    expect(amSlot($r, 'headline_value'))->toMatchArray(['state' => 'auto_medium', 'confidence' => 'medium', 'path' => '$.total', 'reason' => '2 values; picked the one not named previous_*.'])
        ->and($r->baseline)->toBe([['path' => '$.previous_total', 'display' => 'previous_total']])
        ->and(array_values($r->plan['extract']['fields']))->not->toContain('$.previous_total');

    foreach (['priorYear', 'prev_total', 'lastMonth', 'baseline'] as $name) {
        expect(amSlot(amMap('{"total":10,"'.$name.'":8}'), 'headline_value')['path'])->toBe('$.total');
    }
});

it('grades several remaining candidates Low, first in document order, with the ambiguity in the reason', function () {
    $r = amMap('{"total":10,"revenue":12,"previous_total":8}');

    expect(amSlot($r, 'headline_value'))->toMatchArray(['state' => 'auto_low', 'confidence' => 'low', 'path' => '$.total'])
        ->and(amSlot($r, 'headline_value')['reason'])->toContain('total, revenue')
        ->and($r->baseline)->toBe([['path' => '$.previous_total', 'display' => 'previous_total']])
        ->and($r->blockers)->toContain(['slot' => 'headline_value', 'reason' => 'unconfirmed_low']);

    // Low rows come first in their group.
    expect($r->groups[1]['group'])->toBe('KPI')->and($r->groups[1]['slots'][0]['slot'])->toBe('headline_value');
});

it('is type_mismatch when the only numbers are inside the rows', function () {
    $r = amMap('{"data":[{"revenue":1},{"revenue":2}]}');

    expect(amSlot($r, 'headline_value'))->toMatchArray(['state' => 'type_mismatch', 'path' => null])
        ->and(amSlot($r, 'headline_value')['reason'])->toContain('inside the rows')
        ->and($r->counts['type_mismatch'])->toBe(1)
        ->and(array_column($r->blockers, 'reason', 'slot')['headline_value'])->toBe('type_mismatch');
});

it('is required_missing with a suggestion when a candidate exists, and without one when none does', function () {
    // Role of the only number overridden away from value: there is a candidate, but the rules passed it over.
    $r = amMap('{"total":10}', roles: ['$.total' => 'dimension']);
    expect(amSlot($r, 'headline_value'))->toMatchArray(['state' => 'required_missing', 'suggestion' => ['path' => '$.total', 'display' => 'total']])
        ->and($r->blockers)->toContain(['slot' => 'headline_value', 'reason' => 'required_missing']);

    $none = amMap('{"region":"EU"}');
    expect(amSlot($none, 'headline_value'))->toMatchArray(['state' => 'required_missing', 'suggestion' => null]);
    // kpi_label has nothing named like a label, but a text value exists to suggest.
    expect(amSlot($none, 'kpi_label'))->toMatchArray(['state' => 'required_missing', 'suggestion' => ['path' => '$.region', 'display' => 'region']]);
});

it('maps text slots by key word as Medium, leaves optional ones empty, and never maps config-only slots', function () {
    $r = amMap('{"total":1,"title":"T","description":"D","status":"ok","note":"n","month":"2026-01"}');

    expect(amSlot($r, 'title')['path'])->toBe('$.title')
        ->and(amSlot($r, 'subtitle')['path'])->toBe('$.description')
        ->and(amSlot($r, 'status_badge')['path'])->toBe('$.status')
        ->and(amSlot($r, 'secondary_note'))->toMatchArray(['path' => '$.note', 'state' => 'auto_medium'])
        ->and(amSlot($r, 'comparison'))->toMatchArray(['state' => 'optional_empty', 'path' => null])
        ->and($r->slot('icon'))->toBeNull()
        ->and($r->slot('footer_action'))->toBeNull()
        ->and(array_column($r->groups, 'group'))->toBe(['HEADER', 'KPI', 'META'])
        ->and($r->counts['total'])->toBe(8);
});

it('counts and blocks as specified (medium optional does not block)', function () {
    $r = amMap('{"total":1,"status":"ok"}');

    expect($r->counts)->toBe(['auto_mapped' => 2, 'total' => 8, 'confirmed' => 1, 'needs_review' => 1, 'required_missing' => 1, 'type_mismatch' => 0])
        ->and($r->blockers)->toBe([['slot' => 'kpi_label', 'reason' => 'required_missing']]);
});

it('leaves a field inside the rows ineligible for a text slot and says so', function () {
    $r = amMap('{"data":[{"name":"A"},{"name":"B"}]}');

    expect(amSlot($r, 'title')['state'])->toBe('type_mismatch')->and(amSlot($r, 'kpi_label')['state'])->toBe('type_mismatch');
});

it('keeps confirmed and overridden slots as they are and recomputes the rest', function () {
    $json = '{"total":10,"previous_total":8,"month":"Jan","region":"EU"}';
    $map = ['headline_value' => ['path' => '$.previous_total', 'state' => 'overridden'], 'kpi_label' => ['path' => '$.region', 'state' => 'confirmed']];
    $r = amMap($json, $map);

    expect(amSlot($r, 'headline_value'))->toMatchArray(['state' => 'overridden', 'path' => '$.previous_total', 'confidence' => 'high'])
        ->and(amSlot($r, 'kpi_label'))->toMatchArray(['state' => 'confirmed', 'path' => '$.region'])
        ->and($r->plan['extract']['fields'])->toMatchArray(['headline_value' => '$.previous_total', 'kpi_label' => '$.region'])
        ->and($r->counts['confirmed'])->toBe(2)
        // The same again gives the same.
        ->and(amMap($json, $map)->toArray())->toBe($r->toArray());
});

it('reads a cleared slot as empty, and a stale confirmed or overridden slot as empty with api_changed, not dropped', function () {
    $cleared = amMap('{"total":10}', ['headline_value' => ['path' => null, 'state' => 'overridden']]);
    expect(amSlot($cleared, 'headline_value'))->toMatchArray(['state' => 'required_missing', 'path' => null]);

    $stale = amMap('{"total":10}', ['headline_value' => ['path' => '$.gone', 'state' => 'confirmed']]);
    expect(amSlot($stale, 'headline_value'))->toMatchArray(['state' => 'required_missing', 'path' => '$.gone'])
        ->and($stale->plan['extract']['fields'])->not->toHaveKey('headline_value');

    $wrongType = amMap('{"total":10,"region":"EU"}', ['headline_value' => ['path' => '$.region', 'state' => 'overridden']]);
    expect(amSlot($wrongType, 'headline_value')['state'])->toBe('type_mismatch');
});

it('ignores malformed decisions', function () {
    $r = amMap('{"total":10}', ['headline_value' => ['path' => '$.total', 'state' => 'auto_high'], 'kpi_label' => 'x']);

    expect(amSlot($r, 'headline_value')['state'])->toBe('auto_high');
});

it('carries over only entries whose slot key and value type match, and returns the rest as unmapped', function () {
    $old = [
        new BlockTypeSlot('headline_value', 'Headline', true, 'number', 'single', ['value']),
        new BlockTypeSlot('kpi_label', 'Label', true, 'text', 'single', ['label']),
        new BlockTypeSlot('status_badge', 'Badge', false, 'text', 'single', ['filter']),
    ];
    // A test-only second slot set: same key and type, same key but another type, and a key the old set lacks.
    $new = [
        new BlockTypeSlot('headline_value', 'Value', true, 'number', 'single', ['value']),
        new BlockTypeSlot('kpi_label', 'Label', true, 'number', 'single', ['value']),
        new BlockTypeSlot('target', 'Target', false, 'number', 'single', ['value']),
    ];
    $map = [
        'headline_value' => ['path' => '$.total', 'state' => 'confirmed'],
        'kpi_label' => ['path' => '$.region', 'state' => 'overridden'],
        'status_badge' => ['path' => '$.status', 'state' => 'confirmed'],
    ];

    $out = (new AutoMapper)->carryOver($map, $new, $old);

    expect($out['kept'])->toBe(['headline_value' => $map['headline_value']])
        ->and($out['unmapped'])->toBe([
            ['slot' => 'kpi_label', 'path' => '$.region', 'state' => 'overridden'],
            ['slot' => 'status_badge', 'path' => '$.status', 'state' => 'confirmed'],
        ]);
});

it('maps the acceptance sample: total Medium with the reason, previous_total baseline only', function () {
    $r = amMap('{"total":284680.50,"previous_total":250000,"month":"2026-09","revenue":"284,680 USD","region":"EU","status":"ok"}');

    expect(amSlot($r, 'headline_value'))->toMatchArray(['path' => '$.total', 'state' => 'auto_medium', 'reason' => '2 values; picked the one not named previous_*.'])
        ->and(array_column($r->baseline, 'path'))->toBe(['$.previous_total'])
        ->and($r->plan['extract']['fields'])->not->toContain('$.previous_total');
});

it('gives a text field to one slot only: the later slot that matches the same key stays empty', function () {
    $r = amMap('{"total":1,"name":"Revenue"}');

    expect(amSlot($r, 'title'))->toMatchArray(['state' => 'auto_medium', 'path' => '$.name'])
        ->and(amSlot($r, 'kpi_label'))->toMatchArray(['state' => 'required_missing', 'path' => null])
        ->and(array_count_values(array_values($r->plan['extract']['fields']))['$.name'])->toBe(1);

    // The Admin's decision owns its field: an auto match does not take it.
    $decided = amMap('{"total":1,"name":"Revenue"}', ['kpi_label' => ['path' => '$.name', 'state' => 'confirmed']]);
    expect(amSlot($decided, 'title')['state'])->toBe('optional_empty');
});

it('reports baseline candidates whatever the headline decision, leaving out the decided path', function () {
    $json = '{"total":10,"previous_total":8}';

    expect(amMap($json, ['headline_value' => ['path' => '$.total', 'state' => 'confirmed']])->baseline)->toBe([['path' => '$.previous_total', 'display' => 'previous_total']])
        ->and(amMap($json, ['headline_value' => ['path' => '$.previous_total', 'state' => 'overridden']])->baseline)->toBe([])
        ->and(amMap($json, ['headline_value' => ['path' => null, 'state' => 'overridden']])->baseline)->toBe([['path' => '$.previous_total', 'display' => 'previous_total']]);
});

function amFields(string $json, string $slot, array $types = [], array $roles = [], ?string $recordPath = null): ?array
{
    $type = (new LoadBlockTypes(dirname(__DIR__, 2).'/block-types'))->resolve('kpi-card', 1);
    $report = (new FieldRoleInference)->infer(SampleShape::of(LosslessJson::decode($json)), $recordPath, $roles);

    return (new AutoMapper)->fieldsFor($report, $type, $slot, $types);
}

// Story 3.13a.
it('lists the headline picker: compatible numeric single scalars first, text with the reason, records with the aggregation reason', function () {
    $fields = amFields('{"currency":"USD","total":10,"data":[{"v":1},{"v":2}],"count":3}', 'headline_value');

    expect(array_column($fields, 'path'))->toBe(['$.total', '$.count', '$.currency', '$.data[*].v'])
        ->and(array_column($fields, 'compatible'))->toBe([true, true, false, false])
        ->and($fields[2]['reason'])->toBe("Text field can't be a numeric headline. Use it as the currency format below.")
        ->and($fields[3]['reason'])->toBe('Needs aggregation (a later release).')
        ->and($fields[0])->toMatchArray(['kind' => 'field', 'display' => 'total', 'type' => 'integer', 'effective_type' => 'number', 'role' => 'value', 'example' => '10'])
        ->and($fields[0])->not->toHaveKey('reason');
});

it('offers static_text for a text slot only, and nothing for an unknown or config-only slot', function () {
    $text = amFields('{"total":1,"region":"EU"}', 'subtitle');
    $kinds = array_column($text, 'kind');

    expect($kinds)->toBe(['field', 'static_text', 'field'])
        ->and($text[1])->toMatchArray(['path' => null, 'compatible' => true])
        ->and(array_column(amFields('{"total":1}', 'headline_value'), 'kind'))->not->toContain('static_text')
        ->and(amFields('{"total":1}', 'icon'))->toBeNull()
        ->and(amFields('{"total":1}', 'footer_action'))->toBeNull()
        ->and(amFields('{"total":1}', 'bogus'))->toBeNull();
});

it('treats a decimal-shaped string as a number, and keeps any other forced mismatch with its reason', function () {
    $json = '{"revenue":"284680","currency":"USD"}';
    $fields = amFields($json, 'headline_value', ['$.revenue' => 'number', '$.currency' => 'number']);
    $by = array_column($fields, null, 'path');

    expect($by['$.revenue'])->toMatchArray(['compatible' => true, 'effective_type' => 'number', 'type' => 'string'])
        ->and($by['$.currency']['compatible'])->toBeFalse()
        ->and($by['$.currency']['reason'])->toBe('currency is number. Headline value needs a number.');

    // Without the override neither fits; the field is never discarded from a decided slot.
    expect(array_column(amFields($json, 'headline_value'), 'compatible', 'path'))->toBe(['$.revenue' => false, '$.currency' => false]);

    $ok = amMap($json, ['headline_value' => ['path' => '$.revenue', 'state' => 'overridden']], types: ['$.revenue' => 'number']);
    expect(amSlot($ok, 'headline_value'))->toMatchArray(['state' => 'overridden', 'path' => '$.revenue']);

    $bad = amMap($json, ['headline_value' => ['path' => '$.currency', 'state' => 'overridden']], types: ['$.currency' => 'number']);
    expect(amSlot($bad, 'headline_value'))->toMatchArray(['state' => 'type_mismatch', 'path' => '$.currency', 'reason' => 'currency is number. Headline value needs a number.'])
        ->and($bad->blockers)->toContain(['slot' => 'headline_value', 'reason' => 'type_mismatch']);

    $text = amMap($json, ['headline_value' => ['path' => '$.currency', 'state' => 'overridden']]);
    expect(amSlot($text, 'headline_value')['reason'])->toBe('currency is text ("USD"). Headline value needs a number.');
});

it('lets a number be treated as text, and uses the effective type for dates', function () {
    $by = array_column(array_filter(amFields('{"code":12,"day":"2026-01-02"}', 'subtitle', ['$.code' => 'text']), fn ($e) => $e['kind'] === 'field'), null, 'path');

    expect($by['$.code'])->toMatchArray(['compatible' => true, 'effective_type' => 'text'])
        ->and($by['$.day'])->toMatchArray(['compatible' => true, 'effective_type' => 'date']);
});

it('reports api_changed with a match when a decided path left the sample: required_missing, never applied', function () {
    $map = ['headline_value' => ['path' => '$.total', 'state' => 'confirmed']];
    $r = amMap('{"grand_total":10,"region":"EU"}', $map);

    expect(amSlot($r, 'headline_value'))->toMatchArray(['state' => 'required_missing', 'path' => '$.total'])
        ->and($r->apiChanged)->toBe([['slot' => 'headline_value', 'path' => '$.total', 'display' => 'total', 'state' => 'required_missing', 'match' => ['path' => '$.grand_total', 'display' => 'grand_total']]])
        ->and($r->toArray()['api_changed'])->toBe($r->apiChanged)
        ->and($r->blockers)->toContain(['slot' => 'headline_value', 'reason' => 'required_missing'])
        ->and($r->plan['extract']['fields'])->not->toHaveKey('headline_value');

    // The same last key elsewhere is a match; no match is null.
    expect(amMap('{"summary":{"total":1}}', $map)->apiChanged[0]['match'])->toBe(['path' => '$.summary.total', 'display' => 'summary.total'])
        ->and(amMap('{"region":"EU"}', $map)->apiChanged[0]['match'])->toBeNull();
});

it('reads an optional slot whose path left the sample as optional_empty and lists it as a blocker', function () {
    $r = amMap('{"total":1}', ['status_badge' => ['path' => '$.status', 'state' => 'overridden'], 'headline_value' => ['path' => null, 'state' => 'overridden']]);

    expect(amSlot($r, 'status_badge')['state'])->toBe('optional_empty')
        ->and($r->apiChanged[0])->toMatchArray(['slot' => 'status_badge', 'state' => 'optional_empty', 'match' => null])
        ->and($r->blockers)->toContain(['slot' => 'status_badge', 'reason' => 'api_changed'])
        ->and(count($r->apiChanged))->toBe(1);
});

it('fits a string treated as a number exactly when the executor accepts it', function () {
    foreach (['1e5', '+5', '5.', '.5', ' 7 '] as $text) {
        $fields = amFields(json_encode(['v' => $text]), 'headline_value', ['$.v' => 'number']);
        expect($fields[0]['compatible'])->toBeTrue($text)
            ->and(preg_match(ExecutionResult::DECIMAL, trim($text)))->toBe(1);
    }

    foreach (['1e', 'abc', '5 5', '--5', ''] as $text) {
        expect(amFields(json_encode(['v' => $text]), 'headline_value', ['$.v' => 'number'])[0]['compatible'])->toBeFalse($text);
    }
});

// Story 3.13c: every checklist row carries the field's bounded example.
it('gives a checklist row the example of its field, null when it has no field', function () {
    $r = amMap('{"total":284680.50,"label":"Revenue"}');

    expect(amSlot($r, 'headline_value')['example'])->toBe('284680.50')
        ->and(amSlot($r, 'kpi_label')['example'])->toBe('Revenue')
        ->and(amSlot($r, 'subtitle')['example'])->toBeNull();

    $changed = amMap('{"grand_total":1}', ['headline_value' => ['path' => '$.total', 'state' => 'confirmed']]);
    expect(amSlot($changed, 'headline_value')['example'])->toBeNull();
});
