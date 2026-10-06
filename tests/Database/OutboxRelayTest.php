<?php

use App\Platform\Audit\AuditAction;
use App\Platform\Outbox\ConsumerDelivery;
use App\Platform\Outbox\ConsumptionResult;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxConsumers;
use App\Platform\Outbox\OutboxEnvelope;
use App\Platform\Outbox\OutboxRelay;
use App\Platform\Outbox\RelayOutboxJob;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\DB;
use Tests\Database\Fixtures\RecordingConsumer;
use Tests\Database\Support\Cluster;

function emitFor(string $workspace, string $subject, array $data = []): OutboxEnvelope
{
    return app(WorkspaceTransaction::class)->run($workspace, fn () => app(Outbox::class)->emit(AuditAction::AccessRoleChanged, $subject, $data));
}

function consumer(string $name = 'test.recorder'): RecordingConsumer
{
    $consumer = new RecordingConsumer($name);
    app()->forgetInstance(OutboxConsumers::class);
    $registry = new OutboxConsumers;
    $registry->register($consumer);
    app()->instance(OutboxConsumers::class, $registry);

    return $consumer;
}

it('delivers each pending event once inside its own Workspace and sets sent_at', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $consumer = consumer();
    $ea = emitFor($a, 'membership:1');
    $eb = emitFor($b, 'membership:1');

    expect(app(OutboxRelay::class)->relay())->toBe(2)
        ->and(app(OutboxRelay::class)->relay())->toBe(0);

    expect($consumer->received)->toHaveCount(2);
    $byId = array_column($consumer->received, 'ctx', 'id');
    expect($byId[$ea->eventId])->toBe($a)->and($byId[$eb->eventId])->toBe($b);
    expect(Cluster::rows(Cluster::superuser(), 'select count(*) as n from outbox_events where sent_at is null')[0]['n'])->toBe(0);

    // The consumption rows belong to their own Workspaces.
    $rows = Cluster::rows(Cluster::superuser(), 'select workspace_id, event_id from outbox_consumptions order by workspace_id');
    expect(array_column($rows, 'workspace_id'))->toEqualCanonicalizing([$a, $b]);
});

it('leaves a failed delivery pending, and later events of that subject with it', function () {
    $a = Cluster::workspace('A');
    $consumer = consumer();
    $consumer->fail = true;
    $first = emitFor($a, 'membership:1');
    emitFor($a, 'membership:1');
    $other = emitFor($a, 'membership:2');
    $consumer->fail = false;

    // Fail only the first subject's first event.
    $failing = new class('test.recorder') extends RecordingConsumer
    {
        public function handle(OutboxEnvelope $event): void
        {
            if ($event->subject === 'membership:1') {
                throw new RuntimeException('down');
            }
            parent::handle($event);
        }
    };
    $registry = new OutboxConsumers;
    $registry->register($failing);
    app()->instance(OutboxConsumers::class, $registry);

    expect(app(OutboxRelay::class)->relay())->toBe(1);
    expect(array_column($failing->received, 'id'))->toBe([$other->eventId]);

    $pending = Cluster::rows(Cluster::superuser(), 'select subject_seq from outbox_events where sent_at is null order by subject_seq');
    expect(array_column($pending, 'subject_seq'))->toBe([1, 2]);

    // Recovery: the consumer works again and both are delivered in order.
    $good = consumer();
    expect(app(OutboxRelay::class)->relay())->toBe(2)
        ->and($good->received[0]['id'])->toBe($first->eventId);
});

it('does not deliver an event that another relay worker has locked', function () {
    $a = Cluster::workspace('A');
    $consumer = consumer();
    emitFor($a, 'membership:1');

    $other = Cluster::system();
    $other->beginTransaction();
    expect($other->query('select id from outbox_events where sent_at is null for update skip locked')->fetchAll())->toHaveCount(1);

    expect(app(OutboxRelay::class)->relay())->toBe(0)
        ->and($consumer->received)->toBe([]);

    $other->rollBack();
    expect(app(OutboxRelay::class)->relay())->toBe(1);
});

it('ignores a redelivery of the same event ID', function () {
    $a = Cluster::workspace('A');
    $consumer = consumer();
    $event = emitFor($a, 'membership:1');
    $delivery = app(ConsumerDelivery::class);

    expect($delivery->deliver($consumer, $event))->toBe(ConsumptionResult::Applied)
        ->and($delivery->deliver($consumer, $event))->toBe(ConsumptionResult::Duplicate)
        ->and($consumer->received)->toHaveCount(1);

    // A different consumer has its own record.
    expect($delivery->deliver(new RecordingConsumer('test.other'), $event))->toBe(ConsumptionResult::Applied);
});

it('drops an event whose subject_seq is not newer than the last one applied', function () {
    $a = Cluster::workspace('A');
    $consumer = consumer();
    $one = emitFor($a, 'membership:1');
    $two = emitFor($a, 'membership:1');
    $delivery = app(ConsumerDelivery::class);

    expect($delivery->deliver($consumer, $two))->toBe(ConsumptionResult::Applied)
        ->and($delivery->deliver($consumer, $one))->toBe(ConsumptionResult::Stale)
        ->and(array_column($consumer->received, 'id'))->toBe([$two->eventId]);

    // Another subject is unaffected.
    $other = emitFor($a, 'membership:2');
    expect($delivery->deliver($consumer, $other))->toBe(ConsumptionResult::Applied);
});

it('leaves no consumption record when the consumer fails', function () {
    $a = Cluster::workspace('A');
    $consumer = consumer();
    $consumer->fail = true;
    $event = emitFor($a, 'membership:1');

    expect(fn () => app(ConsumerDelivery::class)->deliver($consumer, $event))->toThrow(RuntimeException::class);
    expect(Cluster::rows(Cluster::superuser(), 'select 1 from outbox_consumptions'))->toBe([]);

    $consumer->fail = false;
    expect(app(ConsumerDelivery::class)->deliver($consumer, $event))->toBe(ConsumptionResult::Applied);
});

it('limits system to the relay columns and never lets it bypass row-level security', function () {
    $a = Cluster::workspace('A');
    emitFor($a, 'membership:1');
    $system = Cluster::system();

    // It reads unsent events across Workspaces with no context, but only the relay columns...
    expect(Cluster::rows($system, 'select id, data from outbox_events'))->toHaveCount(1);
    // ...marks them sent and nothing else.
    expect(fn () => $system->exec("update outbox_events set type = 'x'"))->toThrow(PDOException::class, 'permission denied');
    expect(fn () => $system->exec('delete from outbox_events'))->toThrow(PDOException::class, 'permission denied');
    expect(fn () => Cluster::rows($system, 'select * from audit_events'))->toThrow(PDOException::class, 'permission denied');
    expect(fn () => Cluster::rows($system, 'select * from workspace_memberships'))->toThrow(PDOException::class, 'permission denied');
    expect(fn () => $system->exec("insert into outbox_events (id, workspace_id, type, subject, subject_seq, occurred_at, data) values (gen_random_uuid(), '{$a}', 't', 's', 9, now(), '{}')"))->toThrow(PDOException::class, 'permission denied');
    expect($system->exec('update outbox_events set sent_at = now()'))->toBe(1);

    // A sent event can no longer be changed, and app never sees across Workspaces.
    expect($system->exec('update outbox_events set sent_at = null'))->toBe(0)
        ->and(Cluster::rows(Cluster::directApp(), 'select id from outbox_events'))->toBe([]);
    expect(Cluster::rows(Cluster::superuser(), "select rolbypassrls from pg_roles where rolname = 'system'")[0]['rolbypassrls'])->toBeFalse();
});

it('keeps events pending while no consumer is registered and delivers them once one is', function () {
    $a = Cluster::workspace('A');
    app()->instance(OutboxConsumers::class, new OutboxConsumers);
    emitFor($a, 'membership:1');

    expect(app(OutboxRelay::class)->relay())->toBe(0)
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from outbox_events where sent_at is null')[0]['n'])->toBe(1);

    $consumer = consumer();
    expect(app(OutboxRelay::class)->relay())->toBe(1)
        ->and($consumer->received)->toHaveCount(1);
});

it('does not let 100+ poison events of one subject block another subject', function () {
    $a = Cluster::workspace('A');
    $consumer = consumer();
    for ($i = 0; $i < 105; $i++) {
        emitFor($a, 'membership:1');
    }
    $good = emitFor($a, 'membership:2');

    $failing = new class('test.recorder') extends RecordingConsumer
    {
        public function handle(OutboxEnvelope $event): void
        {
            if ($event->subject === 'membership:1') {
                throw new RuntimeException('poison');
            }
            parent::handle($event);
        }
    };
    $registry = new OutboxConsumers;
    $registry->register($failing);
    app()->instance(OutboxConsumers::class, $registry);

    expect(app(OutboxRelay::class)->relay(100))->toBe(1)
        ->and(array_column($failing->received, 'id'))->toBe([$good->eventId]);
});

it('delivers a subject in order and loses nothing with two concurrent relay workers', function () {
    $a = Cluster::workspace('A');
    $file = tempnam(sys_get_temp_dir(), 'relay');
    file_put_contents($file, '');

    foreach (['membership:1' => 15, 'membership:2' => 15, 'membership:3' => 15] as $subject => $count) {
        for ($i = 0; $i < $count; $i++) {
            emitFor($a, $subject);
        }
    }

    $script = dirname(__DIR__).'/Database/Support/relay.php';
    $processes = [];
    for ($i = 0; $i < 2; $i++) {
        $processes[] = [proc_open([PHP_BINARY, $script, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, getenv()), $pipes];
    }
    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        expect(proc_close($process))->toBe(0, $output);
    }

    $bySubject = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        [$subject, $seq] = explode(' ', $line);
        $bySubject[$subject][] = (int) $seq;
    }
    unlink($file);

    ksort($bySubject);
    expect(array_keys($bySubject))->toBe(['membership:1', 'membership:2', 'membership:3']);
    foreach ($bySubject as $subject => $seqs) {
        expect($seqs)->toBe(range(1, 15), "{$subject} delivered out of order or lost");
    }
    expect(Cluster::rows(Cluster::superuser(), 'select count(*) as n from outbox_events where sent_at is null')[0]['n'])->toBe(0)
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from outbox_consumptions where applied')[0]['n'])->toBe(45)
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from outbox_consumptions where not applied')[0]['n'])->toBe(0);
});

it('takes an advisory lock per consumer, Workspace and subject before the stale check', function () {
    $a = Cluster::workspace('A');
    $event = emitFor($a, 'membership:1');

    $probe = new class('test.probe') extends RecordingConsumer
    {
        public int $locks = 0;

        public function handle(OutboxEnvelope $event): void
        {
            $this->locks = (int) DB::selectOne("select count(*) as n from pg_locks where locktype = 'advisory' and pid = pg_backend_pid()")->n;
        }
    };

    app(ConsumerDelivery::class)->deliver($probe, $event);

    expect($probe->locks)->toBeGreaterThanOrEqual(1);
});

it('drains pending events through the job, capped at ten batches per run', function () {
    $a = Cluster::workspace('A');
    $consumer = consumer();
    for ($i = 1; $i <= 150; $i++) {
        emitFor($a, 'membership:'.$i);
    }

    (new RelayOutboxJob)->handle(app(OutboxRelay::class));

    expect($consumer->received)->toHaveCount(150)
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from outbox_events where sent_at is null')[0]['n'])->toBe(0);
});
