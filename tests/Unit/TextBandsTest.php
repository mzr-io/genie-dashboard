<?php

use App\Modules\Blocks\Application\DraftConfig;
use App\Modules\Blocks\Contracts\InvalidDraft;
use App\Modules\BlockTypes\Application\LoadBlockTypes;
use App\Modules\BlockTypes\Infrastructure\PackageShapers;
use App\Modules\Mapping\Application\DecimalMath;
use App\Modules\Mapping\Application\PresentationOptions;
use App\Modules\Mapping\Application\QueryPlanEngine;
use App\Modules\Mapping\Infrastructure\InProcessExecutor;
use App\Modules\Mapping\Infrastructure\PreviewSettings;
use App\Platform\Json\LosslessJson;
use Illuminate\Config\Repository;

// Story 5.7: a band can match the status text (a text slot), the first match from the top wins across both kinds, and a subject without a value matches none.
it('matches a text band on the status text, ignoring case, and keeps the top-to-bottom order across numeric and text bands', function () {
    $options = new PresentationOptions;
    $bands = [
        ['op' => 'equals', 'value' => 'At risk', 'color' => 'error', 'icon' => 'alert', 'label' => 'Needs attention'],
        ['op' => '>=', 'value' => '80', 'color' => 'success', 'icon' => 'check', 'label' => 'Healthy'],
    ];

    expect($options->firstBand($bands, '90', 'at risk ')['label'])->toBe('Needs attention')
        ->and($options->firstBand($bands, '90', 'On track')['label'])->toBe('Healthy')
        ->and($options->firstBand($bands, '80', null)['label'])->toBe('Healthy')
        ->and($options->firstBand($bands, null, 'On track'))->toBeNull()
        ->and($options->firstBand($bands, '79.99', ''))->toBeNull();
});

it('refuses a text band with no word and a numeric band with text', function () {
    $type = (new LoadBlockTypes(dirname(__DIR__, 2).'/block-types'))->resolve('kpi-card', 1);
    $good = ['bands' => [['op' => 'equals', 'value' => 'On track', 'color' => 'success', 'icon' => 'check', 'label' => 'On track']]];

    expect(DraftConfig::normalisePresentation($good, $type))->toEqual($good);

    foreach ([
        ['bands' => [['op' => 'equals', 'value' => '  ', 'color' => 'success', 'icon' => 'check', 'label' => 'x']]],
        ['bands' => [['op' => '>=', 'value' => 'On track', 'color' => 'success', 'icon' => 'check', 'label' => 'x']]],
        ['bands' => [['op' => 'equals', 'value' => 'a', 'to' => '1', 'color' => 'accent', 'icon' => 'check', 'label' => 'x']]],
    ] as $bad) {
        expect(fn () => DraftConfig::normalisePresentation($bad, $type))->toThrow(InvalidDraft::class);
    }
});

it('shows the band of a text match as the status badge, with its icon and word', function () {
    $preview = [];

    foreach (['membership_limit' => '2', 'window_seconds' => '60', 'max_extracted_values' => '1000', 'max_evaluations' => '100', 'max_wall_ms' => '1000'] as $name => $value) {
        $preview[$name] = ['value' => $value];
    }

    $config = new Repository(['dashflow' => ['preview' => $preview, 'tunables' => ['numbers' => ['decimal_scale' => ['value' => '6']]]]]);
    $engine = new QueryPlanEngine(new InProcessExecutor(null, new DecimalMath($config)), new PackageShapers, new PreviewSettings($config));
    $type = (new LoadBlockTypes(dirname(__DIR__, 2).'/block-types'))->resolve('kpi-card', 1);
    $plan = ['v' => 1, 'extract' => ['fields' => ['label' => '$.label', 'total' => '$.total', 'state' => '$.status']], 'shape' => ['kpi_label' => 'label', 'headline_value' => 'total', 'status_badge' => 'state']];
    $rules = ['bands' => [['op' => 'equals', 'value' => 'At risk', 'color' => 'warning', 'icon' => 'alert', 'label' => 'Needs attention']]];

    $hit = $engine->run($type, $plan, LosslessJson::decode('{"label":"Revenue","total":10,"status":"AT RISK"}'), $engine->guards(), $rules);
    $miss = $engine->run($type, $plan, LosslessJson::decode('{"label":"Revenue","total":10,"status":"On track"}'), $engine->guards(), $rules);

    expect($hit->payload['statusBadge'])->toBe(['text' => 'Needs attention', 'color' => 'warning', 'icon' => 'alert'])
        ->and($miss->payload['statusBadge'])->toBe('On track');
});
