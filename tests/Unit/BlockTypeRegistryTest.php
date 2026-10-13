<?php

use App\Modules\BlockTypes\Application\LoadBlockTypes;
use App\Modules\BlockTypes\Contracts\InvalidBlockTypePackage;
use App\Modules\BlockTypes\Contracts\KpiCardSlots;
use App\Modules\BlockTypes\Contracts\UnknownBlockType;
use App\Support\BlockTypes\BlockTypeGenerator;

// Story 3.1: the registry, its boot validation, pinned resolution and the codegen drift check.
function btRoot(): string
{
    return dirname(__DIR__, 2).'/block-types';
}

/** A copy of the kpi-card v1 package under a temp root, its schema.json changed by `$change`. */
function btCopy(?Closure $change = null, string $key = 'kpi-card'): string
{
    $root = sys_get_temp_dir().'/block-types-'.bin2hex(random_bytes(4));
    $dir = "{$root}/{$key}/v1";
    mkdir($dir, 0777, true);

    foreach (['Shaper.php', 'Renderer.vue'] as $file) {
        copy(btRoot().'/kpi-card/v1/'.$file, "{$dir}/{$file}");
    }

    $schema = json_decode((string) file_get_contents(btRoot().'/kpi-card/v1/schema.json'), true);
    $schema['key'] = $key;
    $schema = $change ? $change($schema) : $schema;
    file_put_contents("{$dir}/schema.json", json_encode($schema));

    return $root;
}

it('lists every Block Type v1 with their slots, size limits and default aggregations', function () {
    $all = (new LoadBlockTypes(btRoot()))->all();

    expect(array_map(fn ($t) => $t->key, $all))->toBe(['activity-feed', 'bar', 'calendar-agenda', 'kpi-card', 'kpi-chart', 'line-area', 'list', 'pie-donut', 'progress-list', 'table', 'text-status']);

    $type = $all[3]->toArray();

    expect($type['key'])->toBe('kpi-card')
        ->and($type['contract_version'])->toBe(1)
        ->and(array_column($type['slots'], 'key'))->toBe(['title', 'subtitle', 'icon', 'footer_action', 'kpi_label', 'headline_value', 'comparison', 'comparison_label', 'secondary_note', 'status_badge'])
        ->and(array_column(array_filter($type['slots'], fn ($s) => $s['required']), 'key'))->toBe(['kpi_label', 'headline_value'])
        ->and($type['size']['default_width'])->toBe(3)
        ->and($type['size']['default_height'])->toBe('small')
        ->and($type['default_aggregations'])->toBe(['headline_value' => 'sum', 'comparison' => 'sum']);
});

it('resolves a pinned (key, version) and never falls back', function () {
    $registry = new LoadBlockTypes(btRoot());

    expect($registry->resolve('kpi-card', 1)->key)->toBe('kpi-card');
    expect(fn () => $registry->resolve('kpi-card', 2))->toThrow(UnknownBlockType::class, 'kpi-card v2');
    expect(fn () => $registry->resolve('kpi-card', 0))->toThrow(UnknownBlockType::class);
    expect(fn () => $registry->resolve('latest', 1))->toThrow(UnknownBlockType::class);
});

it('fails boot naming the package and slot on an unknown value type', function () {
    $root = btCopy(function (array $s) {
        $s['slots'][5]['valueType'] = 'decimal';

        return $s;
    });
    $registry = new LoadBlockTypes($root);

    expect(fn () => $registry->all())->toThrow(InvalidBlockTypePackage::class, 'kpi-card/v1, slot headline_value: unknown value type "decimal"');
});

it('fails boot naming the package and slot on a missing cardinality', function () {
    $root = btCopy(function (array $s) {
        unset($s['slots'][4]['cardinality']);

        return $s;
    });

    expect(fn () => (new LoadBlockTypes($root))->all())->toThrow(InvalidBlockTypePackage::class, 'kpi-card/v1, slot kpi_label: cardinality is missing');
});

it('registers nothing when one package is invalid, even if another is fine', function () {
    $root = btCopy();
    $bad = btCopy(function (array $s) {
        $s['slots'][0]['valueType'] = 'nope';

        return $s;
    }, 'zz-bad');
    exec('cp -r '.escapeshellarg($bad.'/zz-bad').' '.escapeshellarg($root.'/zz-bad'));
    $registry = new LoadBlockTypes($root);

    expect(fn () => $registry->all())->toThrow(InvalidBlockTypePackage::class, 'zz-bad/v1, slot title')
        ->and(fn () => $registry->resolve('kpi-card', 1))->toThrow(InvalidBlockTypePackage::class);
});

it('rejects a package whose key or version disagrees with its directory, or that lacks a file', function () {
    $root = btCopy(fn (array $s) => ['contractVersion' => 2] + $s);
    expect(fn () => (new LoadBlockTypes($root))->all())->toThrow(InvalidBlockTypePackage::class, 'contractVersion');

    $root = btCopy();
    unlink($root.'/kpi-card/v1/Renderer.vue');
    expect(fn () => (new LoadBlockTypes($root))->all())->toThrow(InvalidBlockTypePackage::class, 'Renderer.vue is missing');
});

it('keeps the generated files in step with schema.json, and names a stale one', function () {
    $base = dirname(__DIR__, 2);
    $generator = new BlockTypeGenerator;
    $files = $generator->render(BlockTypeGenerator::discover($base.'/block-types'));

    expect(array_keys($files))->toBe([BlockTypeGenerator::PHP_DIR.'/ActivityFeedSlots.php', BlockTypeGenerator::PHP_DIR.'/BarSlots.php', BlockTypeGenerator::PHP_DIR.'/CalendarAgendaSlots.php', BlockTypeGenerator::PHP_DIR.'/KpiCardSlots.php', BlockTypeGenerator::PHP_DIR.'/KpiChartSlots.php', BlockTypeGenerator::PHP_DIR.'/LineAreaSlots.php', BlockTypeGenerator::PHP_DIR.'/ListSlots.php', BlockTypeGenerator::PHP_DIR.'/PieDonutSlots.php', BlockTypeGenerator::PHP_DIR.'/ProgressListSlots.php', BlockTypeGenerator::PHP_DIR.'/TableSlots.php', BlockTypeGenerator::PHP_DIR.'/TextStatusSlots.php', BlockTypeGenerator::TS_OUTPUT]);

    foreach ($files as $path => $content) {
        expect(file_get_contents($base.'/'.$path))->toBe($content, "{$path} is stale");
    }

    // A changed schema.json produces different output: that is the drift the check catches.
    $changed = $generator->render(BlockTypeGenerator::discover(btCopy(function (array $s) {
        $s['size']['defaultWidth'] = 4;

        return $s;
    })));

    expect($changed[BlockTypeGenerator::TS_OUTPUT])->not->toBe($files[BlockTypeGenerator::TS_OUTPUT])
        ->and(KpiCardSlots::DEFAULT_WIDTH)->toBe(3);
});

it('ignores directories that are not v{N} and rejects a key that is not kebab-case', function () {
    $root = btCopy();
    mkdir($root.'/kpi-card/vnext');
    mkdir($root.'/kpi-card/v01');
    mkdir($root.'/notes');

    expect((new LoadBlockTypes($root))->all())->toHaveCount(1);

    $bad = btCopy(null, 'Bad_Key');

    expect(fn () => (new LoadBlockTypes($bad))->all())->toThrow(InvalidBlockTypePackage::class, 'Bad_Key/v1: the package key must be lowercase kebab-case');
});
