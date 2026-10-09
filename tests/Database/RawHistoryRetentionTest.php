<?php

use App\Models\User;
use App\Modules\Ingestion\Application\SweepRawHistory;
use App\Modules\Ingestion\Application\SweepRawHistoryJob;
use App\Modules\RawStore\Contracts\RawTierSweep;
use App\Platform\Outbox\OutboxRelay;
use App\Platform\Tenancy\WorkspaceTransaction;
use App\Support\Observability\MetricEmitter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 2.16 against the real PostgreSQL: the Retention setting of a Data Source (validated against a deployment maximum, saved, audited,
// copied to its sync targets by the outbox consumer) and the scheduled sweep that is the only deleter of the raw tier. The sweep runs as
// role `maintenance` on its own connection, inside each Workspace's context, and never removes a target's current payload.
const RH_HEADERS = ['Referer' => 'http://localhost:8000'];
const RH_URL = '/api/v1/admin/data-sources';
const RH_A = '{"v":"A"}';
const RH_B = '{"v":"B"}';
const RH_C = '{"v":"C"}';

beforeEach(function () {
    $this->withoutVite();
    config([
        'dashflow.retention.max_window_days.value' => '90',
        'dashflow.tunables.sync.superseded_payload_grace.value' => null,
        'dashflow.tunables.sync.cold_purge_after.value' => null,
        'dashflow.tunables.sync.refresh_intervals.value' => '300',
    ]);
});

function rhSweep(): array
{
    return app(SweepRawHistory::class)->run();
}

/**
 * A sync target with a stored history. `$history` is oldest first: `[body text, age as a PostgreSQL interval]`, one observation each
 * (`seq` 1, 2, ...). The last entry is the current payload unless `current` names another body text.
 *
 * @param  list<array{0: string, 1: string}>  $history
 * @param  array<string, mixed>  $columns  `sync_targets` columns, plus `current` (the body text that is current)
 * @return array{id: string, bodies: array<string, string>}
 */
function rhTarget(string $workspace, array $history, array $columns = []): array
{
    $id = (string) Str::uuid7();
    $bodies = [];

    foreach ($history as [$text]) {
        $bodies[$text] ??= Cluster::seedRawBody($workspace, $id, $text);
    }

    foreach ($history as $i => [$text, $age]) {
        Cluster::superuser()->prepare('insert into raw_observations (id, workspace_id, sync_target_id, payload_id, seq, content_hash, size_bytes, dispatch_seq, observed_at) values (?, ?, ?, ?, ?, ?, ?, ?, now() - ?::interval)')
            ->execute([(string) Str::uuid7(), $workspace, $id, $bodies[$text], $i + 1, hash('sha256', $text), strlen($text), $i + 1, $age]);
    }

    $current = $columns['current'] ?? ($history === [] ? null : $history[array_key_last($history)][0]);
    unset($columns['current']);

    Cluster::seedSyncTarget($workspace, $columns + [
        'id' => $id, 'current_payload_id' => $current === null ? null : $bodies[$current], 'payload_seq' => count($history),
        'dispatch_seq' => count($history), 'applied_seq' => count($history),
    ]);

    return ['id' => $id, 'bodies' => $bodies];
}

/** @return list<int> */
function rhSeqs(string $target): array
{
    return array_map('intval', array_column(Cluster::rows(Cluster::superuser(), 'select seq from raw_observations where sync_target_id = ? order by seq', [$target]), 'seq'));
}

/** @return list<string> the stored body texts of the target, sorted */
function rhBodies(string $target): array
{
    $texts = array_column(Cluster::rows(Cluster::superuser(), "select convert_from(body, 'UTF8') as t from raw_bodies where sync_target_id = ?", [$target]), 't');
    sort($texts);

    return $texts;
}

function rhCount(string $table, string $where = 'true'): int
{
    return (int) Cluster::rows(Cluster::superuser(), "select count(*) as n from {$table} where {$where}")[0]['n'];
}

function rhTargetRow(string $id): ?array
{
    return Cluster::rows(Cluster::superuser(), 'select * from sync_targets where id = ?', [$id])[0] ?? null;
}

// ---- The sweep: latest ---------------------------------------------------------------------------------------------

it('sweeps a superseded payload of a latest target once its grace has passed, and never the current one', function () {
    config(['dashflow.tunables.sync.superseded_payload_grace.value' => '60']);
    $workspace = Cluster::workspace('Acme');
    $target = rhTarget($workspace, [[RH_A, '10 minutes'], [RH_B, '5 minutes']]);

    $done = rhSweep();

    expect($done)->toBe(['observations' => 1, 'bodies' => 1, 'targets' => 0])
        ->and(rhSeqs($target['id']))->toBe([2])
        ->and(rhBodies($target['id']))->toBe([RH_B])
        ->and(rhTargetRow($target['id']))->toMatchArray(['current_payload_id' => $target['bodies'][RH_B], 'payload_seq' => 2]);
});

it('keeps a superseded payload inside its grace period, and everything while the grace is unset or malformed', function (?string $grace, string $age) {
    config(['dashflow.tunables.sync.superseded_payload_grace.value' => $grace]);
    $workspace = Cluster::workspace('Acme');
    $target = rhTarget($workspace, [[RH_A, '2 days'], [RH_B, $age]]);

    expect(rhSweep())->toBe(['observations' => 0, 'bodies' => 0, 'targets' => 0])
        ->and(rhSeqs($target['id']))->toBe([1, 2])
        ->and(rhBodies($target['id']))->toBe([RH_A, RH_B]);
})->with([
    'inside the grace' => ['3600', '10 seconds'],
    'unset' => [null, '1 day'],
    'empty' => ['', '1 day'],
    'not a number' => ['an hour', '1 day'],
    'zero' => ['0', '1 day'],
    'fractional' => ['1.5', '1 day'],
]);

it('keeps the current body when it comes back (A, B, A) and removes the superseded observations', function () {
    config(['dashflow.tunables.sync.superseded_payload_grace.value' => '60']);
    $workspace = Cluster::workspace('Acme');
    $target = rhTarget($workspace, [[RH_A, '3 hours'], [RH_B, '2 hours'], [RH_A, '1 hour']]);

    $done = rhSweep();

    expect($done)->toBe(['observations' => 2, 'bodies' => 1, 'targets' => 0])
        ->and(rhSeqs($target['id']))->toBe([3])
        ->and(rhBodies($target['id']))->toBe([RH_A])
        ->and(rhTargetRow($target['id']))->toMatchArray(['current_payload_id' => $target['bodies'][RH_A], 'payload_seq' => 3]);
});

it('judges supersession by the successor observation, so a payload stays while its successor is inside the grace', function () {
    config(['dashflow.tunables.sync.superseded_payload_grace.value' => '3600']);
    $workspace = Cluster::workspace('Acme');
    // A was superseded two days ago, B only a minute ago: A goes, B (superseded by C a minute ago) waits.
    $target = rhTarget($workspace, [[RH_A, '3 days'], [RH_B, '2 days'], [RH_C, '1 minute']]);

    rhSweep();

    expect(rhSeqs($target['id']))->toBe([2, 3])
        ->and(rhBodies($target['id']))->toBe([RH_B, RH_C]);
});

// ---- The sweep: window ---------------------------------------------------------------------------------------------

it('deletes history older than the window except the current payload, and the bodies left without an observation', function () {
    $workspace = Cluster::workspace('Acme');
    $target = rhTarget($workspace, [[RH_A, '20 days'], [RH_B, '3 days'], [RH_C, '1 day']], ['retention_mode' => 'window', 'retention_days' => 7]);

    $done = rhSweep();

    expect($done)->toBe(['observations' => 1, 'bodies' => 1, 'targets' => 0])
        ->and(rhSeqs($target['id']))->toBe([2, 3])
        ->and(rhBodies($target['id']))->toBe([RH_B, RH_C]);
});

it('keeps the current payload and its observation when it is older than the window (nothing is stored while the data is unchanged)', function () {
    $workspace = Cluster::workspace('Acme');
    $target = rhTarget($workspace, [[RH_A, '60 days']], ['retention_mode' => 'window', 'retention_days' => 7]);

    expect(rhSweep())->toBe(['observations' => 0, 'bodies' => 0, 'targets' => 0])
        ->and(rhSeqs($target['id']))->toBe([1])
        ->and(rhBodies($target['id']))->toBe([RH_A]);
});

it('keeps the current body of a window target that came back (A, B, A), whatever the age', function () {
    $workspace = Cluster::workspace('Acme');
    $target = rhTarget($workspace, [[RH_A, '30 days'], [RH_B, '29 days'], [RH_A, '28 days']], ['retention_mode' => 'window', 'retention_days' => 7]);

    rhSweep();

    expect(rhSeqs($target['id']))->toBe([3])
        ->and(rhBodies($target['id']))->toBe([RH_A])
        ->and(rhTargetRow($target['id'])['current_payload_id'])->toBe($target['bodies'][RH_A]);
});

it('does not need the grace for a window: the superseded-payload rule is for latest only', function () {
    config(['dashflow.tunables.sync.superseded_payload_grace.value' => null]);
    $workspace = Cluster::workspace('Acme');
    $window = rhTarget($workspace, [[RH_A, '10 days'], [RH_B, '9 days']], ['retention_mode' => 'window', 'retention_days' => 5]);
    $latest = rhTarget($workspace, [[RH_A, '10 days'], [RH_B, '9 days']]);

    rhSweep();

    expect(rhSeqs($window['id']))->toBe([2])->and(rhSeqs($latest['id']))->toBe([1, 2]);
});

it('applies a changed retention on the next sweep without touching current_payload_id', function () {
    $workspace = Cluster::workspace('Acme');
    $target = rhTarget($workspace, [[RH_A, '20 days'], [RH_B, '10 days'], [RH_C, '2 days']], ['retention_mode' => 'window', 'retention_days' => 30]);
    $before = rhTargetRow($target['id']);

    expect(rhSweep())->toBe(['observations' => 0, 'bodies' => 0, 'targets' => 0]);

    // The window is shortened.
    Cluster::superuser()->prepare('update sync_targets set retention_days = 5 where id = ?')->execute([$target['id']]);
    rhSweep();
    expect(rhSeqs($target['id']))->toBe([3])->and(rhBodies($target['id']))->toBe([RH_C]);

    // Window back to latest: nothing more to take with the grace unset, and with a grace the rule is the latest rule.
    $again = rhTarget($workspace, [[RH_A, '20 days'], [RH_B, '10 days'], [RH_C, '2 days']], ['retention_mode' => 'window', 'retention_days' => 30]);
    Cluster::superuser()->prepare("update sync_targets set retention_mode = 'latest', retention_days = null where id = ?")->execute([$again['id']]);
    config(['dashflow.tunables.sync.superseded_payload_grace.value' => '3600']);
    rhSweep();

    expect(rhSeqs($again['id']))->toBe([3])
        ->and(rhTargetRow($target['id']))->toMatchArray(['current_payload_id' => $before['current_payload_id'], 'payload_seq' => $before['payload_seq'], 'fetch_key' => $before['fetch_key']])
        ->and(rhTargetRow($again['id'])['current_payload_id'])->toBe($again['bodies'][RH_C]);
});

it('takes one fixed batch per statement and leaves the rest for the next run', function () {
    $workspace = Cluster::workspace('Acme');
    $target = rhTarget($workspace, [[RH_A, '1 hour']], ['retention_mode' => 'window', 'retention_days' => 1]);
    $stale = Cluster::seedRawBody($workspace, $target['id'], RH_B);
    $extra = RawTierSweep::BATCH;

    Cluster::superuser()->prepare(
        'insert into raw_observations (id, workspace_id, sync_target_id, payload_id, seq, content_hash, size_bytes, dispatch_seq, observed_at) '
        .'select gen_random_uuid(), ?, ?, ?, 1000 + g, ?, 9, 1, now() - interval \'10 days\' from generate_series(1, ?) g',
    )->execute([$workspace, $target['id'], $stale, hash('sha256', RH_B), $extra + 5]);
    Cluster::superuser()->prepare('update sync_targets set payload_seq = 5000, dispatch_seq = 5000, applied_seq = 5000 where id = ?')->execute([$target['id']]);

    expect(rhSweep()['observations'])->toBe($extra)
        ->and(rhCount('raw_observations', "sync_target_id = '{$target['id']}'"))->toBe(6);

    expect(rhSweep()['observations'])->toBe(5);
});

// ---- The sweep: cold targets ---------------------------------------------------------------------------------------

it('purges a target retired for longer than cold_purge_after: its observations, bodies and the target row', function () {
    config(['dashflow.tunables.sync.cold_purge_after.value' => '3600']);
    $workspace = Cluster::workspace('Acme');
    $cold = rhTarget($workspace, [[RH_A, '3 days'], [RH_B, '2 days']], ['retired_at' => gmdate('c', time() - 7200)]);
    $recent = rhTarget($workspace, [[RH_A, '3 days']], ['retired_at' => gmdate('c', time() - 60)]);
    $active = rhTarget($workspace, [[RH_A, '300 days']]);

    $done = rhSweep();

    expect($done)->toBe(['observations' => 2, 'bodies' => 2, 'targets' => 1])
        ->and(rhTargetRow($cold['id']))->toBeNull()
        ->and(rhSeqs($cold['id']))->toBe([])->and(rhBodies($cold['id']))->toBe([])
        ->and(rhTargetRow($recent['id']))->not->toBeNull()->and(rhSeqs($recent['id']))->toBe([1])
        ->and(rhTargetRow($active['id']))->not->toBeNull()->and(rhSeqs($active['id']))->toBe([1])->and(rhBodies($active['id']))->toBe([RH_A]);
});

it('never purges a target that is not retired, whatever its age, so a current payload that is the only copy survives', function () {
    config(['dashflow.tunables.sync.cold_purge_after.value' => '1']);
    $workspace = Cluster::workspace('Acme');
    $active = rhTarget($workspace, [[RH_A, '900 days']], ['retention_mode' => 'window', 'retention_days' => 1, 'last_success_at' => gmdate('c', time() - 86400 * 900)]);

    rhSweep();
    rhSweep();

    expect(rhTargetRow($active['id']))->toMatchArray(['current_payload_id' => $active['bodies'][RH_A], 'payload_seq' => 1])
        ->and(rhSeqs($active['id']))->toBe([1])->and(rhBodies($active['id']))->toBe([RH_A]);
});

it('purges nothing while cold_purge_after is unset or malformed', function (?string $setting) {
    config(['dashflow.tunables.sync.cold_purge_after.value' => $setting]);
    $workspace = Cluster::workspace('Acme');
    $retired = rhTarget($workspace, [[RH_A, '3 days']], ['retired_at' => gmdate('c', time() - 86400 * 400)]);

    expect(rhSweep()['targets'])->toBe(0)
        ->and(rhTargetRow($retired['id']))->not->toBeNull()->and(rhBodies($retired['id']))->toBe([RH_A]);
})->with([[null], [''], ['soon'], ['-5'], ['0']]);

it('keeps the target row until none of its raw rows is left, taking a big retired target over several runs', function () {
    config(['dashflow.tunables.sync.cold_purge_after.value' => '60']);
    $workspace = Cluster::workspace('Acme');
    $retired = rhTarget($workspace, [[RH_A, '3 days']], ['retired_at' => gmdate('c', time() - 3600)]);
    Cluster::superuser()->prepare(
        'insert into raw_observations (id, workspace_id, sync_target_id, payload_id, seq, content_hash, size_bytes, dispatch_seq, observed_at) '
        .'select gen_random_uuid(), ?, ?, ?, 100 + g, ?, 9, 1, now() - interval \'3 days\' from generate_series(1, ?) g',
    )->execute([$workspace, $retired['id'], $retired['bodies'][RH_A], hash('sha256', RH_A), RawTierSweep::BATCH]);

    expect(rhSweep()['targets'])->toBe(0)->and(rhTargetRow($retired['id']))->not->toBeNull();
    rhSweep();
    expect(rhTargetRow($retired['id']))->toBeNull()->and(rhCount('raw_observations'))->toBe(0)->and(rhCount('raw_bodies'))->toBe(0);
});

// ---- Race, isolation, roles ----------------------------------------------------------------------------------------

it('skips a body whose delete meets a foreign-key violation (a concurrent put stored it again) and deletes the rest', function () {
    $workspace = Cluster::workspace('Acme');
    $target = rhTarget($workspace, [[RH_C, '1 day']]);
    $one = Cluster::seedRawBody($workspace, $target['id'], RH_A);
    $two = Cluster::seedRawBody($workspace, $target['id'], RH_B);
    $pdo = Cluster::superuser();

    // The violation a concurrent `put` causes: the observation that now names the body exists by the time the delete checks the key.
    $pdo->exec("CREATE FUNCTION rh_block_body_delete() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF OLD.id = '{$one}' THEN RAISE EXCEPTION 'still referenced' USING ERRCODE = '23503'; END IF; RETURN OLD; END \$\$");
    $pdo->exec('CREATE TRIGGER rh_block_body_delete BEFORE DELETE ON raw_bodies FOR EACH ROW EXECUTE FUNCTION rh_block_body_delete()');

    try {
        // Both are orphans of a latest target; with the grace unset only a window target's clean-up runs, so give the target a window.
        $pdo->prepare("update sync_targets set retention_mode = 'window', retention_days = 30 where id = ?")->execute([$target['id']]);

        expect(rhSweep())->toBe(['observations' => 0, 'bodies' => 1, 'targets' => 0])
            ->and(rhBodies($target['id']))->toBe([RH_A, RH_C]);
    } finally {
        $pdo->exec('DROP TRIGGER rh_block_body_delete ON raw_bodies');
        $pdo->exec('DROP FUNCTION rh_block_body_delete()');
    }

    // Next run, with the writer gone, the body goes too.
    rhSweep();
    expect(rhBodies($target['id']))->toBe([RH_C]);
});

it('keeps a body a concurrent put references: the delete waits for it and then fails on the key, so the body and the new observation survive', function () {
    $workspace = Cluster::workspace('Acme');
    $target = rhTarget($workspace, [[RH_C, '1 day']], ['retention_mode' => 'window', 'retention_days' => 30]);
    $orphan = Cluster::seedRawBody($workspace, $target['id'], RH_A);

    // What `put` does for a body that already exists: insert an observation of it. Uncommitted, it holds a key-share lock on the body.
    $writer = Cluster::superuser();
    $writer->beginTransaction();
    $writer->prepare('insert into raw_observations (id, workspace_id, sync_target_id, payload_id, seq, content_hash, size_bytes, dispatch_seq, observed_at) values (?, ?, ?, ?, 2, ?, 9, 2, now())')
        ->execute([(string) Str::uuid7(), $workspace, $target['id'], $orphan, hash('sha256', RH_A)]);

    // The sweeper cannot see that observation yet, picks the body, and has to wait for the lock: bound the wait, as a worker would.
    $sweeper = Cluster::maintenance();
    $sweeper->exec("set lock_timeout = '300ms'");
    $sweeper->beginTransaction();
    $sweeper->prepare("select set_config('app.workspace_id', ?, true)")->execute([$workspace]);

    expect(fn () => $sweeper->exec("delete from raw_bodies where id = '{$orphan}'"))->toThrow(PDOException::class, 'lock timeout');
    $sweeper->rollBack();
    $writer->commit();

    rhSweep();

    expect(rhBodies($target['id']))->toBe([RH_A, RH_C])->and(rhSeqs($target['id']))->toBe([1, 2]);
});

it('sweeps each Workspace on its own: one never reads or deletes the other\'s rows', function () {
    config(['dashflow.tunables.sync.superseded_payload_grace.value' => '60', 'dashflow.tunables.sync.cold_purge_after.value' => '60']);
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $inA = rhTarget($a, [[RH_A, '2 days'], [RH_B, '1 day']]);
    $inB = rhTarget($b, [[RH_A, '2 days'], [RH_B, '1 day']], ['retired_at' => gmdate('c', time() - 86400)]);

    // Only A's context: the maintenance role sees and deletes A's rows alone.
    $deleted = Cluster::inWorkspace(Cluster::maintenance(), $a, fn (PDO $pdo): array => [
        'observations' => $pdo->exec('delete from raw_observations'), 'bodies' => $pdo->exec('delete from raw_bodies where id not in (select current_payload_id from sync_targets)'),
        'targets' => $pdo->exec('delete from sync_targets'),
    ]);

    expect($deleted)->toBe(['observations' => 2, 'bodies' => 1, 'targets' => 1])
        ->and(rhSeqs($inB['id']))->toBe([1, 2])->and(rhTargetRow($inB['id']))->not->toBeNull();

    // Without any context the role sees nothing at all.
    $none = Cluster::maintenance();
    expect($none->exec('delete from raw_observations'))->toBe(0)->and($none->exec('delete from raw_bodies'))->toBe(0)->and($none->exec('delete from sync_targets'))->toBe(0)
        ->and(rhCount('raw_observations'))->toBe(2);

    // The sweep itself enters each Workspace in turn.
    $inA2 = rhTarget($a, [[RH_A, '2 days'], [RH_B, '1 day']]);
    rhSweep();
    expect(rhSeqs($inA2['id']))->toBe([2])->and(rhTargetRow($inB['id']))->toBeNull()->and(rhSeqs($inB['id']))->toBe([]);
});

it('runs a Workspace in a transaction of the maintenance connection, with that Workspace as its context', function () {
    $workspace = Cluster::workspace('Acme');
    $seen = app(WorkspaceTransaction::class)->runIsolated(SweepRawHistory::CONNECTION, $workspace, fn ($db) => [
        $db->selectOne("select current_user as u, current_setting('app.workspace_id', true) as w")->u,
        $db->selectOne("select current_setting('app.workspace_id', true) as w")->w,
        $db->selectOne('select rolbypassrls from pg_roles where rolname = current_user')->rolbypassrls,
    ]);

    expect($seen)->toBe(['maintenance', $workspace, false]);
});

it('limits the raw tier to the right roles: only maintenance deletes, app never updates, no role updates at all', function () {
    $workspace = Cluster::workspace('Acme');
    $target = rhTarget($workspace, [[RH_A, '1 day']]);
    $privilege = fn (string $role, string $table, string $privilege): bool => (bool) Cluster::rows(Cluster::superuser(), 'select has_table_privilege(?, ?, ?) as p', [$role, $table, $privilege])[0]['p'];

    foreach (['raw_bodies', 'raw_observations'] as $table) {
        expect($privilege('maintenance', $table, 'DELETE'))->toBeTrue()->and($privilege('maintenance', $table, 'SELECT'))->toBeTrue()
            ->and($privilege('maintenance', $table, 'UPDATE'))->toBeFalse()->and($privilege('maintenance', $table, 'INSERT'))->toBeFalse()
            ->and($privilege('app', $table, 'DELETE'))->toBeFalse()->and($privilege('app', $table, 'UPDATE'))->toBeFalse()
            ->and($privilege('app', $table, 'SELECT'))->toBeTrue()->and($privilege('app', $table, 'INSERT'))->toBeTrue();
    }

    $app = Cluster::directApp();
    expect(fn () => Cluster::inWorkspace($app, $workspace, fn (PDO $pdo) => $pdo->exec('delete from raw_observations')))->toThrow(PDOException::class, 'permission denied')
        ->and(fn () => Cluster::inWorkspace($app, $workspace, fn (PDO $pdo) => $pdo->exec('delete from raw_bodies')))->toThrow(PDOException::class, 'permission denied')
        ->and(fn () => Cluster::inWorkspace($app, $workspace, fn (PDO $pdo) => $pdo->exec('update raw_bodies set size_bytes = 1')))->toThrow(PDOException::class, 'permission denied')
        ->and(fn () => Cluster::inWorkspace(Cluster::maintenance(), $workspace, fn (PDO $pdo) => $pdo->exec('update raw_observations set seq = 9')))->toThrow(PDOException::class, 'permission denied')
        ->and(fn () => Cluster::superuser()->exec('update raw_observations set seq = 9'))->toThrow(PDOException::class, 'immutable')
        ->and(rhSeqs($target['id']))->toBe([1])
        ->and(Cluster::rows(Cluster::superuser(), "select rolbypassrls from pg_roles where rolname = 'maintenance'")[0]['rolbypassrls'])->toBeFalse();
});

it('records the sweep as a metric of the Workspace and kind only, and writes no audit or outbox event', function () {
    config(['dashflow.tunables.sync.superseded_payload_grace.value' => '60', 'dashflow.tunables.sync.cold_purge_after.value' => '60']);
    $workspace = Cluster::workspace('Acme');
    rhTarget($workspace, [[RH_A, '2 days'], [RH_B, '1 day']]);
    rhTarget($workspace, [[RH_C, '2 days']], ['retired_at' => gmdate('c', time() - 3600)]);
    $audits = rhCount('audit_events');
    $outbox = rhCount('outbox_events');

    $metrics = [];
    app()->instance(MetricEmitter::class, new class($metrics) implements MetricEmitter
    {
        /** @param  list<array{0: string, 1: array<string, mixed>, 2: int}>  $seen */
        public function __construct(public array &$seen) {}

        public function increment(string $name, array $labels = [], int $by = 1): void
        {
            $this->seen[] = [$name, $labels, $by];
        }
    });

    rhSweep();

    expect($metrics)->toEqualCanonicalizing([
        ['dashflow.ingestion.raw_swept', ['workspace_id' => $workspace, 'kind' => 'observation'], 2],
        ['dashflow.ingestion.raw_swept', ['workspace_id' => $workspace, 'kind' => 'body'], 2],
        ['dashflow.ingestion.raw_swept', ['workspace_id' => $workspace, 'kind' => 'target'], 1],
    ])
        ->and(rhCount('audit_events'))->toBe($audits)->and(rhCount('outbox_events'))->toBe($outbox);
});

it('goes on with the other Workspaces when one fails', function () {
    config(['dashflow.tunables.sync.superseded_payload_grace.value' => '60']);
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $inA = rhTarget($a, [[RH_A, '2 days'], [RH_B, '1 day']]);
    $inB = rhTarget($b, [[RH_A, '2 days'], [RH_B, '1 day']]);

    $failing = new class(app(RawTierSweep::class), $a) implements RawTierSweep
    {
        public function __construct(private RawTierSweep $inner, private string $fail) {}

        public function deleteSupersededObservations(ConnectionInterface $db, string $workspaceId, int $graceSeconds): int
        {
            return $workspaceId === $this->fail ? throw new RuntimeException('boom') : $this->inner->deleteSupersededObservations($db, $workspaceId, $graceSeconds);
        }

        public function deleteObservationsOutsideWindow(ConnectionInterface $db, string $workspaceId): int
        {
            return $this->inner->deleteObservationsOutsideWindow($db, $workspaceId);
        }

        public function deleteOrphanBodies(ConnectionInterface $db, string $workspaceId, string $mode): int
        {
            return $this->inner->deleteOrphanBodies($db, $workspaceId, $mode);
        }

        public function purgeTarget(ConnectionInterface $db, string $workspaceId, string $syncTargetId): array
        {
            return $this->inner->purgeTarget($db, $workspaceId, $syncTargetId);
        }
    };
    app()->instance(RawTierSweep::class, $failing);

    rhSweep();

    expect(rhSeqs($inA['id']))->toBe([1, 2])->and(rhSeqs($inB['id']))->toBe([2]);
});

it('queues the sweep job on maintenance and runs the sweep', function () {
    config(['dashflow.tunables.sync.superseded_payload_grace.value' => '60']);
    $workspace = Cluster::workspace('Acme');
    $target = rhTarget($workspace, [[RH_A, '2 days'], [RH_B, '1 day']]);

    expect((new SweepRawHistoryJob)->queue)->toBe('maintenance');

    dispatch(new SweepRawHistoryJob);

    expect(rhSeqs($target['id']))->toBe([2]);
});

// ---- The setting ----------------------------------------------------------------------------------------------------

function rhAdmin(string $workspace): string
{
    $user = Cluster::user('ada-'.Str::random(6).'@example.test');
    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspace, $user, 'admin', 'active']);
    Cluster::superuser()->prepare("INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, 'data_sources.manage', now(), now())")
        ->execute([(string) Str::uuid7(), $workspace, $membership]);
    Cluster::seedHostEntry($workspace, 'api.example.com');
    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspace, 'area' => 'admin']);

    return $membership;
}

function rhBody(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Sales API', 'base_url' => 'https://api.example.com/v1', 'headers' => [], 'timeout_seconds' => null,
        'max_response_bytes' => null, 'max_pages' => null, 'live_capable' => false, 'auth_type' => 'none',
    ];
}

function rhCreate(array $overrides = [])
{
    return test()->postJson(RH_URL, rhBody($overrides), RH_HEADERS);
}

function rhUpdate(string $id, int $revision, array $overrides = [])
{
    return test()->putJson(RH_URL."/{$id}", rhBody($overrides) + ['revision' => $revision], RH_HEADERS);
}

function rhSource(): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from data_sources')[0];
}

it('keeps the latest payload by default, for a new and an existing Data Source, and shows the maximum with the form data', function () {
    $workspace = Cluster::workspace('Acme');
    // A row written before the setting existed (the columns default).
    $old = Cluster::seedDataSource($workspace, 'Old');
    rhAdmin($workspace);

    expect(rhSource())->toMatchArray(['retention_mode' => 'latest', 'retention_days' => null]);

    $created = rhCreate(['name' => 'New'])->assertCreated();
    $id = $created->json('data.data_source_id');
    $read = test()->getJson(RH_URL."/{$id}", RH_HEADERS);

    expect($created->json('data'))->toMatchArray(['retention_mode' => 'latest', 'retention_days' => null])
        ->and($read->json('meta.retention'))->toBe(['max_window_days' => 90])
        ->and(test()->getJson(RH_URL, RH_HEADERS)->json('meta.retention'))->toBe(['max_window_days' => 90])
        ->and(collect(test()->getJson(RH_URL, RH_HEADERS)->json('data'))->firstWhere('data_source_id', $old))->toMatchArray(['retention_mode' => 'latest', 'retention_days' => null]);
});

it('saves a window as a revision, audits it with before and after, and copies it to the targets through the outbox consumer', function () {
    $workspace = Cluster::workspace('Acme');
    rhAdmin($workspace);
    $id = rhCreate()->assertCreated()->json('data.data_source_id');
    $target = rhTarget($workspace, [[RH_A, '1 day']], ['data_source_id' => $id]);
    $retired = rhTarget($workspace, [[RH_B, '1 day']], ['data_source_id' => $id, 'retired_at' => gmdate('c', time() - 60)]);
    $other = rhTarget($workspace, [[RH_C, '1 day']]);

    $saved = rhUpdate($id, 1, ['retention_mode' => 'window', 'retention_days' => '30'])->assertOk();

    expect($saved->json('data'))->toMatchArray(['retention_mode' => 'window', 'retention_days' => 30, 'revision' => 2])
        ->and(rhSource())->toMatchArray(['retention_mode' => 'window', 'retention_days' => 30, 'revision' => 2]);

    $audit = Cluster::rows(Cluster::superuser(), "select * from audit_events where action = 'connector.data_source.updated'");
    $before = json_decode($audit[0]['before_state'], true);
    $after = json_decode($audit[0]['after_state'], true);

    expect($audit)->toHaveCount(1)
        ->and($before)->toMatchArray(['retention_mode' => 'latest', 'retention_days' => null])
        ->and($after)->toMatchArray(['retention_mode' => 'window', 'retention_days' => 30]);

    // The targets keep their old rule until the relay delivers the event; then every target of the Data Source has the new one.
    expect(rhTargetRow($target['id']))->toMatchArray(['retention_mode' => 'latest', 'retention_days' => null]);
    $payloads = [rhTargetRow($target['id'])['current_payload_id'], rhTargetRow($retired['id'])['current_payload_id']];

    app(OutboxRelay::class)->relay();

    expect(rhTargetRow($target['id']))->toMatchArray(['retention_mode' => 'window', 'retention_days' => 30])
        ->and(rhTargetRow($retired['id']))->toMatchArray(['retention_mode' => 'window', 'retention_days' => 30])
        ->and(rhTargetRow($other['id']))->toMatchArray(['retention_mode' => 'latest', 'retention_days' => null])
        ->and([rhTargetRow($target['id'])['current_payload_id'], rhTargetRow($retired['id'])['current_payload_id']])->toBe($payloads);

    // And back to latest: the copy follows, the payload pointer never moves.
    rhUpdate($id, 2)->assertOk();
    app(OutboxRelay::class)->relay();

    expect(rhTargetRow($target['id']))->toMatchArray(['retention_mode' => 'latest', 'retention_days' => null])
        ->and(rhTargetRow($target['id'])['current_payload_id'])->toBe($payloads[0]);
});

it('copies the retention into a newly registered target and never changes a fetch key or payload pointer of an existing one', function () {
    $workspace = Cluster::workspace('Acme');
    rhAdmin($workspace);
    $id = rhCreate(['retention_mode' => 'window', 'retention_days' => 14])->assertCreated()->json('data.data_source_id');
    $endpoint = test()->postJson(RH_URL."/{$id}/endpoints", [
        'method' => 'GET', 'path' => '/revenue', 'params' => [['name' => 'region', 'binding' => 'fixed', 'value' => 'emea']],
        'headers' => [], 'body_template' => null, 'read_only_query' => false, 'confirm_read_only' => false,
    ], RH_HEADERS)->assertCreated()->json('data.endpoint_id');

    app(OutboxRelay::class)->relay();
    $first = Cluster::rows(Cluster::superuser(), 'select * from sync_targets where endpoint_id = ?', [$endpoint]);

    expect($first)->toHaveCount(1)->and($first[0])->toMatchArray(['retention_mode' => 'window', 'retention_days' => 14, 'data_source_revision' => 1]);

    $body = Cluster::seedRawBody($workspace, $first[0]['id'], RH_A);
    Cluster::superuser()->prepare('update sync_targets set current_payload_id = ?, payload_seq = 1 where id = ?')->execute([$body, $first[0]['id']]);

    // A retention change is a Data Source revision (as any edit is): the old target is retired with its payload and a new one registers with the new rule.
    rhUpdate($id, 1, ['retention_mode' => 'window', 'retention_days' => 7])->assertOk();
    app(OutboxRelay::class)->relay();
    app(OutboxRelay::class)->relay();

    $old = rhTargetRow($first[0]['id']);
    $new = Cluster::rows(Cluster::superuser(), 'select * from sync_targets where endpoint_id = ? and retired_at is null', [$endpoint]);

    expect($old)->toMatchArray(['fetch_key' => $first[0]['fetch_key'], 'current_payload_id' => $body, 'payload_seq' => 1, 'retention_mode' => 'window', 'retention_days' => 7])
        ->and($old['retired_at'])->not->toBeNull()
        ->and($new)->toHaveCount(1)->and($new[0])->toMatchArray(['retention_mode' => 'window', 'retention_days' => 7, 'data_source_revision' => 2])
        ->and($new[0]['fetch_key'])->not->toBe($first[0]['fetch_key']);
});

it('refuses a window while no maximum is set, with a field error and a reason, and saves nothing', function (mixed $maximum) {
    config(['dashflow.retention.max_window_days.value' => $maximum]);
    $workspace = Cluster::workspace('Acme');
    rhAdmin($workspace);

    rhCreate(['retention_mode' => 'window', 'retention_days' => 30])->assertStatus(422)
        ->assertJsonPath('reasons.retention_days', 'retention-window-unavailable')->assertJsonValidationErrors('retention_days', 'errors');

    expect(Cluster::rows(Cluster::superuser(), 'select * from data_sources'))->toBe([]);

    // `latest` always works, and the form is told there is no maximum.
    $id = rhCreate()->assertCreated()->json('data.data_source_id');
    rhUpdate($id, 1, ['retention_mode' => 'window', 'retention_days' => 5])->assertStatus(422)->assertJsonPath('reasons.retention_days', 'retention-window-unavailable');

    expect(rhSource())->toMatchArray(['revision' => 1, 'retention_mode' => 'latest'])
        ->and(test()->getJson(RH_URL."/{$id}", RH_HEADERS)->json('meta.retention'))->toBe(['max_window_days' => null]);
})->with([[null], [''], ['soon'], ['0'], ['-3'], ['1.5']]);

it('refuses a number of days out of range, not whole, above the maximum, or sent with latest, and saves nothing', function (array $body, string $reason) {
    $workspace = Cluster::workspace('Acme');
    rhAdmin($workspace);
    $id = rhCreate()->assertCreated()->json('data.data_source_id');

    rhUpdate($id, 1, $body)->assertStatus(422)->assertJsonPath('reasons.retention_days', $reason)->assertJsonValidationErrors('retention_days', 'errors');

    expect(rhSource())->toMatchArray(['revision' => 1, 'retention_mode' => 'latest', 'retention_days' => null]);
})->with([
    'zero' => [['retention_mode' => 'window', 'retention_days' => 0], 'retention-days-invalid'],
    'negative' => [['retention_mode' => 'window', 'retention_days' => -1], 'retention-days-invalid'],
    'a fraction' => [['retention_mode' => 'window', 'retention_days' => '1.5'], 'retention-days-invalid'],
    'text' => [['retention_mode' => 'window', 'retention_days' => 'ten'], 'retention-days-invalid'],
    'missing' => [['retention_mode' => 'window'], 'retention-days-invalid'],
    'a float' => [['retention_mode' => 'window', 'retention_days' => 7.5], 'retention-days-invalid'],
    'above the maximum' => [['retention_mode' => 'window', 'retention_days' => 91], 'retention-days-above-maximum'],
    'days with latest' => [['retention_mode' => 'latest', 'retention_days' => 7], 'retention-days-not-allowed'],
    'days without a mode' => [['retention_days' => 7], 'retention-days-not-allowed'],
]);

it('accepts the first and the last day of the window and refuses an unknown mode', function () {
    $workspace = Cluster::workspace('Acme');
    rhAdmin($workspace);
    $id = rhCreate(['retention_mode' => 'window', 'retention_days' => 1])->assertCreated()->json('data.data_source_id');

    rhUpdate($id, 1, ['retention_mode' => 'window', 'retention_days' => 90])->assertOk()->assertJsonPath('data.retention_days', 90);
    rhUpdate($id, 2, ['retention_mode' => 'forever'])->assertStatus(422)->assertJsonPath('reasons.retention_mode', 'retention-mode-invalid');

    expect(rhSource())->toMatchArray(['retention_mode' => 'window', 'retention_days' => 90, 'revision' => 2]);
});

it('keeps the pair of retention columns consistent with CHECKs on both tables', function (string $set) {
    $workspace = Cluster::workspace('Acme');
    Cluster::seedDataSource($workspace, 'Src');
    Cluster::seedSyncTarget($workspace);

    foreach (['data_sources', 'sync_targets'] as $table) {
        expect(fn () => Cluster::superuser()->exec("update {$table} set {$set}"))->toThrow(PDOException::class, "{$table}_retention_check");
    }
})->with([
    'window without days' => ["retention_mode = 'window', retention_days = null"],
    'window of zero days' => ["retention_mode = 'window', retention_days = 0"],
    'latest with days' => ["retention_mode = 'latest', retention_days = 5"],
    'an unknown mode' => ["retention_mode = 'forever', retention_days = null"],
]);

it('passes the maximum retention window to the create and edit pages as a prop, null when unset', function () {
    $workspace = Cluster::workspace('Acme');
    rhAdmin($workspace);
    $id = rhCreate()->assertCreated()->json('data.data_source_id');

    test()->get(route('admin.data-sources.create'))->assertOk()->assertInertia(fn ($page) => $page->where('retentionMaxWindowDays', 90));
    test()->get(route('admin.data-sources.edit', $id))->assertOk()->assertInertia(fn ($page) => $page->where('retentionMaxWindowDays', 90));

    config(['dashflow.retention.max_window_days.value' => null]);

    test()->get(route('admin.data-sources.create'))->assertOk()->assertInertia(fn ($page) => $page->where('retentionMaxWindowDays', null));
    test()->get(route('admin.data-sources.edit', $id))->assertOk()->assertInertia(fn ($page) => $page->where('retentionMaxWindowDays', null));
});

it('applies a retention change to the targets of a Data Source that has no Endpoints', function () {
    $workspace = Cluster::workspace('Acme');
    rhAdmin($workspace);
    $id = rhCreate()->assertCreated()->json('data.data_source_id');
    $target = rhTarget($workspace, [[RH_A, '1 day']], ['data_source_id' => $id]);

    expect(Cluster::rows(Cluster::superuser(), 'select 1 from endpoints where data_source_id = ?', [$id]))->toBe([]);

    rhUpdate($id, 1, ['retention_mode' => 'window', 'retention_days' => 9])->assertOk();
    app(OutboxRelay::class)->relay();

    expect(rhTargetRow($target['id']))->toMatchArray(['retention_mode' => 'window', 'retention_days' => 9]);
});
