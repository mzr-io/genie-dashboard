<?php

use App\Modules\BlockTypes\Application\LoadBlockTypes;
use App\Modules\BlockTypes\Contracts\BlockTypeSlot;
use App\Modules\Mapping\Application\AutoMapper;
use App\Modules\Mapping\Application\FieldRoleInference;
use App\Modules\Mapping\Contracts\AutoMapResult;
use App\Platform\Json\LosslessJson;
use App\Platform\Json\SampleShape;

// Story 3.12a: auto-map of the kpi-card slots over a sample's field report, with confidence, decisions and the plan.
function amMap(string $json, array $slotMap = [], ?string $recordPath = null, array $roles = []): AutoMapResult
{
    $type = (new LoadBlockTypes(dirname(__DIR__, 2).'/block-types'))->resolve('kpi-card', 1);
    $report = (new FieldRoleInference)->infer(SampleShape::of(LosslessJson::decode($json)), $recordPath, $roles);

    return (new AutoMapper)->map($report, $type, $slotMap);
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

it('reads a cleared slot as empty, and a stale confirmed or overridden slot as type_mismatch, not dropped', function () {
    $cleared = amMap('{"total":10}', ['headline_value' => ['path' => null, 'state' => 'overridden']]);
    expect(amSlot($cleared, 'headline_value'))->toMatchArray(['state' => 'required_missing', 'path' => null]);

    $stale = amMap('{"total":10}', ['headline_value' => ['path' => '$.gone', 'state' => 'confirmed']]);
    expect(amSlot($stale, 'headline_value'))->toMatchArray(['state' => 'type_mismatch', 'path' => '$.gone'])
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
