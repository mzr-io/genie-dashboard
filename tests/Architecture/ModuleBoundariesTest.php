<?php

use Tests\Architecture\Support\Scanner;

$fixtures = __DIR__.'/Fixtures';

it('keeps the real tree inside the module graph', function () {
    expect((new Scanner)->boundaryViolations(dirname(__DIR__, 2).'/app'))->toBe([]);
});

it('is a no-op for an app directory without modules', function () {
    expect((new Scanner)->boundaryViolations(__DIR__.'/Fixtures/does-not-exist'))->toBe([]);
});

it('has no reverse edges and no edge to an unknown module', function () {
    $edges = (require __DIR__.'/dependencies.php')['edges'];

    foreach ($edges as $from => $targets) {
        foreach ($targets as $to) {
            expect($edges)->toHaveKey($to)
                ->and($edges[$to])->not->toContain($from);
        }
    }
});

it('fails on an edge missing from dependencies.php and names the file', function () use ($fixtures) {
    $violations = (new Scanner)->boundaryViolations("{$fixtures}/forbidden-edge/app");

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('BadEdge.php.stub')
        ->and($violations[0])->toContain('Dashboards -> Identity');
});

it('fails when a module reaches past another module\'s Contracts', function () use ($fixtures) {
    $violations = (new Scanner)->boundaryViolations("{$fixtures}/past-contracts/app");

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('PastContracts.php.stub')
        ->and($violations[0])->toContain('past Contracts');
});

it('passes an allowed edge to Contracts and a call into the kernel', function () use ($fixtures) {
    expect((new Scanner)->boundaryViolations("{$fixtures}/allowed-edge/app"))->toBe([]);
});

it('fails when the kernel calls a module and names the file', function () use ($fixtures) {
    $violations = (new Scanner)->boundaryViolations("{$fixtures}/kernel-calls-module/app");

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('KernelCallsModule.php.stub')
        ->and($violations[0])->toContain('kernel calls module Blocks');
});

it('fails group imports of App\Modules and names the file', function () use ($fixtures) {
    $violations = (new Scanner)->boundaryViolations("{$fixtures}/group-import/app");

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('GroupImport.php.stub')
        ->and($violations[0])->toContain('group imports of App\Modules are not allowed; one import per class');
});

it('catches class names written with doubled backslashes', function () use ($fixtures) {
    $violations = (new Scanner)->boundaryViolations("{$fixtures}/doubled-backslash/app");

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('Doubled.php.stub')
        ->and($violations[0])->toContain('Dashboards -> Identity');
});

it('does not treat a look-alike namespace as Contracts', function () use ($fixtures) {
    $violations = (new Scanner)->boundaryViolations("{$fixtures}/lookalike-contracts/app");

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('LookAlike.php.stub')
        ->and($violations[0])->toContain('past Contracts');
});

it('has no dependency cycle of any length', function () {
    $edges = (require __DIR__.'/dependencies.php')['edges'];
    $state = [];

    $visit = function (string $node) use (&$visit, &$state, $edges): void {
        expect($state[$node] ?? 0)->not->toBe(1, "cycle through {$node}");

        if (($state[$node] ?? 0) === 2) {
            return;
        }

        $state[$node] = 1;
        foreach ($edges[$node] ?? [] as $next) {
            $visit($next);
        }
        $state[$node] = 2;
    };

    foreach (array_keys($edges) as $node) {
        $visit($node);
    }
});

it('gives every table one owner that is a known module or the kernel', function () {
    $rules = require __DIR__.'/dependencies.php';
    $seen = [];

    foreach ($rules['tables'] as $owner => $tables) {
        expect($owner === $rules['kernel'] || array_key_exists($owner, $rules['edges']))->toBeTrue("unknown owner {$owner}");

        foreach ($tables as $table) {
            expect($seen)->not->toHaveKey($table);
            $seen[$table] = $owner;
        }
    }
});
