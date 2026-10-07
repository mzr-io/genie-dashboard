<?php

use Tests\Architecture\Support\Scanner;

$fixtures = __DIR__.'/Fixtures';

it('limits global tables to the agreed list', function () {
    $global = (require __DIR__.'/dependencies.php')['global_tables'];

    expect($global)->toEqualCanonicalizing([
        'users', 'sessions', 'password_reset_tokens', 'invitations',
        'service_health_samples', 'operator_audit', 'personal_access_tokens', 'workspaces',
        'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks',
    ]);
});

it('passes the real migrations', function () {
    expect(glob(dirname(__DIR__, 2).'/database/migrations/*.php'))->not->toBeEmpty();
    expect((new Scanner)->migrationViolations(dirname(__DIR__, 2).'/database/migrations'))->toBe([]);
});

it('fails a migration creating a non-global table without workspace_id and names it', function () use ($fixtures) {
    $violations = (new Scanner)->migrationViolations("{$fixtures}/migrations-bad");

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('create_widgets_table.php.stub')
        ->and($violations[0])->toContain('widgets');
});

it('passes global tables and tenant tables carrying workspace_id', function () use ($fixtures) {
    expect((new Scanner)->migrationViolations("{$fixtures}/migrations-good"))->toBe([]);
});

it('reports only the table lacking workspace_id when a later table has it', function () use ($fixtures) {
    $violations = (new Scanner)->migrationViolations("{$fixtures}/migrations-boundary");

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('create_two_tables.php.stub')
        ->and($violations[0])->toContain('gadgets');
});

it('fails raw create table SQL without workspace_id', function () use ($fixtures) {
    $violations = (new Scanner)->migrationViolations("{$fixtures}/migrations-raw");

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('create_gizmos_table.php.stub')
        ->and($violations[0])->toContain('gizmos');
});

it('passes a partitioned table that carries workspace_id and row-level security on the parent and every partition', function () use ($fixtures) {
    expect((new Scanner)->migrationViolations("{$fixtures}/migrations-partition-good"))->toBe([])
        ->and((new Scanner)->partitionViolations("{$fixtures}/migrations-partition-good"))->toBe([]);
});

it('does not ask a partition for its own workspace_id, but fails a partitioned table without one and names it', function () use ($fixtures) {
    $violations = (new Scanner)->migrationViolations("{$fixtures}/migrations-partition-no-workspace");

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('create_events.php.stub')
        ->and($violations[0])->toContain('events');
});

it('fails a migration that partitions a table without forcing row-level security and a policy, and names the file', function () use ($fixtures) {
    $violations = (new Scanner)->partitionViolations("{$fixtures}/migrations-partition-bad");

    expect($violations)->toHaveCount(2)
        ->and($violations[0])->toContain('create_runs.php.stub')->toContain('force row level security')
        ->and($violations[1])->toContain('create policy');
});

it('keeps every real partitioned table under row-level security', function () {
    expect((new Scanner)->partitionViolations(dirname(__DIR__, 2).'/database/migrations'))->toBe([]);
});
