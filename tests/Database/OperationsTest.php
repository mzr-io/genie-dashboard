<?php

use App\Modules\Connector\Application\RunConnectionTest;
use App\Platform\Operations\Operation;
use App\Platform\Operations\OperationKind;
use App\Platform\Operations\OperationKinds;
use App\Platform\Operations\OperationOutcome;
use App\Platform\Operations\Operations;
use App\Platform\Operations\OperationStatus;
use App\Platform\Operations\RunOperation;
use App\Platform\Tenancy\WorkspaceTransaction;
use App\Support\Observability\RequestContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Database\Fixtures\ScriptedOperationHandler;
use Tests\Database\Support\Cluster;

// Story 2.5: the Operations kernel on its own, with a scripted handler: the lifecycle, the guarantees around the handler
// (clean-up in a `finally`, no leak from a failure), the completion event in the status transaction and the read rules.
beforeEach(function () {
    ScriptedOperationHandler::reset();
    app(OperationKinds::class)->register(new OperationKind('scripted', 'maintenance', 60, ScriptedOperationHandler::class));
});

function opEnqueue(string $workspace, ?string $requester = null, array $input = [], string $kind = 'scripted'): Operation
{
    return app(Operations::class)->enqueue($workspace, $kind, $requester ?? (string) Str::uuid7(), 'data_source_draft', input: $input);
}

function opRun(string $workspace, Operation $operation, array $input = []): void
{
    app(Operations::class)->run($workspace, $operation->id, $input);
}

function opRow(string $id): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from operations where id = ?', [$id])[0];
}

it('stores a queued Operation with a UUIDv7 key, the request ID, the kind and its lifetime, and dispatches after the commit on the kind\'s queue', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    $requester = (string) Str::uuid7();
    app(RequestContext::class)->begin('req-operations-0001');

    $operation = opEnqueue($workspace, $requester, ['a' => 1]);

    $row = opRow($operation->id);
    expect(substr($operation->id, 14, 1))->toBe('7')
        ->and($row)->toMatchArray(['workspace_id' => $workspace, 'kind' => 'scripted', 'requester_membership_id' => $requester, 'subject_type' => 'data_source_draft', 'subject_id' => null, 'subject_revision' => null, 'status' => 'queued', 'result' => null])
        ->and($row['request_id'])->toBe('req-operations-0001')
        ->and(strtotime($row['expires_at']) - time())->toBeBetween(55, 61);

    Queue::assertPushedOn('maintenance', RunOperation::class, fn (RunOperation $job) => $job->operationId === $operation->id && $job->input === ['a' => 1] && $job->workspaceId() === $workspace);
});

it('dispatches nothing when the enclosing transaction rolls back, and stores nothing', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');

    expect(fn () => app(WorkspaceTransaction::class)->run($workspace, function () use ($workspace) {
        opEnqueue($workspace);

        throw new RuntimeException('roll back');
    }))->toThrow(RuntimeException::class);

    Queue::assertNothingPushed();
    expect(Cluster::rows(Cluster::superuser(), 'select count(*) as n from operations')[0]['n'])->toBe(0);
});

it('refuses a kind nobody registered, a duplicate registration and a malformed requester', function () {
    $workspace = Cluster::workspace('Acme');

    expect(fn () => opEnqueue($workspace, kind: 'nobody'))->toThrow(InvalidArgumentException::class, 'not registered')
        ->and(fn () => app(OperationKinds::class)->register(new OperationKind('scripted', 'maintenance', 60, ScriptedOperationHandler::class)))->toThrow(InvalidArgumentException::class, 'already registered')
        ->and(fn () => app(Operations::class)->enqueue($workspace, 'scripted', 'not-a-uuid', 'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new OperationKind('Bad Name', 'q', 60, ScriptedOperationHandler::class))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new OperationKind('ok', 'q', 0, ScriptedOperationHandler::class))->toThrow(InvalidArgumentException::class);
});

it('runs the handler, stores its summary, cleans up and emits the completion event in the same transaction as the status change', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    $requester = (string) Str::uuid7();
    $operation = opEnqueue($workspace, $requester);

    opRun($workspace, $operation, ['x' => 'y']);

    $row = opRow($operation->id);
    $events = Cluster::rows(Cluster::superuser(), "select * from outbox_events where type = 'platform.operation.completed'");

    expect($row['status'])->toBe('succeeded')
        ->and(json_decode($row['result'], true))->toEqual(['ok' => true, 'status' => 200])
        ->and(ScriptedOperationHandler::$inputs)->toBe([['x' => 'y']])
        ->and(ScriptedOperationHandler::$cleaned)->toBe([$operation->id])
        ->and($events)->toHaveCount(1)
        ->and($events[0]['subject'])->toBe('operation:'.$operation->id)
        ->and($events[0]['actor'])->toBe($requester)
        ->and(json_decode($events[0]['data'], true))->toEqualCanonicalizing(['operation_id' => $operation->id, 'kind' => 'scripted', 'status' => 'succeeded']);
});

it('marks an Operation failed when the handler says so', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    $operation = opEnqueue($workspace);
    ScriptedOperationHandler::$mode = 'fail';

    opRun($workspace, $operation);

    expect(opRow($operation->id)['status'])->toBe('failed')
        ->and(json_decode(opRow($operation->id)['result'], true))->toEqual(['ok' => false, 'code' => 'fetch-failed']);
});

it('cleans up in a finally when the handler throws, fails the Operation with a generic code and logs the class only', function () {
    Queue::fake();
    Log::spy();
    $workspace = Cluster::workspace('Acme');
    $operation = opEnqueue($workspace);
    ScriptedOperationHandler::$mode = 'throw';

    opRun($workspace, $operation);

    expect(ScriptedOperationHandler::$cleaned)->toBe([$operation->id])
        ->and(opRow($operation->id)['status'])->toBe('failed')
        ->and(json_decode(opRow($operation->id)['result'], true))->toEqual(['ok' => false, 'code' => 'operation-failed'])
        ->and(json_encode(Cluster::rows(Cluster::superuser(), 'select * from operations')))->not->toContain('s3cr3t-canary');

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $message === 'platform.operation.handler_failed'
        && $context['exception'] === RuntimeException::class && ! str_contains(json_encode($context), 's3cr3t-canary'))->once();
});

it('still records the outcome when the clean-up itself fails', function () {
    Queue::fake();
    Log::spy();
    $workspace = Cluster::workspace('Acme');
    $operation = opEnqueue($workspace);
    ScriptedOperationHandler::$cleanupThrows = true;

    opRun($workspace, $operation);

    expect(opRow($operation->id)['status'])->toBe('succeeded');
    Log::shouldHaveReceived('error')->withArgs(fn (string $message) => $message === 'platform.operation.cleanup_failed')->once();
});

it('runs an Operation once: a second run, or a run of an ended one, does nothing', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    $operation = opEnqueue($workspace);

    opRun($workspace, $operation);
    opRun($workspace, $operation);

    expect(ScriptedOperationHandler::$inputs)->toHaveCount(1)
        ->and(app(Operations::class)->complete($workspace, $operation->id, OperationStatus::Failed, ['ok' => false]))->toBeFalse()
        ->and(opRow($operation->id)['status'])->toBe('succeeded')
        ->and(Cluster::rows(Cluster::superuser(), "select count(*) as n from outbox_events where type = 'platform.operation.completed'")[0]['n'])->toBe(1);
});

it('expires an Operation whose lifetime passed before the worker got to it: no handler call, clean-up still runs', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    $operation = opEnqueue($workspace);
    Cluster::superuser()->exec("update operations set expires_at = now() - interval '1 second' where id = '{$operation->id}'");

    opRun($workspace, $operation);

    expect(ScriptedOperationHandler::$inputs)->toBe([])
        ->and(ScriptedOperationHandler::$cleaned)->toBe([$operation->id])
        ->and(opRow($operation->id)['status'])->toBe('expired');
});

it('fails an Operation whose kind is no longer registered', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    $operation = opEnqueue($workspace);
    Cluster::superuser()->exec("update operations set kind = 'retired' where id = '{$operation->id}'");

    opRun($workspace, $operation);

    expect(opRow($operation->id)['status'])->toBe('failed')
        ->and(json_decode(opRow($operation->id)['result'], true))->toEqual(['ok' => false, 'code' => 'operation-failed']);
});

it('stores the status and emits the event together: a failure after the status change rolls both back', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    $operation = opEnqueue($workspace);

    expect(fn () => app(WorkspaceTransaction::class)->run($workspace, function () use ($workspace, $operation) {
        app(Operations::class)->complete($workspace, $operation->id, OperationStatus::Succeeded, ['ok' => true]);

        throw new RuntimeException('after the status change');
    }))->toThrow(RuntimeException::class);

    expect(opRow($operation->id)['status'])->toBe('queued')
        ->and(Cluster::rows(Cluster::superuser(), "select count(*) as n from outbox_events where type = 'platform.operation.completed'")[0]['n'])->toBe(0);
});

it('keeps the summary small: scalars only, short strings, snake_case keys, an ended status', function (array $summary) {
    expect(fn () => new OperationOutcome(true, $summary))->toThrow(InvalidArgumentException::class);
})->with([
    'a nested list' => [['body' => ['a', 'b']]],
    'a float' => [['ratio' => 0.5]],
    'a long string' => [['text' => str_repeat('x', OperationOutcome::VALUE_MAX + 1)]],
    'a camelCase key' => [['statusCode' => 1]],
    'an upper-case key' => [['OK' => true]],
]);

it('refuses to complete with a status that has not ended', function () {
    $workspace = Cluster::workspace('Acme');

    expect(fn () => app(Operations::class)->complete($workspace, (string) Str::uuid7(), OperationStatus::Running))->toThrow(InvalidArgumentException::class);
});

it('answers status only to the membership that asked, in the Workspace it was asked in', function () {
    Queue::fake();
    $a = Cluster::workspace('Acme');
    $b = Cluster::workspace('Beta');
    $requester = (string) Str::uuid7();
    $operation = opEnqueue($a, $requester);
    $operations = app(Operations::class);

    expect($operations->status($a, $operation->id, $requester)?->id)->toBe($operation->id)
        ->and($operations->status($a, strtoupper($operation->id), strtoupper($requester))?->id)->toBe($operation->id)
        ->and($operations->status($a, $operation->id, (string) Str::uuid7()))->toBeNull()
        ->and($operations->status($b, $operation->id, $requester))->toBeNull()
        ->and($operations->status($a, 'nope', $requester))->toBeNull()
        ->and($operations->status($a, (string) Str::uuid7(), $requester))->toBeNull();
});

it('denies the app role DELETE on operations and sync_runs and keeps both under forced row-level security', function () {
    foreach (['operations', 'sync_runs'] as $table) {
        $flags = Cluster::rows(Cluster::superuser(), 'select relrowsecurity, relforcerowsecurity, pg_get_userbyid(relowner) as owner from pg_class where oid = ?::regclass', [$table])[0];
        expect($flags)->toMatchArray(['relrowsecurity' => true, 'relforcerowsecurity' => true, 'owner' => 'migrator'])
            ->and(Cluster::rows(Cluster::superuser(), "select has_table_privilege('app', ?, 'DELETE') as p", [$table])[0]['p'])->toBeFalse();
    }
});

it('routes the connection_test kind to fetch-interactive, which only the connector supervisor consumes', function () {
    $kinds = app(OperationKinds::class);

    expect($kinds->has('connection_test'))->toBeTrue()
        ->and($kinds->get('connection_test')->queue)->toBe('fetch-interactive')
        ->and($kinds->get('connection_test')->handler)->toBe(RunConnectionTest::class)
        ->and(config('horizon.environments.connector.supervisor-connector.queue'))->toContain('fetch-interactive');

    foreach (config('horizon.environments') as $environment => $supervisors) {
        if ($environment === 'connector') {
            continue;
        }

        foreach ($supervisors as $supervisor) {
            expect($supervisor['queue'])->not->toContain('fetch-interactive');
        }
    }
});

function opStageSecret(string $workspace, string $operationId): void
{
    Cluster::superuser()->prepare("insert into secrets (id, workspace_id, data_source_id, operation_id, ephemeral, expires_at, slot, purpose, key_version, key_ref, ciphertext, created_at, updated_at) values (?, ?, null, ?, true, now() + interval '1 hour', 'bearer_token', 'cred', 3, 'x', decode('00ff', 'hex'), now(), now())")
        ->execute([(string) Str::uuid7(), $workspace, $operationId]);
}

it('fails the Operation, removes its transient secrets and emits the event when the dispatch fails, without enqueue throwing', function () {
    $workspace = Cluster::workspace('Acme');
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andReturnUsing(function (RunOperation $job) use ($workspace) {
        opStageSecret($workspace, $job->operationId);

        throw new RuntimeException('queue is down');
    });
    app()->instance(Dispatcher::class, $dispatcher);

    $operation = opEnqueue($workspace);

    expect(opRow($operation->id)['status'])->toBe('failed')
        ->and(json_decode(opRow($operation->id)['result'], true))->toEqual(['ok' => false, 'code' => 'operation-failed'])
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from secrets')[0]['n'])->toBe(0)
        ->and(Cluster::rows(Cluster::superuser(), "select count(*) as n from outbox_events where type = 'platform.operation.completed'")[0]['n'])->toBe(1);
});

it('removes the transient secrets of an Operation whose kind is no longer registered', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    $operation = opEnqueue($workspace);
    opStageSecret($workspace, $operation->id);
    Cluster::superuser()->exec("update operations set kind = 'retired' where id = '{$operation->id}'");

    opRun($workspace, $operation);

    expect(opRow($operation->id)['status'])->toBe('failed')
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from secrets')[0]['n'])->toBe(0);
});

it('runs the handler under a savepoint: a database error in it leaves the clean-up and the final status possible', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    $operation = opEnqueue($workspace);
    ScriptedOperationHandler::$mode = 'sqlerror';

    opRun($workspace, $operation);

    expect(ScriptedOperationHandler::$cleaned)->toBe([$operation->id])
        ->and(opRow($operation->id)['status'])->toBe('failed')
        ->and(json_decode(opRow($operation->id)['result'], true))->toEqual(['ok' => false, 'code' => 'operation-failed'])
        ->and(Cluster::rows(Cluster::superuser(), "select count(*) as n from outbox_events where type = 'platform.operation.completed'")[0]['n'])->toBe(1);
});

it('schedules the purge of expired secrets and the partition upkeep, each on one server', function () {
    $commands = array_map(fn ($event) => [(string) $event->command, $event->expression, $event->onOneServer], app(Schedule::class)->events());
    $find = fn (string $name) => array_values(array_filter($commands, fn (array $c) => str_contains($c[0], $name)));

    expect($find('dashflow:secrets:purge-expired'))->toHaveCount(1)
        ->and($find('dashflow:secrets:purge-expired')[0][1])->toBe('*/5 * * * *')
        ->and($find('dashflow:secrets:purge-expired')[0][2])->toBeTrue()
        ->and($find('dashflow:partitions:ensure'))->toHaveCount(1)
        ->and($find('dashflow:partitions:ensure')[0][1])->toBe('0 0 * * *')
        ->and($find('dashflow:partitions:ensure')[0][2])->toBeTrue();
});
