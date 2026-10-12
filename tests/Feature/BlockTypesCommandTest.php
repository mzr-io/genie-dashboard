<?php

use App\Modules\BlockTypes\Application\LoadBlockTypes;
use App\Modules\BlockTypes\Contracts\BlockTypeRegistry;
use App\Modules\BlockTypes\Contracts\InvalidBlockTypePackage;
use App\Support\BlockTypes\BlockTypeGenerator;

// Story 3.1: the drift check as a command, and the container's registry binding.
it('passes --check when the generated files are in sync', function () {
    $this->artisan('dashflow:block-types', ['--check' => true])->assertExitCode(0);
});

it('fails --check with "stale" when a generated file differs, and the file is restored afterwards', function () {
    $path = base_path(BlockTypeGenerator::TS_OUTPUT);
    $original = file_get_contents($path);

    try {
        file_put_contents($path, $original."// drift\n");
        $this->artisan('dashflow:block-types', ['--check' => true])->expectsOutputToContain('stale')->assertExitCode(1);
    } finally {
        file_put_contents($path, $original);
    }

    $this->artisan('dashflow:block-types', ['--check' => true])->assertExitCode(0);
});

it('binds the registry to base_path(block-types) and throws on an invalid root', function () {
    expect(app(BlockTypeRegistry::class))->toBeInstanceOf(LoadBlockTypes::class)
        ->and(app(BlockTypeRegistry::class)->all())->toHaveCount(1);

    $root = sys_get_temp_dir().'/bt-bad-'.bin2hex(random_bytes(4));
    mkdir("{$root}/kpi-card/v1", 0777, true);
    app()->singleton(BlockTypeRegistry::class, fn () => new LoadBlockTypes($root));

    expect(fn () => app(BlockTypeRegistry::class)->all())->toThrow(InvalidBlockTypePackage::class);
});
