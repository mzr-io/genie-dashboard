<?php

use App\Modules\Blocks\Application\DraftStrictCheck;
use App\Modules\BlockTypes\Application\LoadBlockTypes;

// Story 3.18: the strict-schema check reads the stored config and reports each fault as a value-free {path, slot, type, expected}.
function strictType()
{
    return (new LoadBlockTypes(dirname(__DIR__, 2).'/block-types'))->resolve('kpi-card', 1);
}

function strictPlan(array $shape = ['headline_value' => 'rev', 'kpi_label' => 'name'], array $fields = ['rev' => '$.total', 'name' => '$.label']): array
{
    return ['v' => 1, 'extract' => ['fields' => $fields], 'shape' => $shape];
}

function strictTypes(array $config): array
{
    return array_values(array_unique(array_column(DraftStrictCheck::check($config, strictType()), 'type')));
}

it('accepts a complete Draft', function () {
    expect(DraftStrictCheck::check(['schema_version' => 1, 'query_plan' => strictPlan()], strictType()))->toBe([]);
});

it('reports no plan', function () {
    expect(DraftStrictCheck::check(['schema_version' => 1], strictType()))->toBe([
        ['path' => 'config.query_plan', 'slot' => null, 'type' => 'no_plan', 'expected' => null],
        ['path' => null, 'slot' => 'kpi_label', 'type' => 'required_slot_unmapped', 'expected' => 'text'],
        ['path' => null, 'slot' => 'headline_value', 'type' => 'required_slot_unmapped', 'expected' => 'number'],
    ]);
});

it('reports an unmapped required slot', function () {
    expect(DraftStrictCheck::check(['query_plan' => strictPlan(['headline_value' => 'rev'], ['rev' => '$.total'])], strictType()))
        ->toBe([['path' => null, 'slot' => 'kpi_label', 'type' => 'required_slot_unmapped', 'expected' => 'text']]);
});

it('reports a plan that fails its type check', function () {
    $faults = DraftStrictCheck::check(['query_plan' => ['v' => 2, 'extract' => ['fields' => ['rev' => 'not a path']], 'shape' => ['nope' => 'rev']]], strictType());

    expect(array_unique(array_column($faults, 'type')))->toContain('invalid_plan');
});

it('reports an invalid presentation', function () {
    $faults = DraftStrictCheck::check(['query_plan' => strictPlan(), 'presentation' => ['slots' => ['headline_value' => ['format' => ['kind' => 'bogus']]]]], strictType());

    expect(array_column($faults, 'type'))->toContain('invalid_presentation')
        ->and($faults[0]['path'])->toStartWith('config.presentation');
});

it('reports an invalid configuration', function () {
    expect(strictTypes(['query_plan' => strictPlan(), 'icon' => 'Not An Icon', 'display' => 'wide']))->toBe(['invalid_config']);
});
