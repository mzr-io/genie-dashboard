<?php

use Tests\Architecture\Support\Scanner;

$fixtures = __DIR__.'/Fixtures';

it('keeps every query on its own tables in the real tree', function () {
    expect((new Scanner)->tableViolations(dirname(__DIR__, 2).'/app'))->toBe([]);
});

it('fails when a module queries another module\'s table and names the file', function () use ($fixtures) {
    $violations = (new Scanner)->tableViolations("{$fixtures}/cross-module-table/app");

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('CrossTable.php.stub')
        ->and($violations[0])->toContain('Dashboards queries table blocks owned by Blocks');
});

it('passes a module querying its own table', function () use ($fixtures) {
    expect((new Scanner)->tableViolations("{$fixtures}/own-table/app"))->toBe([]);
});

it('fails a join on another module\'s table and names the file', function () use ($fixtures) {
    $violations = (new Scanner)->tableViolations("{$fixtures}/join-table/app");

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('JoinTable.php.stub')
        ->and($violations[0])->toContain('table blocks owned by Blocks');
});

it('fails raw SQL on another module\'s table and names the file', function () use ($fixtures) {
    $violations = (new Scanner)->tableViolations("{$fixtures}/raw-sql/app");

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('RawSql.php.stub')
        ->and($violations[0])->toContain('table blocks owned by Blocks');
});

it('lets only WorkspaceTransaction set the Workspace context in the real tree', function () {
    expect((new Scanner)->workspaceContextViolations(dirname(__DIR__, 2).'/app'))->toBe([]);

    $transaction = (string) file_get_contents(dirname(__DIR__, 2).'/app/Platform/Tenancy/WorkspaceTransaction.php');
    expect($transaction)->toContain('set_config(?, ?, true)');
});

it('fails set_config outside WorkspaceTransaction and names the file', function () use ($fixtures) {
    $violations = (new Scanner)->workspaceContextViolations("{$fixtures}/set-config/app");

    expect($violations)->not->toBeEmpty()
        ->and($violations[0])->toContain('RogueContext.php.stub')
        ->and($violations[0])->toContain('only WorkspaceTransaction may');
});

it('allows set_config inside WorkspaceTransaction', function () use ($fixtures) {
    expect((new Scanner)->workspaceContextViolations("{$fixtures}/set-config-clean/app"))->toBe([]);
});

it('exempts only app/Platform/Tenancy/WorkspaceTransaction.php, not a look-alike path', function () use ($fixtures) {
    $violations = (new Scanner)->workspaceContextViolations("{$fixtures}/set-config-lookalike/app");

    expect($violations)->not->toBeEmpty()
        ->and($violations[0])->toContain('Modules/Dashboards/Platform/Tenancy/WorkspaceTransaction.php.stub');
});
