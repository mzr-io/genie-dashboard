<?php

use App\Modules\BlockTypes\Application\LoadBlockTypes;
use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Contracts\UnknownBlockType;
use App\Modules\BlockTypes\Infrastructure\PackageShapers;
use App\Support\BlockTypes\BlockTypeGenerator;

// Story 5.17: a Block Type is added by a package alone; the first-party packages conform to the contract.
function extRoot(): string
{
    return dirname(__DIR__, 2).'/block-types';
}

/** A copy of every package plus `example-gauge`, as a root the registry, the Shapers and the generator can all read. */
function extWithGauge(): string
{
    $root = sys_get_temp_dir().'/ext-'.bin2hex(random_bytes(4));
    mkdir($root.'/example-gauge/v1', 0777, true);
    file_put_contents($root.'/example-gauge/v1/schema.json', json_encode([
        'key' => 'example-gauge',
        'contractVersion' => 1,
        'title' => 'Example gauge',
        'slots' => [
            ['key' => 'title', 'label' => 'Title', 'required' => false, 'valueType' => 'text', 'cardinality' => 'single', 'fieldRoles' => ['label']],
            ['key' => 'subtitle', 'label' => 'Subtitle', 'required' => false, 'valueType' => 'text', 'cardinality' => 'single', 'fieldRoles' => ['label']],
            ['key' => 'icon', 'label' => 'Icon', 'required' => false, 'valueType' => 'text', 'cardinality' => 'single', 'fieldRoles' => ['label']],
            ['key' => 'footer_action', 'label' => 'Footer action', 'required' => false, 'valueType' => 'text', 'cardinality' => 'single', 'fieldRoles' => ['label']],
            ['key' => 'reading', 'label' => 'Reading', 'required' => true, 'valueType' => 'number', 'cardinality' => 'single', 'fieldRoles' => ['value']],
        ],
        'size' => ['defaultWidth' => 3, 'minWidth' => 2, 'maxWidth' => 6, 'defaultHeight' => 'small', 'minHeight' => 'small', 'maxHeight' => 'medium'],
        'defaultAggregations' => ['reading' => 'sum'],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($root.'/example-gauge/v1/Renderer.vue', '<template><p>loading empty stale unavailable error</p></template>');
    file_put_contents($root.'/example-gauge/v1/Shaper.php', <<<'PHP'
<?php

namespace BlockTypes\ExampleGauge\V1;

final class Shaper
{
    public function shape(array $slots, array $options = []): array
    {
        return ['state' => 'normal', 'reading' => $slots['reading'] ?? null];
    }
}
PHP);

    return $root;
}

it('finds, shapes and generates a new package without any other file being touched', function () {
    $root = extWithGauge();
    $registry = new LoadBlockTypes($root);
    $type = $registry->resolve('example-gauge', 1);

    // The Shaper is found by its place, not by a list.
    $payload = (new PackageShapers($root))->shape($type, ['reading' => '42'], []);

    expect($payload)->toBe(['state' => 'normal', 'reading' => '42']);

    // Everything the generator writes for it is inside the package, apart from the vocabulary every package shares.
    $files = (new BlockTypeGenerator)->render(BlockTypeGenerator::discover($root));
    $own = array_keys(array_filter($files, fn (string $_, string $path): bool => str_contains($path, 'example-gauge'), ARRAY_FILTER_USE_BOTH));

    expect($own)->toBe(['block-types/example-gauge/v1/'.BlockTypeGenerator::PACKAGE_TS])
        ->and(array_keys($files))->toBe(['block-types/example-gauge/v1/'.BlockTypeGenerator::PACKAGE_TS, BlockTypeGenerator::TS_OUTPUT]);
});

it('does not find a Shaper for a key that is not a package or is not a safe name', function () {
    $shapers = new PackageShapers(extRoot());
    $type = (new LoadBlockTypes(extRoot()))->resolve('kpi-card', 1);
    $ghost = new BlockType('ghost', 1, 'Ghost', $type->slots, $type->size, []);
    $evil = new BlockType('../app', 1, 'Evil', $type->slots, $type->size, []);

    expect(fn () => $shapers->shape($ghost, [], []))->toThrow(UnknownBlockType::class)
        ->and(fn () => $shapers->shape($evil, [], []))->toThrow(UnknownBlockType::class);
});

it('has every first-party package declare the common slots, a value type and a cardinality for each slot, and a default aggregation for numeric chart rows', function () {
    $types = (new LoadBlockTypes(extRoot()))->all();

    expect(count($types))->toBe(11);

    foreach ($types as $type) {
        $keys = array_map(fn ($s): string => $s->key, $type->slots);

        expect($keys)->toContain('title', 'subtitle', 'icon', 'footer_action');

        foreach ($type->slots as $slot) {
            expect(['number', 'text', 'datetime', 'boolean', 'any'])->toContain($slot->valueType)
                ->and(['single', 'rows'])->toContain($slot->cardinality);

            if (in_array($type->key, ['line-area', 'bar', 'pie-donut', 'kpi-chart'], true) && $slot->valueType === 'number' && $slot->cardinality === 'rows') {
                expect(array_key_exists($slot->key, $type->defaultAggregations))->toBeTrue("{$type->key}.{$slot->key} has no default aggregation");
            }
        }
    }
});

it('has no Shaper that reaches the application, the framework or the database', function () {
    foreach (glob(extRoot().'/*/v*/Shaper.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);

        expect(preg_match('/^use\s+(App|Illuminate)\\\\/m', $source))->toBe(0, basename(dirname($file, 2)).' imports the core')
            ->and(preg_match('/\\\\(App|Illuminate)\\\\/', $source))->toBe(0, basename(dirname($file, 2)).' names the core');
    }
});
