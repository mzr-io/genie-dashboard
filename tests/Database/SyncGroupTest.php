<?php

use App\Models\User;
use App\Modules\Connector\Contracts\ConnectionTestCode;
use App\Modules\Connector\Contracts\EndpointFetcher;
use App\Modules\Connector\Contracts\EndpointFetchResult;
use App\Modules\Connector\Contracts\EndpointFetchSpec;
use App\Modules\Connector\Contracts\FailureClass;
use App\Modules\Ingestion\Application\DispatchDueSyncs;
use App\Modules\Ingestion\Application\FetchJob;
use App\Modules\Ingestion\Application\ReemitPayloadEvents;
use App\Modules\Ingestion\Contracts\Subscribe;
use App\Modules\Ingestion\Contracts\SubscribeInput;
use App\Modules\Ingestion\Contracts\SubscribeResult;
use App\Modules\Ingestion\Contracts\SyncGenerations;
use App\Modules\RawStore\Contracts\RawPayload;
use App\Modules\RawStore\Contracts\RawStore;
use App\Modules\RawStore\Infrastructure\PostgresRawStore;
use App\Platform\Outbox\OutboxRelay;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 2.20 against the real PostgreSQL: a comparison target linked to its primary is one sync group with one fence; one run keeps both payloads and
// one generation in one transaction, a failed comparison leaves the primary stored and the generation incomplete, compute reads only complete
// generations, a late run is superseded, and the sweep re-emits an event a consumer is missing. The fetcher is a fake keyed by Endpoint.
const SG_HEADERS = ['Referer' => 'http://localhost:8000'];

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
    config([
        'dashflow.tunables.sync.refresh_intervals.value' => '60,300',
        'dashflow.tunables.sync.hot_window.value' => '600',
        'dashflow.tunables.sync.cold_purge_after.value' => null,
        'dashflow.tunables.budgets.max_hot_keys_per_workspace.value' => null,
        'dashflow.tunables.budgets.max_new_cold_keys_per_membership_per_hour.value' => null,
        'dashflow.fetch.workspace_fair_share.value' => null,
    ]);
});

/** A fetcher that answers by Endpoint: a body string is a good answer, `false` a failed one, a Closure runs first and answers with its return. */
function sgFetcher(array $answers): object
{
    $fetcher = new class($answers) implements EndpointFetcher
    {
        /** @var list<string> */
        public array $calls = [];

        public function __construct(public array $answers) {}

        public function fetch(EndpointFetchSpec $spec): EndpointFetchResult
        {
            $this->calls[] = $spec->endpointId;
            $answer = $this->answers[$spec->endpointId];
            $answer = $answer instanceof Closure ? $answer() : $answer;

            if ($answer === false) {
                return new EndpointFetchResult(false, null, 500, 1, 0, ConnectionTestCode::FetchFailed, 'http_500', 'https://api.example.com/x', [], dataSourceId: $spec->dataSourceId, failureClass: FailureClass::Transient);
            }

            return new EndpointFetchResult(true, $answer, 200, 1, strlen($answer), null, null, 'https://api.example.com/x', [], dataSourceId: $spec->dataSourceId);
        }
    };
    app()->instance(EndpointFetcher::class, $fetcher);

    return $fetcher;
}

/** @return array{workspace: string, primary: string, comparison: string, pe: string, ce: string} a due primary and its linked comparison on one Data Source */
function sgGroup(array $primary = [], array $comparison = []): array
{
    $workspace = Cluster::workspace('Acme');
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $pe = (string) Str::uuid7();
    $ce = (string) Str::uuid7();
    $p = Cluster::seedSyncTarget($workspace, $primary + ['data_source_id' => $source, 'endpoint_id' => $pe, 'refresh_interval_seconds' => 60, 'next_due_at' => '2020-01-01']);
    $c = Cluster::seedSyncTarget($workspace, $comparison + [
        'data_source_id' => $source, 'endpoint_id' => $ce, 'sync_group_id' => $p, 'group_primary_target_id' => $p, 'refresh_interval_seconds' => 60, 'next_due_at' => '2020-01-01',
    ]);

    return ['workspace' => $workspace, 'primary' => $p, 'comparison' => $c, 'pe' => $pe, 'ce' => $ce];
}

function sgDispatch(array $g, int $seq): void
{
    Cluster::superuser()->prepare('update sync_targets set dispatch_seq = greatest(dispatch_seq, ?) where id = ?')->execute([$seq, $g['primary']]);
    dispatch(new FetchJob($g['workspace'], $g['primary'], $seq));
}

function sgTarget(string $id): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from sync_targets where id = ?', [$id])[0];
}

/** @return list<array<string, mixed>> */
function sgGenerations(): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from sync_generations order by dispatch_seq');
}

/** @return list<array<string, mixed>> */
function sgEvents(): array
{
    return Cluster::rows(Cluster::superuser(), "select subject, subject_seq, data from outbox_events where type = 'ingestion.payload.changed' order by occurred_at, subject_seq, subject");
}

function sgBody(string $id): string
{
    return hex2bin(Cluster::rows(Cluster::superuser(), "select encode(body, 'hex') as hex from raw_bodies where id = ?", [$id])[0]['hex']);
}

// ---- Dispatch -------------------------------------------------------------------------------------------------------------------------

it('queues one fetch job for a due group on the primary seq, and never dispatches the comparison on its own', function () {
    $g = sgGroup();
    Cluster::seedSubscription($g['workspace'], $g['comparison'], ['role' => 'comparison', 'refresh_interval_seconds' => 300]);
    Cluster::seedSubscription($g['workspace'], $g['primary'], ['refresh_interval_seconds' => 60]);
    Queue::fake();

    expect(app(DispatchDueSyncs::class)->run())->toBe(1);
    Queue::assertPushed(FetchJob::class, 1);
    Queue::assertPushed(FetchJob::class, fn (FetchJob $job): bool => $job->workspaceId === $g['workspace'] && $job->syncGroupId === $g['primary'] && $job->dispatchSeq === 1);

    expect(sgTarget($g['primary'])['dispatch_seq'])->toBe(1)
        ->and(sgTarget($g['comparison'])['dispatch_seq'])->toBe(0)
        // The minimum hot interval among the members' subscriptions: the primary's 60 s.
        ->and(strtotime(sgTarget($g['primary'])['next_due_at']) - time())->toBeGreaterThan(50)->toBeLessThanOrEqual(61);
});

it('makes the group hot when only the comparison is watched, at the comparison interval, and leaves an unwatched group alone', function () {
    $g = sgGroup();
    $idle = sgGroup();
    Cluster::seedSubscription($g['workspace'], $g['comparison'], ['role' => 'comparison', 'refresh_interval_seconds' => 300]);
    Queue::fake();

    expect(app(DispatchDueSyncs::class)->run())->toBe(1);
    Queue::assertPushed(FetchJob::class, fn (FetchJob $job): bool => $job->syncGroupId === $g['primary']);

    expect(strtotime(sgTarget($g['primary'])['next_due_at']) - time())->toBeGreaterThan(290)->toBeLessThanOrEqual(301)
        ->and(sgTarget($idle['primary'])['dispatch_seq'])->toBe(0);
});

it('dispatches a group once per tick with the demand rule off, and not its comparison', function () {
    config(['dashflow.tunables.sync.hot_window.value' => null]);
    $g = sgGroup();
    Queue::fake();

    expect(app(DispatchDueSyncs::class)->run())->toBe(1);
    Queue::assertPushed(FetchJob::class, 1);
    expect(sgTarget($g['comparison'])['dispatch_seq'])->toBe(0);
});

// ---- Link -----------------------------------------------------------------------------------------------------------------------------

/** @return array{0: string, 1: string} Workspace, Data Source */
function sgSetup(): array
{
    $workspace = Cluster::workspace('Acme');
    $user = Cluster::user('ada-'.Str::random(6).'@example.test');
    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspace, $user, 'admin', 'active']);
    Cluster::superuser()->prepare("INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, 'data_sources.manage', now(), now())")
        ->execute([(string) Str::uuid7(), $workspace, $membership]);
    Cluster::seedHostEntry($workspace, 'api.example.com');
    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspace, 'area' => 'admin']);

    return [$workspace, Cluster::seedDataSource($workspace, 'Sales API')];
}

function sgEndpoint(string $source, string $path): string
{
    $body = ['method' => 'GET', 'path' => $path, 'params' => [['name' => 'region', 'binding' => 'fixed', 'value' => 'emea']], 'headers' => [], 'body_template' => null, 'read_only_query' => false, 'confirm_read_only' => false];

    return (string) test()->postJson("/api/v1/admin/data-sources/{$source}/endpoints", $body, SG_HEADERS)->assertCreated()->json('data.endpoint_id');
}

function sgSubscribe(string $workspace, string $source, string $endpoint, string $role, ?string $primary = null): SubscribeResult
{
    $input = new SubscribeInput($workspace, $source, $endpoint, '0190aaaa-0000-7000-8000-000000000001', $role, 60, primaryTargetId: $primary);

    return app(WorkspaceTransaction::class)->run($workspace, fn () => app(Subscribe::class)->subscribe($input));
}

it('links a comparison subscription to its primary: it shares the primary group and stores its primary', function () {
    [$workspace, $source] = sgSetup();
    $primary = sgSubscribe($workspace, $source, sgEndpoint($source, '/a'), 'primary');
    $comparison = sgSubscribe($workspace, $source, sgEndpoint($source, '/b'), 'comparison', $primary->syncTargetId);

    expect($comparison->ok())->toBeTrue()
        ->and(sgTarget($comparison->syncTargetId))->toMatchArray(['sync_group_id' => $primary->syncTargetId, 'group_primary_target_id' => $primary->syncTargetId, 'applied_seq' => 0])
        ->and(sgTarget($primary->syncTargetId))->toMatchArray(['sync_group_id' => $primary->syncTargetId, 'group_primary_target_id' => null]);

    // Subscribing again is a no-op; a second comparison for the same primary, or a comparison that is itself a primary, is a conflict.
    expect(sgSubscribe($workspace, $source, sgEndpoint($source, '/b'), 'comparison', $primary->syncTargetId)->ok())->toBeFalse()
        ->and(sgSubscribe($workspace, $source, sgEndpoint($source, '/c'), 'comparison', $primary->syncTargetId)->reason)->toBe(SubscribeResult::GROUP_CONFLICT);
});

it('refuses an unknown, foreign, retired or comparison primary with a reason and creates nothing', function () {
    [$workspace, $source] = sgSetup();
    $primary = sgSubscribe($workspace, $source, sgEndpoint($source, '/a'), 'primary');
    $comparison = sgSubscribe($workspace, $source, sgEndpoint($source, '/b'), 'comparison', $primary->syncTargetId);
    $other = Cluster::workspace('Other');
    $foreign = Cluster::seedSyncTarget($other);
    $retired = Cluster::seedSyncTarget($workspace, ['retired_at' => 'now']);
    $endpoint = sgEndpoint($source, '/d');
    $before = count(Cluster::rows(Cluster::superuser(), 'select id from sync_targets'));

    foreach ([(string) Str::uuid7(), $foreign, $retired, $comparison->syncTargetId] as $unknown) {
        $result = sgSubscribe($workspace, $source, $endpoint, 'comparison', $unknown);
        expect($result->ok())->toBeFalse()->and($result->reason)->toBe(SubscribeResult::PRIMARY_UNKNOWN);
    }

    expect(count(Cluster::rows(Cluster::superuser(), 'select id from sync_targets')))->toBe($before)
        ->and(fn () => sgSubscribe($workspace, $source, $endpoint, 'primary', $primary->syncTargetId))->toThrow(InvalidArgumentException::class);
});

// ---- The group fetch ------------------------------------------------------------------------------------------------------------------

it('fetches both targets in one job under one fence, and keeps both payloads and one complete generation, with the event carrying it', function () {
    $g = sgGroup();
    $fetcher = sgFetcher([$g['pe'] => '{"total":100}', $g['ce'] => '{"total":80}']);

    sgDispatch($g, 1);

    $primary = sgTarget($g['primary']);
    $comparison = sgTarget($g['comparison']);
    $generations = sgGenerations();

    expect($fetcher->calls)->toBe([$g['pe'], $g['ce']])
        ->and($primary)->toMatchArray(['applied_seq' => 1, 'payload_seq' => 1, 'consecutive_failures' => 0])
        ->and($comparison)->toMatchArray(['applied_seq' => 1, 'payload_seq' => 1, 'dispatch_seq' => 0])
        ->and(sgBody($primary['current_payload_id']))->toBe('{"total":100}')
        ->and(sgBody($comparison['current_payload_id']))->toBe('{"total":80}')
        ->and($generations)->toHaveCount(1)
        ->and($generations[0])->toMatchArray([
            'sync_group_id' => $g['primary'], 'primary_target_id' => $g['primary'], 'comparison_target_id' => $g['comparison'], 'dispatch_seq' => 1,
            'primary_ok' => true, 'comparison_ok' => true, 'complete' => true,
            'primary_payload_id' => $primary['current_payload_id'], 'comparison_payload_id' => $comparison['current_payload_id'],
            'primary_payload_seq' => 1, 'comparison_payload_seq' => 1,
        ]);

    $events = sgEvents();
    expect($events)->toHaveCount(2);

    foreach ($events as $event) {
        $data = json_decode($event['data'], true);
        expect($data)->toMatchArray(['sync_group_id' => $g['primary'], 'generation_id' => $generations[0]['id'], 'generation_complete' => true, 'failed_side' => null, 'dispatch_seq' => 1]);
    }

    $generation = app(SyncGenerations::class)->latestComplete($g['workspace'], $g['primary']);
    expect($generation?->id)->toBe($generations[0]['id'])
        ->and($generation->primaryPayloadId)->toBe($primary['current_payload_id'])
        ->and(app(SyncGenerations::class)->comparisonUnavailable($g['workspace'], $g['primary']))->toBeFalse();

    // One run per side, both succeeded.
    expect(array_column(Cluster::rows(Cluster::superuser(), "select status from sync_runs where kind = 'scheduled_fetch'"), 'status'))->toBe(['succeeded', 'succeeded']);
});

it('stores the primary and writes an incomplete generation when the comparison fetch fails; the comparison is unavailable and the last complete generation stays readable', function () {
    $g = sgGroup();
    $answers = [$g['pe'] => '{"v":1}', $g['ce'] => '{"v":1}'];
    sgFetcher($answers);
    sgDispatch($g, 1);
    $first = sgGenerations()[0];

    // The race: the primary moves to a new payload while the comparison fetch fails.
    sgFetcher([$g['pe'] => '{"v":2}', $g['ce'] => false]);
    sgDispatch($g, 2);

    $primary = sgTarget($g['primary']);
    $comparison = sgTarget($g['comparison']);
    $generations = sgGenerations();

    expect($primary)->toMatchArray(['payload_seq' => 2, 'applied_seq' => 2])
        ->and(sgBody($primary['current_payload_id']))->toBe('{"v":2}')
        ->and($comparison)->toMatchArray(['payload_seq' => 1, 'consecutive_failures' => 1, 'applied_seq' => 2])
        ->and($generations)->toHaveCount(2)
        ->and($generations[1])->toMatchArray([
            'primary_ok' => true, 'comparison_ok' => false, 'complete' => false,
            'primary_payload_id' => $primary['current_payload_id'], 'comparison_payload_id' => null, 'comparison_payload_seq' => null,
        ]);

    // Never a mixed pair: compute reads generation 1 (old, old), and sees the comparison as unavailable.
    $read = app(SyncGenerations::class)->latestComplete($g['workspace'], $g['primary']);
    expect($read?->id)->toBe($first['id'])
        ->and($read->primaryPayloadSeq)->toBe(1)
        ->and(app(SyncGenerations::class)->comparisonUnavailable($g['workspace'], $g['primary']))->toBeTrue();

    $events = sgEvents();
    $last = json_decode(end($events)['data'], true);
    expect($last)->toMatchArray(['generation_id' => $generations[1]['id'], 'generation_complete' => false, 'failed_side' => 'comparison', 'payload_seq' => 2]);

    // The comparison recovers with an unchanged body: the group completes without a payload change, and the event says so.
    sgFetcher([$g['pe'] => '{"v":2}', $g['ce'] => '{"v":1}']);
    sgDispatch($g, 3);

    $generations = sgGenerations();
    expect($generations)->toHaveCount(3)
        ->and($generations[2])->toMatchArray(['complete' => true, 'primary_payload_seq' => 2, 'comparison_payload_seq' => 1])
        ->and(app(SyncGenerations::class)->latestComplete($g['workspace'], $g['primary'])?->dispatchSeq)->toBe(3)
        ->and(app(SyncGenerations::class)->comparisonUnavailable($g['workspace'], $g['primary']))->toBeFalse();
});

it('does not repeat an incomplete generation while the same sides keep failing, and writes none when both sides fail', function () {
    $g = sgGroup();
    sgFetcher([$g['pe'] => '{"v":1}', $g['ce'] => false]);
    sgDispatch($g, 1);
    sgDispatch($g, 2);
    sgDispatch($g, 3);

    expect(sgGenerations())->toHaveCount(1)
        ->and(sgTarget($g['comparison'])['consecutive_failures'])->toBe(3);

    $both = sgGroup();
    sgFetcher([$both['pe'] => false, $both['ce'] => false]);
    sgDispatch($both, 1);

    expect(Cluster::rows(Cluster::superuser(), 'select id from sync_generations where sync_group_id = ?', [$both['primary']]))->toBe([])
        ->and(sgTarget($both['primary']))->toMatchArray(['applied_seq' => 1, 'consecutive_failures' => 1])
        ->and(app(SyncGenerations::class)->latestComplete($both['workspace'], $both['primary']))->toBeNull();
});

it('still stores the comparison and marks the generation incomplete when the primary fetch fails', function () {
    $g = sgGroup();
    sgFetcher([$g['pe'] => false, $g['ce'] => '{"v":1}']);
    sgDispatch($g, 1);

    expect(sgGenerations())->toHaveCount(1)
        ->and(sgGenerations()[0])->toMatchArray(['primary_ok' => false, 'comparison_ok' => true, 'complete' => false, 'primary_payload_id' => null])
        ->and(app(SyncGenerations::class)->latestComplete($g['workspace'], $g['primary']))->toBeNull();
});

it('supersedes a late or duplicate group run without calling the source or writing anything', function () {
    $g = sgGroup();
    $fetcher = sgFetcher([$g['pe'] => '{"v":1}', $g['ce'] => '{"v":1}']);
    sgDispatch($g, 5);
    $after = [sgTarget($g['primary']), sgTarget($g['comparison'])];
    $calls = count($fetcher->calls);

    sgDispatch($g, 3);
    sgDispatch($g, 5);

    expect([sgTarget($g['primary']), sgTarget($g['comparison'])])->toBe($after)
        ->and($fetcher->calls)->toHaveCount($calls)
        ->and(sgGenerations())->toHaveCount(1)
        ->and(sgEvents())->toHaveCount(2);
});

it('supersedes a group run that loses the fence at commit, writing no payload, no generation and no event', function () {
    $g = sgGroup();
    // While the comparison is being fetched, dispatch 2 applies on another connection.
    sgFetcher([
        $g['pe'] => '{"slow":1}',
        $g['ce'] => function () use ($g) {
            Cluster::superuser()->prepare('update sync_targets set dispatch_seq = 2, applied_seq = 2 where id = ?')->execute([$g['primary']]);

            return '{"slow":2}';
        },
    ]);

    sgDispatch($g, 1);

    expect(sgTarget($g['primary']))->toMatchArray(['applied_seq' => 2, 'payload_seq' => 0, 'current_payload_id' => null])
        ->and(sgTarget($g['comparison']))->toMatchArray(['applied_seq' => 0, 'payload_seq' => 0])
        ->and(sgGenerations())->toBe([])
        ->and(sgEvents())->toBe([])
        ->and(Cluster::rows(Cluster::superuser(), 'select id from raw_bodies'))->toBe([]);
});

it('keeps both payloads and the generation or none of them: a body that cannot be stored rolls the whole group back', function () {
    $g = sgGroup();
    sgFetcher([$g['pe'] => '{"v":1}', $g['ce'] => '{"v":1}']);
    $store = new class implements RawStore
    {
        public int $puts = 0;

        public function put(string $workspaceId, string $syncTargetId, int $seq, int $dispatchSeq, string $body, ?string $requestId, DateTimeInterface $observedAt): RawPayload
        {
            if (++$this->puts === 2) {
                throw new RuntimeException('disk full');
            }

            return app(PostgresRawStore::class)->put($workspaceId, $syncTargetId, $seq, $dispatchSeq, $body, $requestId, $observedAt);
        }

        public function get(string $workspaceId, string $syncTargetId, string $payloadId): ?string
        {
            return null;
        }
    };
    app()->instance(RawStore::class, $store);

    sgDispatch($g, 1);

    expect($store->puts)->toBe(2)
        ->and(sgGenerations())->toBe([])
        ->and(sgEvents())->toBe([])
        ->and(Cluster::rows(Cluster::superuser(), 'select id from raw_bodies'))->toBe([])
        ->and(sgTarget($g['primary']))->toMatchArray(['payload_seq' => 0, 'current_payload_id' => null, 'applied_seq' => 1, 'consecutive_failures' => 1])
        ->and(sgTarget($g['comparison']))->toMatchArray(['payload_seq' => 0, 'current_payload_id' => null, 'consecutive_failures' => 1]);
});

it('keeps an ordinary single target working as before: no generation, and the event has no group fields', function () {
    $workspace = Cluster::workspace('Acme');
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = (string) Str::uuid7();
    $target = Cluster::seedSyncTarget($workspace, ['data_source_id' => $source, 'endpoint_id' => $endpoint, 'refresh_interval_seconds' => 60, 'next_due_at' => '2020-01-01']);
    sgFetcher([$endpoint => '{"v":1}']);

    sgDispatch(['workspace' => $workspace, 'primary' => $target], 1);

    expect(sgGenerations())->toBe([])
        ->and(array_keys(json_decode(sgEvents()[0]['data'], true)))->toEqualCanonicalizing(['sync_target_id', 'endpoint_id', 'payload_id', 'payload_seq', 'dispatch_seq']);
});

it('keeps a body, a value and a secret out of the generation and the event data', function () {
    $g = sgGroup();
    sgFetcher([$g['pe'] => '{"secret":"CANARY-sg-4471"}', $g['ce'] => '{"secret":"CANARY-sg-4472"}']);
    sgDispatch($g, 1);

    $dump = json_encode([sgGenerations(), sgEvents(), Cluster::rows(Cluster::superuser(), 'select * from sync_runs')]);

    expect($dump)->not->toContain('CANARY-sg');
});

// ---- The sweep ------------------------------------------------------------------------------------------------------------------------

it('re-emits the event for a target whose payload_seq is ahead of what consumers applied, once per tick', function () {
    $g = sgGroup();
    sgFetcher([$g['pe'] => '{"v":1}', $g['ce'] => '{"v":1}']);
    sgDispatch($g, 1);
    $emitted = count(sgEvents());

    // The events are fresh: the relay and the consumers get time before a re-emit.
    expect(app(ReemitPayloadEvents::class)->run())->toBe(0);

    Cluster::superuser()->exec("update outbox_events set occurred_at = now() - interval '1 hour'");
    // The comparison's event was applied by a consumer; the primary's was not.
    $applied = Cluster::rows(Cluster::superuser(), "select id, subject_seq from outbox_events where subject = 'sync_target:{$g['comparison']}'")[0];
    Cluster::superuser()->prepare("insert into outbox_consumptions (id, workspace_id, consumer, event_id, subject, subject_seq, applied, consumed_at) values (?, ?, 'results', ?, ?, ?, true, now())")
        ->execute([(string) Str::uuid7(), $g['workspace'], $applied['id'], 'sync_target:'.$g['comparison'], $applied['subject_seq']]);

    expect(app(ReemitPayloadEvents::class)->run())->toBe(1);

    $events = sgEvents();
    expect($events)->toHaveCount($emitted + 1);
    $again = collect($events)->first(fn (array $e): bool => $e['subject'] === 'sync_target:'.$g['primary'] && $e['subject_seq'] === 2);
    expect(json_decode($again['data'], true))->toMatchArray([
        'sync_target_id' => $g['primary'], 'payload_seq' => 1, 'reemitted' => true, 'sync_group_id' => $g['primary'], 'generation_complete' => true,
    ]);

    // Once per tick: it is fresh again, so the next run leaves it.
    expect(app(ReemitPayloadEvents::class)->run())->toBe(0);
});

it('does not re-emit for a target a consumer has caught up with, one that never stored a payload, or a retired one', function () {
    $workspace = Cluster::workspace('Acme');
    $none = Cluster::seedSyncTarget($workspace);
    $retired = Cluster::seedSyncTarget($workspace, ['retired_at' => 'now', 'payload_seq' => 1, 'current_payload_id' => (string) Str::uuid7()]);

    expect(app(ReemitPayloadEvents::class)->run())->toBe(0)
        ->and(sgEvents())->toBe([])
        ->and($none)->not->toBe($retired);
});

// ---- Roles and tenancy ----------------------------------------------------------------------------------------------------------------

it('isolates generations by Workspace and keeps them immutable for the application role', function () {
    $g = sgGroup();
    sgFetcher([$g['pe'] => '{"v":1}', $g['ce'] => '{"v":1}']);
    sgDispatch($g, 1);

    $other = Cluster::workspace('Other');
    expect(app(SyncGenerations::class)->latestComplete($other, $g['primary']))->toBeNull()
        ->and(app(SyncGenerations::class)->comparisonUnavailable($other, $g['primary']))->toBeFalse();

    foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
        expect(Cluster::rows(Cluster::superuser(), 'select has_table_privilege(?, ?, ?) as p', ['app', 'sync_generations', $privilege])[0]['p'])->toBeFalse("app {$privilege}");
    }

    foreach (['SELECT', 'INSERT'] as $privilege) {
        expect(Cluster::rows(Cluster::superuser(), 'select has_table_privilege(?, ?, ?) as p', ['app', 'sync_generations', $privilege])[0]['p'])->toBeTrue("app {$privilege}");
    }
});

it('gives role system the group link column and only the rows the dispatcher needs, changing nothing new', function () {
    $g = sgGroup();
    $system = Cluster::system();

    expect(Cluster::rows($system, 'select id, group_primary_target_id from sync_targets where group_primary_target_id is not null'))->toHaveCount(1)
        ->and(fn () => $system->query('select current_payload_id from sync_targets'))->toThrow(PDOException::class, 'permission denied')
        ->and(fn () => $system->query('select * from sync_generations'))->toThrow(PDOException::class, 'permission denied')
        ->and(fn () => $system->exec("update sync_targets set group_primary_target_id = null where id = '{$g['comparison']}'"))->toThrow(PDOException::class, 'permission denied');

    Cluster::superuser()->exec("update sync_targets set retired_at = now() where id = '{$g['comparison']}'");
    expect(Cluster::rows($system, 'select id from sync_targets where group_primary_target_id is not null'))->toBe([]);
});

it('refuses a target that is its own primary, a second comparison for one primary, and an applied_seq past dispatch_seq for a target alone', function () {
    $g = sgGroup();
    $pdo = Cluster::superuser();

    expect(fn () => $pdo->exec("update sync_targets set group_primary_target_id = id where id = '{$g['primary']}'"))->toThrow(PDOException::class)
        ->and(fn () => Cluster::seedSyncTarget($g['workspace'], ['sync_group_id' => $g['primary'], 'group_primary_target_id' => $g['primary']]))->toThrow(PDOException::class)
        ->and(fn () => $pdo->exec("update sync_targets set applied_seq = 9 where id = '{$g['primary']}'"))->toThrow(PDOException::class)
        ->and($pdo->exec("update sync_targets set applied_seq = 9 where id = '{$g['comparison']}'"))->toBe(1);
});

it('unlinks a comparison when its primary is retired, and lets a primary take a new comparison after its old one was retired', function () {
    [$workspace, $source] = sgSetup();
    $endpoint = sgEndpoint($source, '/a');
    $primary = sgSubscribe($workspace, $source, $endpoint, 'primary');
    $comparison = sgSubscribe($workspace, $source, sgEndpoint($source, '/b'), 'comparison', $primary->syncTargetId);
    app(OutboxRelay::class)->relay();

    // A retired comparison does not block the primary.
    Cluster::superuser()->exec("update sync_targets set retired_at = now(), next_due_at = null where id = '{$comparison->syncTargetId}'");
    $again = sgSubscribe($workspace, $source, sgEndpoint($source, '/c'), 'comparison', $primary->syncTargetId);
    expect($again->ok())->toBeTrue();

    // Revising the primary's Endpoint retires its target; the comparison becomes a target of its own.
    test()->putJson("/api/v1/admin/data-sources/{$source}/endpoints/{$endpoint}", [
        'method' => 'GET', 'path' => '/a2', 'headers' => [], 'body_template' => null, 'read_only_query' => false, 'confirm_read_only' => false,
        'params' => [['name' => 'region', 'binding' => 'fixed', 'value' => 'apac']], 'revision' => 1,
    ], SG_HEADERS)->assertOk();
    app(OutboxRelay::class)->relay();

    expect(sgTarget($primary->syncTargetId)['retired_at'])->not->toBeNull()
        ->and(sgTarget($again->syncTargetId))->toMatchArray(['group_primary_target_id' => null, 'sync_group_id' => $again->syncTargetId, 'retired_at' => null]);
});
