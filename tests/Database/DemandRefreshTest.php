<?php

use App\Models\User;
use App\Modules\Ingestion\Application\DispatchDueSyncs;
use App\Modules\Ingestion\Application\FetchJob;
use App\Modules\Ingestion\Application\SweepRawHistory;
use App\Modules\Ingestion\Contracts\Subscribe;
use App\Modules\Ingestion\Contracts\SubscribeInput;
use App\Modules\Ingestion\Contracts\SubscribeResult;
use App\Platform\Outbox\OutboxRelay;
use App\Platform\Tenancy\WorkspaceTransaction;
use App\Support\Observability\MetricEmitter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 2.19 against the real PostgreSQL: Subscribe (upsert, throttled touch, access.context_missing, the new-cold-key budget), the dispatcher's demand
// rule (the minimum hot interval, nothing for an idle target, the hot-key budget), the cold per-user purge, the three metrics, and the roles' limits.
const DR_HEADERS = ['Referer' => 'http://localhost:8000'];

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();

    $this->digestFile = tempnam(sys_get_temp_dir(), 'dashflow-digest-key');
    file_put_contents($this->digestFile, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    config([
        'dashflow.secrets.digest_key_path.value' => $this->digestFile,
        'dashflow.tunables.sync.refresh_intervals.value' => '60,300,900',
        'dashflow.tunables.sync.hot_window.value' => '600',
        'dashflow.tunables.sync.cold_purge_after.value' => null,
        'dashflow.tunables.budgets.max_hot_keys_per_workspace.value' => null,
        'dashflow.tunables.budgets.max_new_cold_keys_per_membership_per_hour.value' => null,
        'dashflow.fetch.workspace_fair_share.value' => null,
    ]);
});

afterEach(fn () => @unlink($this->digestFile));

/** A scheduled target that is already due. */
function drTarget(string $workspace, array $columns = []): string
{
    return Cluster::seedSyncTarget($workspace, $columns + ['refresh_interval_seconds' => 60, 'next_due_at' => '2020-01-01']);
}

function drRow(string $id): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from sync_targets where id = ?', [$id])[0];
}

function drRun(): int
{
    Queue::fake();

    return app(DispatchDueSyncs::class)->run();
}

/** A metric spy; read what it saw from `->seen`. */
function drMetrics(): object
{
    $spy = new class implements MetricEmitter
    {
        /** @var list<array{0: string, 1: array<string, mixed>, 2: int}> */
        public array $seen = [];

        public function increment(string $name, array $labels = [], int $by = 1): void
        {
            $this->seen[] = [$name, $labels, $by];
        }
    };
    app()->instance(MetricEmitter::class, $spy);

    return $spy;
}

// ---- The dispatcher: demand -----------------------------------------------------------------------------------------

it('fetches a target at the smallest interval of its hot subscriptions', function () {
    $workspace = Cluster::workspace('Acme');
    $target = drTarget($workspace);
    Cluster::seedSubscription($workspace, $target, ['refresh_interval_seconds' => 300]);
    Cluster::seedSubscription($workspace, $target, ['refresh_interval_seconds' => 60]);

    expect(drRun())->toBe(1);
    Queue::assertPushed(FetchJob::class, fn (FetchJob $job): bool => $job->workspaceId === $workspace && $job->syncGroupId === $target && $job->dispatchSeq === 1);

    $row = drRow($target);
    expect($row['dispatch_seq'])->toBe(1)
        ->and(strtotime($row['next_due_at']) - time())->toBeGreaterThan(50)->toBeLessThanOrEqual(61)
        ->and($row['budget_limited'])->toBeFalse();
});

it('does not fetch an idle target, one whose subscriptions went cold, or one with none; the demand rule off keeps the old schedule', function () {
    $workspace = Cluster::workspace('Acme');
    $none = drTarget($workspace);
    $cold = drTarget($workspace);
    Cluster::seedSubscription($workspace, $cold, ['hot_until' => gmdate('c', time() - 5)]);
    $unset = drTarget($workspace);
    Cluster::seedSubscription($workspace, $unset, ['hot_until' => null]);

    expect(drRun())->toBe(0)
        ->and(drRow($none)['dispatch_seq'])->toBe(0)->and(drRow($cold)['dispatch_seq'])->toBe(0)->and(drRow($unset)['dispatch_seq'])->toBe(0);
    Queue::assertNothingPushed();

    foreach ([null, '', 'soon', '0', '1.5'] as $setting) {
        config(['dashflow.tunables.sync.hot_window.value' => $setting]);
        Cluster::superuser()->exec("update sync_targets set dispatch_seq = 0, next_due_at = '2020-01-01'");

        expect(drRun())->toBe(3, "hot_window {$setting}");
    }
});

it('widens a target over the hot-key budget to the next larger interval, marks it budget-limited, emits the metric and queues nothing beyond the batch', function () {
    config(['dashflow.tunables.budgets.max_hot_keys_per_workspace.value' => '1']);
    $workspace = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    $first = drTarget($workspace);
    $second = drTarget($workspace);
    $third = drTarget($workspace);
    $alone = drTarget($other);
    Cluster::seedSubscription($workspace, $first, ['last_access_at' => gmdate('c', time() - 10)]);
    Cluster::seedSubscription($workspace, $second, ['last_access_at' => gmdate('c', time() - 20)]);
    Cluster::seedSubscription($workspace, $third, ['last_access_at' => gmdate('c', time() - 30), 'refresh_interval_seconds' => 900]);
    Cluster::seedSubscription($other, $alone);
    $spy = drMetrics();

    expect(drRun())->toBe(4);

    $within = fn (string $id, int $seconds) => expect(strtotime(drRow($id)['next_due_at']) - time())->toBeGreaterThan($seconds - 10)->toBeLessThanOrEqual($seconds + 1);
    // The most recently watched keeps 60 s; the second widens to the next larger entry (300); the third has none larger and stays, still limited.
    $within($first, 60);
    $within($second, 300);
    $within($third, 900);
    $within($alone, 60);
    expect(drRow($first)['budget_limited'])->toBeFalse()
        ->and(drRow($second)['budget_limited'])->toBeTrue()
        ->and(drRow($third)['budget_limited'])->toBeTrue()
        ->and(drRow($alone)['budget_limited'])->toBeFalse()
        ->and($spy->seen)->toHaveCount(1)
        ->and($spy->seen[0][0])->toBe('dashflow.ingestion.budget_limited')
        ->and($spy->seen[0][2])->toBe(2)
        ->and($spy->seen[0][1])->toHaveKeys(['workspace_id', 'request_id'])
        ->and($spy->seen[0][1]['workspace_id'])->toBe($workspace);

    // Back under budget on the next due time: the mark is cleared.
    config(['dashflow.tunables.budgets.max_hot_keys_per_workspace.value' => null]);
    Cluster::superuser()->exec("update sync_targets set next_due_at = '2020-01-01'");
    expect(drRun())->toBe(4)->and(drRow($second)['budget_limited'])->toBeFalse();
});

it('leaves every budget off when its setting is unset or malformed', function (mixed $setting) {
    config(['dashflow.tunables.budgets.max_hot_keys_per_workspace.value' => $setting]);
    $workspace = Cluster::workspace('Acme');
    $targets = [drTarget($workspace), drTarget($workspace)];

    foreach ($targets as $target) {
        Cluster::seedSubscription($workspace, $target);
    }

    $spy = drMetrics();

    expect(drRun())->toBe(2)->and($spy->seen)->toBe([])
        ->and(drRow($targets[0])['budget_limited'])->toBeFalse()->and(drRow($targets[1])['budget_limited'])->toBeFalse();
})->with([null, '', 'many', '0', '-3', '1.5']);

it('gives role system only the hot subscription rows and columns, and the one new column to change', function () {
    $workspace = Cluster::workspace('Acme');
    $target = drTarget($workspace);
    $hot = Cluster::seedSubscription($workspace, $target);
    $cold = Cluster::seedSubscription($workspace, $target, ['hot_until' => gmdate('c', time() - 60)]);
    $unset = Cluster::seedSubscription($workspace, $target, ['hot_until' => null]);
    $system = Cluster::system();

    expect(Cluster::rows($system, 'select sync_target_id from sync_subscriptions'))->toHaveCount(1);

    foreach (['id', 'block_version_id', 'role', 'compute_context', 'period_start'] as $column) {
        expect(fn () => $system->query("select {$column} from sync_subscriptions"))->toThrow(PDOException::class, 'permission denied');
    }

    expect(fn () => $system->exec('update sync_subscriptions set hot_until = now()'))->toThrow(PDOException::class, 'permission denied')
        ->and(fn () => $system->exec('delete from sync_subscriptions'))->toThrow(PDOException::class, 'permission denied')
        ->and(fn () => $system->exec("insert into sync_subscriptions (id, workspace_id) values (gen_random_uuid(), '{$workspace}')"))->toThrow(PDOException::class, 'permission denied')
        ->and(fn () => $system->exec('update sync_targets set user_scoped = true'))->toThrow(PDOException::class, 'permission denied')
        ->and($system->exec('update sync_targets set budget_limited = true'))->toBe(1)
        ->and([$hot, $cold, $unset])->each->toBeString();
});

// ---- Subscribe -----------------------------------------------------------------------------------------------------------

/** @return array{0: string, 1: string, 2: string} Workspace, Data Source, the signed-in Admin's membership */
function drSetup(): array
{
    $workspace = Cluster::workspace('Acme');
    $user = Cluster::user('ada-'.Str::random(6).'@example.test');
    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspace, $user, 'admin', 'active']);

    foreach (['data_sources.manage'] as $permission) {
        Cluster::superuser()->prepare('INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
            ->execute([(string) Str::uuid7(), $workspace, $membership, $permission]);
    }

    Cluster::seedHostEntry($workspace, 'api.example.com');
    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspace, 'area' => 'admin']);

    return [$workspace, Cluster::seedDataSource($workspace, 'Sales API'), $membership];
}

/** @return string the Endpoint ID */
function drEndpoint(string $source, array $body): string
{
    $body += ['method' => 'GET', 'path' => '/revenue', 'headers' => [], 'body_template' => null, 'read_only_query' => false, 'confirm_read_only' => false];

    return (string) test()->postJson("/api/v1/admin/data-sources/{$source}/endpoints", $body, DR_HEADERS)->assertCreated()->json('data.endpoint_id');
}

function drSubscribe(string $workspace, string $source, string $endpoint, array $overrides = []): SubscribeResult
{
    $input = new SubscribeInput(...($overrides + [
        'workspaceId' => $workspace, 'dataSourceId' => $source, 'endpointId' => $endpoint, 'blockVersionId' => '0190aaaa-0000-7000-8000-000000000001',
        'role' => 'primary', 'refreshIntervalSeconds' => 60, 'computeContext' => 'ctx-a', 'periodStart' => '2026-01-01', 'periodEnd' => '2026-01-31',
    ]));

    return app(WorkspaceTransaction::class)->run($workspace, fn () => app(Subscribe::class)->subscribe($input));
}

/** @return list<array<string, mixed>> */
function drSubs(): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from sync_subscriptions order by created_at, id');
}

const DR_PERIOD = ['params' => [
    ['name' => 'region', 'binding' => 'fixed', 'value' => 'emea'],
    ['name' => 'from', 'binding' => 'period_start'],
    ['name' => 'to', 'binding' => 'period_end'],
]];

it('upserts a subscription with the interval copied and hot_until a hot window after the last access, and shares the target of the same key', function () {
    [$workspace, $source] = drSetup();
    $endpoint = drEndpoint($source, DR_PERIOD);

    $result = drSubscribe($workspace, $source, $endpoint);

    expect($result->ok())->toBeTrue()->and($result->reason)->toBeNull();
    $rows = drSubs();
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray([
            'workspace_id' => $workspace, 'sync_target_id' => $result->syncTargetId, 'block_version_id' => '0190aaaa-0000-7000-8000-000000000001',
            'role' => 'primary', 'compute_context' => 'ctx-a', 'refresh_interval_seconds' => 60, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31',
        ])
        ->and(strtotime($rows[0]['hot_until']) - strtotime($rows[0]['last_access_at']))->toBe(600)
        ->and($result->hotUntil)->toBe(gmdate('Y-m-d\TH:i:s\Z', strtotime($rows[0]['hot_until'])));

    $target = drRow($result->syncTargetId);
    expect($target)->toMatchArray(['user_scoped' => false, 'endpoint_id' => $endpoint, 'refresh_interval_seconds' => 60])
        ->and($target['fetch_key'])->toStartWith('fk1:');

    // Another Block version and the comparison role share the one target; another period is another target.
    $again = drSubscribe($workspace, $source, $endpoint, ['blockVersionId' => '0190aaaa-0000-7000-8000-000000000002', 'role' => 'comparison', 'refreshIntervalSeconds' => 300]);
    $other = drSubscribe($workspace, $source, $endpoint, ['periodStart' => '2025-01-01', 'periodEnd' => '2025-01-31']);

    expect($again->syncTargetId)->toBe($result->syncTargetId)->and($other->syncTargetId)->not->toBe($result->syncTargetId)
        ->and(drSubs())->toHaveCount(3);
});

it('keeps hot_until unset while hot_window is unset or malformed, and a touch changes last_access_at only past the throttle', function () {
    [$workspace, $source] = drSetup();
    $endpoint = drEndpoint($source, DR_PERIOD);
    config(['dashflow.tunables.sync.hot_window.value' => 'a while']);

    $result = drSubscribe($workspace, $source, $endpoint);
    expect($result->hotUntil)->toBeNull()->and(drSubs()[0]['hot_until'])->toBeNull();

    config(['dashflow.tunables.sync.hot_window.value' => '600']);
    $before = drSubs()[0]['last_access_at'];

    // Touched at once: inside the throttle (the interval copied at subscribe time), nothing moves, and the new interval is not taken.
    $touch = drSubscribe($workspace, $source, $endpoint, ['refreshIntervalSeconds' => 999]);
    expect($touch->ok())->toBeTrue()->and(drSubs()[0])->toMatchArray(['last_access_at' => $before, 'hot_until' => null, 'refresh_interval_seconds' => 60]);

    // Past the throttle the touch moves it and makes it hot.
    Cluster::superuser()->exec("update sync_subscriptions set last_access_at = now() - interval '61 seconds'");
    $touch = drSubscribe($workspace, $source, $endpoint);
    $row = drSubs()[0];

    expect(strtotime($row['last_access_at']))->toBeGreaterThan(time() - 5)
        ->and(strtotime($row['hot_until']) - strtotime($row['last_access_at']))->toBe(600)
        ->and($row['refresh_interval_seconds'])->toBe(60)
        ->and($touch->hotUntil)->not->toBeNull()
        ->and(drSubs())->toHaveCount(1);
});

it('makes the target hot for the dispatcher once subscribed', function () {
    [$workspace, $source] = drSetup();
    $endpoint = drEndpoint($source, DR_PERIOD);
    $result = drSubscribe($workspace, $source, $endpoint);

    expect(drRun())->toBe(1);
    Queue::assertPushed(FetchJob::class, fn (FetchJob $job): bool => $job->syncGroupId === $result->syncTargetId);
});

const DR_USER = ['params' => [
    ['name' => 'uid', 'binding' => 'user_id'],
    ['name' => 'mail', 'binding' => 'user_email'],
]];

it('returns access.context_missing and creates no target and no subscription when a bound attribute is missing or empty', function (array $bound) {
    [$workspace, $source, $membership] = drSetup();
    $endpoint = drEndpoint($source, ['params' => [['name' => 'uid', 'binding' => 'user_id']]]);
    $targets = count(Cluster::rows(Cluster::superuser(), 'select id from sync_targets'));

    $result = drSubscribe($workspace, $source, $endpoint, ['bound' => $bound, 'membershipId' => $membership]);

    expect($result->ok())->toBeFalse()->and($result->reason)->toBe('access.context_missing')->and($result->syncTargetId)->toBeNull()
        ->and(drSubs())->toBe([])
        ->and(Cluster::rows(Cluster::superuser(), 'select id from sync_targets'))->toHaveCount($targets);
})->with(['absent' => [[]], 'empty' => [['uid' => '']], 'null' => [['uid' => null]], 'not a string' => [['uid' => 7]]]);

it('makes a user-scoped target for bound data, per member, never scheduled by itself, with no value of the user stored', function () {
    [$workspace, $source, $membership] = drSetup();
    $endpoint = drEndpoint($source, DR_USER);
    $bound = ['uid' => 'CANARY-user-17', 'mail' => 'CANARY-emea'];

    $one = drSubscribe($workspace, $source, $endpoint, ['bound' => $bound, 'membershipId' => $membership]);
    $same = drSubscribe($workspace, $source, $endpoint, ['bound' => $bound, 'membershipId' => $membership, 'blockVersionId' => '0190aaaa-0000-7000-8000-000000000009']);
    $diff = drSubscribe($workspace, $source, $endpoint, ['bound' => ['uid' => 'u-18', 'mail' => 'apac'], 'membershipId' => $membership]);

    expect($one->ok())->toBeTrue()->and($same->syncTargetId)->toBe($one->syncTargetId)->and($diff->syncTargetId)->not->toBe($one->syncTargetId);

    $row = drRow($one->syncTargetId);
    expect($row)->toMatchArray(['user_scoped' => true, 'created_by_membership_id' => $membership, 'next_due_at' => null])
        ->and(json_encode(Cluster::rows(Cluster::superuser(), 'select * from sync_targets'), JSON_THROW_ON_ERROR))->not->toContain('CANARY')
        ->and(json_encode(drSubs(), JSON_THROW_ON_ERROR))->not->toContain('CANARY');

    // A user-scoped target is not fetched on a schedule even when hot.
    expect(drRun())->toBe(0);
});

it('refuses a new per-user target over the new-cold-key budget, with the metric, and still serves a key that exists', function () {
    config(['dashflow.tunables.budgets.max_new_cold_keys_per_membership_per_hour.value' => '2']);
    [$workspace, $source, $membership] = drSetup();
    $endpoint = drEndpoint($source, DR_USER);
    $spy = drMetrics();
    $bound = fn (string $n): array => ['bound' => ['uid' => "u-{$n}", 'mail' => 'emea'], 'membershipId' => $membership];

    expect(drSubscribe($workspace, $source, $endpoint, $bound('1'))->ok())->toBeTrue()
        ->and(drSubscribe($workspace, $source, $endpoint, $bound('2'))->ok())->toBeTrue();

    $refused = drSubscribe($workspace, $source, $endpoint, $bound('3'));
    expect($refused->ok())->toBeFalse()->and($refused->reason)->toBe(SubscribeResult::BUDGET_LIMITED)->and($refused->budgetLimited)->toBeTrue()
        ->and(Cluster::rows(Cluster::superuser(), 'select id from sync_targets where user_scoped'))->toHaveCount(2)
        ->and($spy->seen)->toHaveCount(1)
        ->and($spy->seen[0][0])->toBe('dashflow.ingestion.budget_limited')
        ->and($spy->seen[0][1])->toHaveKeys(['workspace_id', 'request_id']);

    // An existing key is not new: still served. An hour later the member may make another.
    expect(drSubscribe($workspace, $source, $endpoint, $bound('1') + ['blockVersionId' => '0190aaaa-0000-7000-8000-000000000003'])->ok())->toBeTrue();
    Cluster::superuser()->exec("update sync_targets set created_at = now() - interval '2 hours'");
    expect(drSubscribe($workspace, $source, $endpoint, $bound('3'))->ok())->toBeTrue();
});

it('reports no period or unresolvable fixed row as a reason, not an exception, and rejects a malformed call', function () {
    [$workspace, $source] = drSetup();
    $endpoint = drEndpoint($source, ['params' => [['name' => 'from', 'binding' => 'period_start']]]);

    expect(drSubscribe($workspace, $source, $endpoint, ['periodStart' => null])->reason)->toBe(SubscribeResult::PERIOD_MISSING)
        ->and(drSubs())->toBe([]);

    foreach ([['role' => 'live'], ['refreshIntervalSeconds' => 0], ['periodStart' => '2026-02-30'], ['blockVersionId' => 'nope']] as $bad) {
        expect(fn () => drSubscribe($workspace, $source, $endpoint, $bad))->toThrow(InvalidArgumentException::class);
    }
});

it('keeps a target made by a subscription when the Endpoint event arrives, and retires one of an earlier revision', function () {
    [$workspace, $source] = drSetup();
    $endpoint = drEndpoint($source, DR_PERIOD);
    $result = drSubscribe($workspace, $source, $endpoint);
    app(OutboxRelay::class)->relay();

    expect(drRow($result->syncTargetId)['retired_at'])->toBeNull();

    $revised = test()->putJson("/api/v1/admin/data-sources/{$source}/endpoints/{$endpoint}", [
        'method' => 'GET', 'path' => '/revenue', 'headers' => [], 'body_template' => null, 'read_only_query' => false, 'confirm_read_only' => false,
        'params' => [['name' => 'region', 'binding' => 'fixed', 'value' => 'apac'], ['name' => 'from', 'binding' => 'period_start'], ['name' => 'to', 'binding' => 'period_end']],
        'revision' => 1,
    ], DR_HEADERS);
    $revised->assertOk();
    app(OutboxRelay::class)->relay();

    expect(drRow($result->syncTargetId)['retired_at'])->not->toBeNull();
});

// ---- Cold purge and the metrics ----------------------------------------------------------------------------------------

it('purges a cold per-user target and its payloads after cold_purge_after, and never a hot one, a shared one or one that is not past it', function () {
    config(['dashflow.tunables.sync.cold_purge_after.value' => '3600']);
    $workspace = Cluster::workspace('Acme');
    $old = Cluster::seedSyncTarget($workspace, ['user_scoped' => true]);
    $oldBody = Cluster::seedRawBody($workspace, $old, '{"a":1}');
    Cluster::seedSubscription($workspace, $old, ['last_access_at' => gmdate('c', time() - 9000), 'hot_until' => gmdate('c', time() - 7200)]);
    $recent = Cluster::seedSyncTarget($workspace, ['user_scoped' => true]);
    Cluster::seedSubscription($workspace, $recent, ['hot_until' => gmdate('c', time() - 600)]);
    $hot = Cluster::seedSyncTarget($workspace, ['user_scoped' => true]);
    Cluster::seedSubscription($workspace, $hot, ['hot_until' => gmdate('c', time() + 600)]);
    Cluster::seedSubscription($workspace, $hot, ['hot_until' => gmdate('c', time() - 90000)]);
    $shared = Cluster::seedSyncTarget($workspace, ['created_at' => gmdate('c', time() - 90000)]);
    Cluster::seedSubscription($workspace, $shared, ['hot_until' => gmdate('c', time() - 90000)]);
    $abandoned = Cluster::seedSyncTarget($workspace, ['user_scoped' => true, 'created_at' => gmdate('c', time() - 90000)]);

    $done = app(SweepRawHistory::class)->run();

    $left = array_column(Cluster::rows(Cluster::superuser(), 'select id from sync_targets'), 'id');
    expect($done['targets'])->toBe(2)
        ->and($left)->toEqualCanonicalizing([$recent, $hot, $shared])
        ->and(Cluster::rows(Cluster::superuser(), 'select id from raw_bodies where id = ?', [$oldBody]))->toBe([])
        ->and(Cluster::rows(Cluster::superuser(), 'select id from sync_subscriptions where sync_target_id = ?', [$old]))->toBe([])
        ->and(Cluster::rows(Cluster::superuser(), 'select id from sync_subscriptions'))->toHaveCount(4)
        ->and($abandoned)->toBeString();
});

it('judges a subscription with no hot_until by its last access, so a recently touched one is kept', function () {
    config(['dashflow.tunables.sync.cold_purge_after.value' => '3600']);
    $workspace = Cluster::workspace('Acme');
    $old = Cluster::seedSyncTarget($workspace, ['user_scoped' => true]);
    Cluster::seedSubscription($workspace, $old, ['last_access_at' => gmdate('c', time() - 9000), 'hot_until' => null]);
    $recent = Cluster::seedSyncTarget($workspace, ['user_scoped' => true]);
    Cluster::seedSubscription($workspace, $recent, ['last_access_at' => gmdate('c', time() - 600), 'hot_until' => null]);

    app(SweepRawHistory::class)->run();

    expect(array_column(Cluster::rows(Cluster::superuser(), 'select id from sync_targets'), 'id'))->toBe([$recent]);
});

it('purges no per-user target while cold_purge_after is unset or malformed', function (mixed $setting) {
    config(['dashflow.tunables.sync.cold_purge_after.value' => $setting]);
    $workspace = Cluster::workspace('Acme');
    Cluster::seedSyncTarget($workspace, ['user_scoped' => true, 'created_at' => gmdate('c', time() - 999999)]);

    expect(app(SweepRawHistory::class)->run()['targets'])->toBe(0)
        ->and(Cluster::rows(Cluster::superuser(), 'select id from sync_targets'))->toHaveCount(1);
})->with([null, '', 'later', '0', '-1']);

it('emits hot_targets and cold_targets with the Workspace and request ID, and only while hot_window is set', function () {
    $workspace = Cluster::workspace('Acme');
    $hot = Cluster::seedSyncTarget($workspace);
    Cluster::seedSubscription($workspace, $hot);
    Cluster::seedSyncTarget($workspace);
    Cluster::seedSyncTarget($workspace, ['retired_at' => gmdate('c', time() - 5)]);
    $spy = drMetrics();

    app(SweepRawHistory::class)->run();

    $byName = [];
    foreach ($spy->seen as [$name, $labels, $by]) {
        $byName[$name] = [$labels, $by];
    }

    expect($byName['dashflow.ingestion.hot_targets'][1])->toBe(1)
        ->and($byName['dashflow.ingestion.cold_targets'][1])->toBe(1)
        ->and($byName['dashflow.ingestion.hot_targets'][0])->toHaveKeys(['workspace_id', 'request_id'])
        ->and($byName['dashflow.ingestion.cold_targets'][0]['workspace_id'])->toBe($workspace);

    config(['dashflow.tunables.sync.hot_window.value' => null]);
    $spy = drMetrics();
    app(SweepRawHistory::class)->run();
    expect($spy->seen)->toBe([]);
});

it('isolates subscriptions by Workspace', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $sub = Cluster::seedSubscription($a);
    Cluster::seedSubscription($b);

    $seen = app(WorkspaceTransaction::class)->run($b, fn () => array_column(DB::select('select id from sync_subscriptions'), 'id'));

    expect($seen)->toHaveCount(1)->not->toContain($sub);
    expect(fn () => app(WorkspaceTransaction::class)->run($b, fn () => DB::insert(
        'insert into sync_subscriptions (id, workspace_id, sync_target_id, block_version_id, role, refresh_interval_seconds, last_access_at) values (?, ?, ?, ?, ?, 60, now())',
        [(string) Str::uuid7(), $a, Cluster::seedSyncTarget($a), (string) Str::uuid7(), 'primary'],
    )))->toThrow(Exception::class);
});

it('adds the subscriptions table and the target columns in one migration that rolls back and runs again', function () {
    $count = fn (string $sql): int => (int) Cluster::rows(Cluster::superuser(), $sql)[0]['n'];
    $present = fn (): array => [
        $count("select count(*) as n from information_schema.tables where table_name = 'sync_subscriptions'"),
        $count("select count(*) as n from information_schema.columns where table_name = 'sync_targets' and column_name in ('user_scoped', 'created_by_membership_id', 'budget_limited')"),
    ];

    expect($present())->toBe([1, 3]);

    try {
        expect(Artisan::call('migrate:rollback', ['--database' => 'migrator', '--step' => 1, '--force' => true]))->toBe(0)
            ->and($present())->toBe([0, 0]);
    } finally {
        Artisan::call('migrate', ['--database' => 'migrator', '--force' => true]);
    }

    expect($present())->toBe([1, 3]);
});
