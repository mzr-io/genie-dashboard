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
