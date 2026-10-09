<?php

use App\Models\User;
use App\Modules\Connector\Contracts\Admission;
use App\Modules\Connector\Contracts\CallOutcome;
use App\Modules\Connector\Contracts\ConnectionTestCode;
use App\Modules\Connector\Contracts\EgressTransportFailed;
use App\Modules\Connector\Contracts\EndpointFetcher;
use App\Modules\Connector\Contracts\EndpointFetchResult;
use App\Modules\Connector\Contracts\EndpointFetchSpec;
use App\Modules\Connector\Contracts\HostResolver;
use App\Modules\Connector\Contracts\Jitter;
use App\Modules\Connector\Contracts\ResponseLimitExceeded;
use App\Modules\Connector\Contracts\SourceGovernor;
use App\Modules\Connector\Infrastructure\CurlClient;
use App\Modules\Connector\Infrastructure\CurlResult;
use App\Modules\Connector\Infrastructure\ValkeySourceGovernor;
use App\Modules\Ingestion\Application\DispatchDueSyncs;
use App\Modules\Ingestion\Application\FetchJob;
use App\Modules\Ingestion\Application\FetchSyncTarget;
use App\Modules\Ingestion\Application\RegisterSyncTargets;
use App\Modules\Ingestion\Contracts\FetchKeyInput;
use App\Modules\Ingestion\Contracts\FetchKeyResolver;
use App\Modules\Ingestion\Contracts\SyncStatuses;
use App\Modules\Ingestion\Infrastructure\SyncSettings;
use App\Modules\RawStore\Contracts\RawPayload;
use App\Modules\RawStore\Contracts\RawStore;
use App\Platform\Outbox\OutboxEnvelope;
use App\Platform\Outbox\OutboxRelay;
use App\Platform\Tenancy\WorkspaceMismatchException;
use App\Platform\Tenancy\WorkspaceTransaction;
use App\Support\Observability\MetricEmitter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;
use Tests\Unit\Support\FakeCurl;
use Tests\Unit\Support\FakeGovernor;
use Tests\Unit\Support\FakeResolver;
use Tests\Unit\Support\FixedJitter;

// Story 2.14 against the real PostgreSQL: a saved Endpoint revision becomes one sync target (through the outbox), the dispatcher
// (role `system`) fences each run with `dispatch_seq`, the `fetch-scheduled` job keeps the last good response as exact bytes plus an
// immutable observation, and a failed or late run changes nothing it must not. The queue is `sync`; the curl handler and the resolver are fakes.
const SD_HEADERS = ['Referer' => 'http://localhost:8000'];
const SD_CANARY = 'CANARY-sd-91c3e7';
const SD_BODY = '{"total":12345678901234567890.12,"rate":1.10}';
// The canonical form (Story 2.15): sorted keys, no whitespace, every number as received. `content_hash` is its sha256, not the bytes'.
const SD_CANONICAL = '{"rate":1.10,"total":12345678901234567890.12}';

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();

    $this->logFile = tempnam(sys_get_temp_dir(), 'dashflow-log');
    config(['logging.default' => 'single', 'logging.channels.single.path' => $this->logFile, 'dashflow.tunables.sync.refresh_intervals.value' => '900,300']);

    $this->curl = new FakeCurl;
    app()->instance(HostResolver::class, new FakeResolver(['api.example.com' => ['93.184.216.34']]));
    app()->instance(CurlClient::class, $this->curl);
});

afterEach(fn () => @unlink($this->logFile));

/** @return array{0: string, 1: string, 2: string} the Workspace, a Data Source on the allowlisted api.example.com, and the signed-in Admin's membership */
function sdSetup(): array
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

    return [$workspace, Cluster::seedDataSource($workspace, 'Sales API'), $membership];
}

/** @param  array<string, mixed>  $overrides */
function sdBody(array $overrides = []): array
{
    return $overrides + [
        'method' => 'GET', 'path' => '/revenue',
        'params' => [['name' => 'region', 'binding' => 'fixed', 'value' => 'emea']],
        'headers' => [], 'body_template' => null, 'read_only_query' => false, 'confirm_read_only' => false,
    ];
}

function sdCreate(string $source, array $overrides = []): string
{
    return (string) test()->postJson("/api/v1/admin/data-sources/{$source}/endpoints", sdBody($overrides), SD_HEADERS)->assertCreated()->json('data.endpoint_id');
}

function sdRevise(string $source, string $endpoint, int $revision, array $overrides = [])
{
    return test()->putJson("/api/v1/admin/data-sources/{$source}/endpoints/{$endpoint}", sdBody($overrides) + ['revision' => $revision], SD_HEADERS);
}

function sdRelay(): int
{
    return app(OutboxRelay::class)->relay();
}

/** @return list<array<string, mixed>> */
function sdTargets(string $where = 'true'): array
{
    return Cluster::rows(Cluster::superuser(), "select * from sync_targets where {$where} order by created_at, id");
}

/** The one unretired target, after the relay has delivered. */
function sdTarget(): array
{
    $targets = sdTargets('retired_at is null');
    expect($targets)->toHaveCount(1);

    return $targets[0];
}

/** Raises the fence the way the dispatcher does, so a job for `$seq` is a legitimate dispatch. */
function sdDispatched(string $target, int $seq): void
{
    Cluster::superuser()->prepare('update sync_targets set dispatch_seq = greatest(dispatch_seq, ?) where id = ?')->execute([$seq, $target]);
}

function sdRun(string $workspace, string $target, int $seq): void
{
    sdDispatched($target, $seq);
    dispatch(new FetchJob($workspace, $target, $seq));
}

/** @return list<array<string, mixed>> */
function sdRuns(): array
{
    return Cluster::rows(Cluster::superuser(), "select * from sync_runs where kind = 'scheduled_fetch' order by started_at, id");
}

function sdCount(string $table): int
{
    return (int) Cluster::rows(Cluster::superuser(), "select count(*) as n from {$table}")[0]['n'];
}

/** A registered, due target for a plain Endpoint, ready for a run. @return array{0: string, 1: string, 2: string, 3: string} workspace, source, endpoint, target */
function sdReady(array $overrides = []): array
{
    [$workspace, $source] = sdSetup();
    $endpoint = sdCreate($source, $overrides);
    sdRelay();

    return [$workspace, $source, $endpoint, sdTarget()['id']];
}

it('registers one sync target for a shared Endpoint revision, keyed by the FetchKeyResolver, due at once, and only after the outbox delivers', function () {
    [$workspace, $source] = sdSetup();
    $endpoint = sdCreate($source, [
        'params' => [['name' => 'region', 'binding' => 'fixed', 'value' => 'emea'], ['name' => 'from', 'binding' => 'date_range_from']],
        'headers' => [['name' => 'X-Day', 'binding' => 'period_start'], ['name' => 'X-Team', 'binding' => 'fixed', 'value' => 'finance']],
        'test_values' => ['from' => '2026-10-01', 'header:X-Day' => '2026-10-02'],
    ]);

    // The save wrote the event, not the target: Connector cannot call Ingestion.
    expect(sdTargets())->toBe([]);
    $event = Cluster::rows(Cluster::superuser(), "select * from outbox_events where type = 'connector.endpoint.created'")[0];
    expect($event['subject'])->toBe('endpoint:'.$endpoint)
        ->and(json_decode($event['data'], true))->toEqual(['data_source_id' => $source, 'endpoint_id' => $endpoint, 'endpoint_revision_id' => Cluster::rows(Cluster::superuser(), 'select id from endpoint_revisions')[0]['id'], 'revision' => 1])
        ->and($event['data'])->not->toContain('revenue')->not->toContain('emea')->not->toContain('2026');

    sdRelay();
    sdRelay();

    $target = sdTarget();
    $revision = Cluster::rows(Cluster::superuser(), 'select id from endpoint_revisions')[0]['id'];
    $typed = [
        'region' => ['t' => 'string', 'v' => 'emea'], 'from' => ['t' => 'date', 'v' => '2026-10-01'],
        'header:X-Day' => ['t' => 'date', 'v' => '2026-10-02'], 'header:X-Team' => ['t' => 'string', 'v' => 'finance'],
    ];
    $key = app(FetchKeyResolver::class)->resolve(new FetchKeyInput($workspace, $revision, 1, $typed))->key;

    expect($target)->toMatchArray([
        'workspace_id' => $workspace, 'fetch_key' => $key, 'data_source_id' => $source, 'endpoint_id' => $endpoint, 'endpoint_revision_id' => $revision,
        'data_source_revision' => 1, 'sync_group_id' => $target['id'], 'refresh_interval_seconds' => 300, 'etag' => null, 'last_modified' => null,
        'content_hash' => null, 'current_payload_id' => null, 'payload_seq' => 0, 'dispatch_seq' => 0, 'applied_seq' => 0, 'last_success_at' => null,
        'last_checked_at' => null, 'consecutive_failures' => 0, 'retired_at' => null,
    ])
        ->and($key)->toMatch('/^fk1:[0-9a-f]{64}$/')
        ->and(substr($target['id'], 14, 1))->toBe('7')
        ->and(json_decode($target['params'], true))->toEqual($typed)
        ->and(strtotime($target['next_due_at']))->toBeLessThanOrEqual(time() + 1);

    // A second delivery of the same save, and a re-run of the consumer, add nothing.
    expect(sdCount('sync_targets'))->toBe(1);
});

it('makes a new target for a revised Endpoint and retires the old one, keeping its payloads', function () {
    [$workspace, $source, $endpoint, $first] = sdReady();
    $body = Cluster::seedRawBody($workspace, $first);
    Cluster::superuser()->prepare('update sync_targets set current_payload_id = ?, payload_seq = 1, last_success_at = now() where id = ?')->execute([$body, $first]);

    sdRevise($source, $endpoint, 1, ['path' => '/revenue-v2'])->assertOk();
    sdRelay();

    $targets = sdTargets();
    expect($targets)->toHaveCount(2)
        ->and($targets[0]['id'])->toBe($first)
        ->and($targets[0]['retired_at'])->not->toBeNull()
        ->and($targets[0]['next_due_at'])->toBeNull()
        ->and($targets[0]['current_payload_id'])->toBe($body)
        ->and($targets[0]['payload_seq'])->toBe(1)
        ->and($targets[1]['retired_at'])->toBeNull()
        ->and($targets[1]['next_due_at'])->not->toBeNull()
        ->and($targets[1]['fetch_key'])->not->toBe($targets[0]['fetch_key'])
        ->and(sdCount('raw_bodies'))->toBe(1);

    // The old target is never dispatched again.
    Queue::fake();
    expect(app(DispatchDueSyncs::class)->run())->toBe(1);
    Queue::assertPushed(FetchJob::class, fn (FetchJob $job): bool => $job->syncGroupId === $targets[1]['id']);
});

it('makes new targets for every Endpoint when the Data Source is updated, which is part of the key', function () {
    [$workspace, $source] = sdSetup();
    $one = sdCreate($source);
    $two = sdCreate($source, ['path' => '/costs']);
    sdRelay();
    expect(sdTargets())->toHaveCount(2);

    test()->putJson("/api/v1/admin/data-sources/{$source}", [
        'name' => 'Sales API', 'base_url' => 'https://api.example.com/v1', 'headers' => [], 'timeout_seconds' => null, 'max_response_bytes' => null,
        'max_pages' => null, 'live_capable' => false, 'auth_type' => 'none', 'revision' => 1,
    ], SD_HEADERS)->assertOk();

    $event = Cluster::rows(Cluster::superuser(), "select * from outbox_events where type = 'connector.data_source.updated'")[0];
    expect(json_decode($event['data'], true))->toEqual(['data_source_id' => $source, 'revision' => 2])->and($event['subject'])->toBe('data_source:'.$source);

    sdRelay();

    $current = sdTargets('retired_at is null');
    expect($current)->toHaveCount(2)
        ->and(array_column($current, 'data_source_revision'))->each->toBe(2)
        ->and(array_column($current, 'endpoint_id'))->toEqualCanonicalizing([$one, $two])
        ->and(sdTargets('retired_at is not null'))->toHaveCount(2);
});

it('registers no key and no target while a date-bound row has no test value, and says why', function () {
    [$workspace, $source] = sdSetup();
    $endpoint = sdCreate($source, ['params' => [['name' => 'from', 'binding' => 'date_range_from'], ['name' => 'to', 'binding' => 'date_range_to']], 'test_values' => ['to' => '2026-10-31']]);
    sdRelay();

    expect(sdTargets())->toBe([]);

    $data = test()->getJson("/api/v1/admin/data-sources/{$source}/endpoints", SD_HEADERS)->assertOk()->json('data.0');
    expect($data['sync'])->toBe(['state' => 'not_scheduled', 'last_success_at' => null, 'last_checked_at' => null, 'payload_changed_at' => null, 'reason' => 'test_values', 'missing_test_values' => ['from']])
        ->and($data['test_values'])->toBe(['to' => '2026-10-31']);

    // Saving the missing value makes the target.
    sdRevise($source, $endpoint, 1, ['params' => [['name' => 'from', 'binding' => 'date_range_from'], ['name' => 'to', 'binding' => 'date_range_to']], 'test_values' => ['from' => '2026-10-01', 'to' => '2026-10-31']])->assertOk();
    sdRelay();

    expect(sdTargets())->toHaveCount(1)
        ->and(test()->getJson("/api/v1/admin/data-sources/{$source}/endpoints/{$endpoint}", SD_HEADERS)->json('data.sync'))->toBe(['state' => 'waiting', 'last_success_at' => null, 'last_checked_at' => null, 'payload_changed_at' => null, 'reason' => null, 'missing_test_values' => []]);
});

it('registers no representative target for an Endpoint that needs user context, and retires the one it had', function () {
    [$workspace, $source] = sdSetup();
    $endpoint = sdCreate($source);
    sdRelay();
    expect(sdTargets('retired_at is null'))->toHaveCount(1);

    sdRevise($source, $endpoint, 1, ['params' => [['name' => 'me', 'binding' => 'user_id']]])->assertOk();
    sdRelay();

    expect(sdTargets('retired_at is null'))->toBe([])
        ->and(sdTargets())->toHaveCount(1)
        ->and(test()->getJson("/api/v1/admin/data-sources/{$source}/endpoints/{$endpoint}", SD_HEADERS)->json('data.sync'))->toBe(['state' => 'not_scheduled', 'last_success_at' => null, 'last_checked_at' => null, 'payload_changed_at' => null, 'reason' => 'user_context', 'missing_test_values' => []]);
});

it('takes test values only for date-range and period rows, checked as a date, and never for a user-bound name', function () {
    [, $source] = sdSetup();
    $rows = [['name' => 'from', 'binding' => 'date_range_from'], ['name' => 'region', 'binding' => 'fixed', 'value' => 'emea'], ['name' => 'me', 'binding' => 'user_id']];
    $post = fn (array $values) => test()->postJson("/api/v1/admin/data-sources/{$source}/endpoints", sdBody(['params' => $rows, 'test_values' => $values]), SD_HEADERS);

    expect($post(['me' => '2026-10-01'])->assertStatus(422)->json('reasons'))->toBe(['test_values.me' => 'value-not-accepted'])
        ->and($post(['region' => '2026-10-01'])->assertStatus(422)->json('reasons'))->toBe(['test_values.region' => 'test-value-not-accepted'])
        ->and($post(['unknown' => '2026-10-01'])->assertStatus(422)->json('reasons'))->toBe(['test_values.unknown' => 'test-value-not-accepted'])
        ->and($post(['from' => '2026-02-30'])->assertStatus(422)->json('reasons'))->toBe(['test_values.from' => 'param-date-invalid'])
        ->and($post(['from' => '01/10/2026'])->assertStatus(422)->json('reasons'))->toBe(['test_values.from' => 'param-date-invalid'])
        ->and($post(['from' => 20261001])->assertStatus(422)->json('reasons'))->toBe(['test_values.from' => 'param-date-invalid']);
    expect(sdCount('endpoints'))->toBe(0);

    // A fixed parameter keeps its stored value, and an empty value is no value.
    $ok = $post(['from' => '2026-10-01', 'region' => ''])->assertCreated();
    expect($ok->json('data.test_values'))->toBe(['from' => '2026-10-01'])
        ->and($ok->json('data.params.1.value'))->toBe('emea');
});

it('registers the target but never schedules it while no refresh interval is set, or it is malformed', function (mixed $setting) {
    config(['dashflow.tunables.sync.refresh_intervals.value' => $setting]);
    [$workspace, $source, $endpoint, $target] = sdReady();

    $row = sdTargets()[0];
    expect($row['refresh_interval_seconds'])->toBeNull()->and($row['next_due_at'])->toBeNull()->and($row['retired_at'])->toBeNull();

    Queue::fake();
    expect(app(DispatchDueSyncs::class)->run())->toBe(0);
    Queue::assertNothingPushed();
    expect(app(SyncStatuses::class)->forEndpoints($workspace, [$endpoint])[$endpoint]->reason)->toBe('no_interval')
        ->and(test()->getJson("/api/v1/admin/data-sources/{$source}/endpoints", SD_HEADERS)->json('data.0.sync.reason'))->toBe('no_interval');
})->with(['unset' => [null], 'empty' => [''], 'malformed' => ['300,soon'], 'zero' => ['0'], 'negative' => ['-5']]);

it('uses the smallest of the refresh intervals and advances next_due_at by it on each dispatch', function () {
    [$workspace, , , $target] = sdReady();

    Queue::fake();
    expect(app(DispatchDueSyncs::class)->run())->toBe(1);

    $row = sdTargets()[0];
    expect($row['dispatch_seq'])->toBe(1)
        ->and(strtotime($row['next_due_at']) - time())->toBeGreaterThan(290)->toBeLessThanOrEqual(301);

    Queue::assertPushed(FetchJob::class, 1);
    Queue::assertPushedOn('fetch-scheduled', FetchJob::class, fn (FetchJob $job): bool => $job->workspaceId === $workspace && $job->syncGroupId === $target && $job->dispatchSeq === 1);

    // Not due again until its time: the next tick queues nothing and changes nothing.
    expect(app(DispatchDueSyncs::class)->run())->toBe(0)
        ->and(sdTargets()[0]['dispatch_seq'])->toBe(1);
    Queue::assertPushed(FetchJob::class, 1);
});

it('dispatches a due target once even when two dispatchers tick together, and skips a row another dispatcher holds', function () {
    [, , , $target] = sdReady();
    Queue::fake();

    $other = Cluster::system();
    $other->beginTransaction();
    expect($other->query('select id from sync_targets where next_due_at is not null for update skip locked')->fetchAll())->toHaveCount(1);

    expect(app(DispatchDueSyncs::class)->run())->toBe(0)->and(sdTargets()[0]['dispatch_seq'])->toBe(0);
    $other->rollBack();

    expect(app(DispatchDueSyncs::class)->run())->toBe(1)->and(sdTargets()[0]['dispatch_seq'])->toBe(1);
    Queue::assertPushed(FetchJob::class, 1);
});

it('re-dispatches a target whose job was lost at its next due time, and that run fetches', function () {
    [$workspace, , , $target] = sdReady();
    $this->curl->queue = [FakeCurl::answer(200, SD_BODY)];

    // Tick 1: the job is queued and never runs (the queue lost it).
    Queue::fake();
    expect(app(DispatchDueSyncs::class)->run())->toBe(1);
    Queue::assertPushed(FetchJob::class, 1);
    expect(sdTargets()[0])->toMatchArray(['dispatch_seq' => 1, 'applied_seq' => 0, 'payload_seq' => 0]);

    // Its time comes again: the target is re-derived from next_due_at and dispatched with the next number, on the real (sync) queue.
    app()->forgetInstance('queue');
    Facade::clearResolvedInstance('queue');
    Cluster::superuser()->prepare("update sync_targets set next_due_at = now() - interval '1 second' where id = ?")->execute([$target]);

    expect(app(DispatchDueSyncs::class)->run())->toBe(1);

    expect(sdTargets()[0])->toMatchArray(['dispatch_seq' => 2, 'applied_seq' => 2, 'payload_seq' => 1])
        ->and(sdRuns())->toHaveCount(1)
        ->and(sdRuns()[0])->toMatchArray(['status' => 'succeeded', 'dispatch_seq' => 2, 'sync_target_id' => $target]);
});

it('keeps the exact bytes, an immutable observation, the pointer and the event of a good response, and shows Last success', function () {
    [$workspace, $source, $endpoint, $target] = sdReady(['params' => [['name' => 'region', 'binding' => 'fixed', 'value' => 'emea']], 'headers' => [['name' => 'X-Team', 'binding' => 'fixed', 'value' => 'finance']]]);
    $this->curl->queue = [FakeCurl::answer(200, SD_BODY)];

    expect(app(SyncStatuses::class)->forEndpoints($workspace, [$endpoint])[$endpoint]->state)->toBe('waiting');

    sdRun($workspace, $target, 1);

    $row = sdTargets()[0];
    $bodies = Cluster::rows(Cluster::superuser(), "select id, workspace_id, sync_target_id, content_hash, size_bytes, encode(body, 'hex') as hex from raw_bodies");
    $observations = Cluster::rows(Cluster::superuser(), 'select * from raw_observations');

    expect($this->curl->calls)->toHaveCount(1)
        ->and($this->curl->calls[0][CURLOPT_URL])->toBe('https://api.example.com/revenue?region=emea')
        ->and($bodies)->toHaveCount(1)
        // The bytes are the response's, character for character: no number was rewritten.
        ->and(hex2bin($bodies[0]['hex']))->toBe(SD_BODY)
        ->and($bodies[0])->toMatchArray(['workspace_id' => $workspace, 'sync_target_id' => $target, 'content_hash' => hash('sha256', SD_BODY), 'size_bytes' => strlen(SD_BODY)])
        ->and($observations)->toHaveCount(1)
        ->and($observations[0])->toMatchArray(['workspace_id' => $workspace, 'sync_target_id' => $target, 'payload_id' => $bodies[0]['id'], 'seq' => 1, 'dispatch_seq' => 1, 'content_hash' => hash('sha256', SD_BODY)])
        ->and($row)->toMatchArray(['current_payload_id' => $bodies[0]['id'], 'content_hash' => hash('sha256', SD_CANONICAL), 'payload_seq' => 1, 'applied_seq' => 1, 'dispatch_seq' => 1, 'consecutive_failures' => 0])
        ->and($row['last_success_at'])->not->toBeNull()->and($row['last_checked_at'])->not->toBeNull()
        ->and($row['payload_changed_at'])->toBe($row['last_success_at']);

    // RawStore reads the same bytes back for the target, and for no other.
    $read = app(WorkspaceTransaction::class)->run($workspace, fn () => [
        app(RawStore::class)->get($workspace, $target, $bodies[0]['id']),
        app(RawStore::class)->get($workspace, (string) Str::uuid7(), $bodies[0]['id']),
        app(RawStore::class)->get($workspace, $target, (string) Str::uuid7()),
    ]);
    expect($read)->toBe([SD_BODY, null, null]);

    // The event carries IDs and sequence numbers, never a byte of the body.
    $events = Cluster::rows(Cluster::superuser(), "select * from outbox_events where type = 'ingestion.payload.changed'");
    expect($events)->toHaveCount(1)
        ->and($events[0]['subject'])->toBe('sync_target:'.$target)
        ->and(json_decode($events[0]['data'], true))->toEqual(['sync_target_id' => $target, 'endpoint_id' => $endpoint, 'payload_id' => $bodies[0]['id'], 'payload_seq' => 1, 'dispatch_seq' => 1])
        ->and($events[0]['data'])->not->toContain('12345678901234567890');

    // One succeeded run: the sanitised template and the parameter names, no query string and no value.
    $runs = sdRuns();
    expect($runs)->toHaveCount(1)
        ->and($runs[0])->toMatchArray([
            'kind' => 'scheduled_fetch', 'status' => 'succeeded', 'http_status' => 200, 'error_code' => null, 'bytes' => strlen(SD_BODY), 'data_source_id' => $source,
            'url_template' => 'https://api.example.com/revenue', 'sync_target_id' => $target, 'dispatch_seq' => 1, 'workspace_id' => $workspace,
        ])
        ->and(json_decode($runs[0]['parameter_names'], true))->toBe(['region', 'header:X-Team'])
        ->and($runs[0]['request_id'])->toBeString()
        ->and(json_encode($runs[0]))->not->toContain('emea')->not->toContain('finance')->not->toContain('?');

    $status = app(SyncStatuses::class)->forEndpoints($workspace, [$endpoint])[$endpoint];
    $listed = test()->getJson("/api/v1/admin/data-sources/{$source}/endpoints", SD_HEADERS)->json('data.0.sync');
    expect($status->state)->toBe('succeeded')
        ->and($listed)->toBe(['state' => 'succeeded', 'last_success_at' => $status->lastSuccessAt, 'last_checked_at' => $status->lastCheckedAt, 'payload_changed_at' => $status->payloadChangedAt, 'reason' => null, 'missing_test_values' => []])
        ->and($status->lastCheckedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($status->payloadChangedAt)->toBe($status->lastSuccessAt)
        ->and($status->lastSuccessAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

/** What the source answers when it is down, by kind. */
function sdDown(string $kind): CurlResult|Throwable
{
    return match ($kind) {
        '500' => FakeCurl::answer(500, '{"error":"down"}'),
        'html' => FakeCurl::answer(200, '<html>maintenance</html>', ['content-type' => ['text/html']]),
        'truncated' => FakeCurl::answer(200, '{"total":'),
        'timeout' => new EgressTransportFailed('timeout', 28),
        'refused' => FakeCurl::answer(401, '{}'),
    };
}

it('keeps the last good payload when the source fails, records every failed run and counts the failures', function (string $kind, string $code) {
    [$workspace, , , $target] = sdReady();
    $this->curl->queue = [FakeCurl::answer(200, SD_BODY), sdDown($kind)];

    sdRun($workspace, $target, 1);
    $good = sdTargets()[0];

    sdRun($workspace, $target, 2);
    $row = sdTargets()[0];

    expect($row)->toMatchArray([
        'current_payload_id' => $good['current_payload_id'], 'content_hash' => $good['content_hash'], 'payload_seq' => 1, 'applied_seq' => 2,
        'consecutive_failures' => 1, 'last_success_at' => $good['last_success_at'],
    ])
        ->and($row['last_checked_at'])->not->toBeNull()
        ->and(sdCount('raw_bodies'))->toBe(1)
        ->and(sdCount('raw_observations'))->toBe(1)
        ->and(sdCount('outbox_events where type = \'ingestion.payload.changed\''))->toBe(1);

    $runs = sdRuns();
    expect($runs)->toHaveCount(2)
        ->and($runs[1])->toMatchArray(['status' => 'failed', 'error_code' => $code, 'dispatch_seq' => 2, 'url_template' => 'https://api.example.com/revenue']);

    // A later failure is counted; the next good response resets the count and moves the pointer.
    $this->curl->queue = [sdDown($kind), FakeCurl::answer(200, '{"total":2}')];
    sdRun($workspace, $target, 3);
    expect(sdTargets()[0])->toMatchArray(['consecutive_failures' => 2, 'current_payload_id' => $good['current_payload_id']]);

    sdRun($workspace, $target, 4);
    $recovered = sdTargets()[0];
    expect($recovered)->toMatchArray(['consecutive_failures' => 0, 'payload_seq' => 2, 'applied_seq' => 4])
        ->and($recovered['current_payload_id'])->not->toBe($good['current_payload_id'])
        ->and(sdCount('raw_bodies'))->toBe(2);
})->with([
    'a 500' => ['500', 'fetch-failed'],
    'a body that is not JSON' => ['html', 'not-json'],
    'JSON that does not parse' => ['truncated', 'not-json'],
    'a timeout' => ['timeout', 'fetch-failed'],
    'a refused login' => ['refused', 'config-error'],
]);

it('treats a response over the size limit as a failed run with nothing stored or truncated', function () {
    [$workspace, , , $target] = sdReady();
    Cluster::superuser()->prepare('update data_sources set max_response_bytes = 20')->execute();
    $this->curl->queue = [new ResponseLimitExceeded(100, 20, 200)];

    sdRun($workspace, $target, 1);

    $runs = sdRuns();
    expect(sdCount('raw_bodies'))->toBe(0)->and(sdCount('raw_observations'))->toBe(0)
        ->and(sdTargets()[0])->toMatchArray(['current_payload_id' => null, 'payload_seq' => 0, 'applied_seq' => 1, 'consecutive_failures' => 1])
        ->and($runs)->toHaveCount(1)
        ->and($runs[0]['status'])->toBe('failed');
});

it('supersedes a late or duplicate run without calling the source or changing anything', function () {
    [$workspace, , , $target] = sdReady();
    $this->curl->queue = [FakeCurl::answer(200, SD_BODY), FakeCurl::answer(200, '{"late":true}')];

    sdRun($workspace, $target, 5);
    $after = sdTargets()[0];
    expect($after['applied_seq'])->toBe(5);

    // Late: a lower number.
    sdRun($workspace, $target, 3);
    // Duplicate: the same number again.
    sdRun($workspace, $target, 5);

    expect(sdTargets()[0])->toBe($after)
        ->and($this->curl->calls)->toHaveCount(1)
        ->and(sdCount('raw_bodies'))->toBe(1)
        ->and(sdCount('raw_observations'))->toBe(1);

    $runs = sdRuns();
    expect(array_column($runs, 'status'))->toBe(['succeeded', 'superseded', 'superseded'])
        ->and(array_column($runs, 'dispatch_seq'))->toBe([5, 3, 5]);
});

it('supersedes a run that loses the race at commit: another run applied a newer dispatch while it was fetching', function () {
    [$workspace, , , $target] = sdReady();

    // The fetch of dispatch 1 is slow; while it runs, dispatch 2 completes on another connection and commits.
    app()->instance(EndpointFetcher::class, new class($target) implements EndpointFetcher
    {
        public function __construct(private readonly string $target) {}

        public function fetch(EndpointFetchSpec $spec): EndpointFetchResult
        {
            Cluster::superuser()->prepare('update sync_targets set dispatch_seq = 2, applied_seq = 2, payload_seq = 1, last_success_at = now() where id = ?')->execute([$this->target]);

            return new EndpointFetchResult(true, '{"slow":true}', 200, 1, 13, null, null, 'https://api.example.com/revenue', ['region'], dataSourceId: $spec->dataSourceId);
        }
    });

    sdRun($workspace, $target, 1);

    expect(sdTargets()[0])->toMatchArray(['applied_seq' => 2, 'payload_seq' => 1, 'current_payload_id' => null])
        ->and(sdCount('raw_bodies'))->toBe(0)
        ->and(sdCount('raw_observations'))->toBe(0)
        ->and(sdCount('outbox_events where type = \'ingestion.payload.changed\''))->toBe(0)
        ->and(array_column(sdRuns(), 'status'))->toBe(['superseded']);
});

it('commits exactly the runs that win the fence when several dispatches finish in any order on separate connections', function () {
    [$workspace, , , $target] = sdReady();
    sdDispatched($target, 5);

    $script = dirname(__DIR__).'/Database/Support/fetch.php';
    $processes = [];

    // Dispatches 1 to 5, started together, answering after pauses that put their commits out of order.
    foreach ([[1, 120], [2, 10], [3, 200], [4, 60], [5, 150]] as [$seq, $pause]) {
        $processes[$seq] = proc_open([PHP_BINARY, $script, $workspace, $target, (string) $seq, (string) $pause], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $processes[$seq] = [$processes[$seq], $pipes];
    }

    foreach ($processes as [$process, $pipes]) {
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($process);
    }

    $row = sdTargets()[0];
    $observations = Cluster::rows(Cluster::superuser(), 'select seq, dispatch_seq from raw_observations order by seq');
    $runs = sdRuns();
    $succeeded = array_values(array_filter($runs, fn (array $run): bool => $run['status'] === 'succeeded'));

    // Dispatch 5 is the newest, so it always wins; each run that lost was recorded superseded, and nothing was committed twice.
    expect($row['applied_seq'])->toBe(5)
        ->and($runs)->toHaveCount(5)
        ->and(array_count_values(array_column($runs, 'status')))->each->toBeInt()
        ->and(count($succeeded))->toBe($row['payload_seq'])
        ->and($row['payload_seq'])->toBeGreaterThanOrEqual(1)
        ->and(sdCount('raw_observations'))->toBe($row['payload_seq'])
        ->and(array_column($observations, 'seq'))->toBe(range(1, $row['payload_seq']))
        // The observations were applied in dispatch order, whatever order the answers arrived in.
        ->and(array_column($observations, 'dispatch_seq'))->toBe(array_values(collect(array_column($observations, 'dispatch_seq'))->sort()->all()))
        ->and(max(array_column($observations, 'dispatch_seq')))->toBe(5);

    $current = Cluster::rows(Cluster::superuser(), "select encode(body, 'hex') as hex from raw_bodies where id = ?", [$row['current_payload_id']])[0];
    expect(hex2bin($current['hex']))->toBe('{"k":5}');
});

it('fails hard with a security event and no request when the job holds an ID of another Workspace', function () {
    [$workspace, , , $target] = sdReady();
    $other = Cluster::workspace('Other');
    $foreign = Cluster::seedTenantRow('sync_targets', $other);

    expect(fn () => dispatch(new FetchJob($workspace, $foreign, 1)))->toThrow(WorkspaceMismatchException::class)
        ->and($this->curl->calls)->toBe([])
        ->and(sdRuns())->toBe([])
        ->and((string) file_get_contents($this->logFile))->toContain('security.tenancy.workspace_mismatch');

    expect((new FetchJob($workspace, $target, 4))->referencedIds())->toBe(['sync_targets' => [$target]]);
});

it('records a revision that moved after the target was registered as a superseded run, with nothing sent', function () {
    [$workspace, $source, $endpoint, $target] = sdReady();

    // Revised, and the relay has not yet retired the old target.
    sdRevise($source, $endpoint, 1, ['path' => '/revenue-v2'])->assertOk();
    sdRun($workspace, $target, 1);

    $runs = sdRuns();
    expect($this->curl->calls)->toBe([])
        ->and($runs)->toHaveCount(1)
        ->and($runs[0])->toMatchArray(['status' => 'superseded', 'error_code' => 'revision_moved'])
        ->and(sdTargets()[0])->toMatchArray(['applied_seq' => 0, 'payload_seq' => 0, 'consecutive_failures' => 0]);
});

it('sends a read-only POST once with the run ID as its Idempotency-Key', function () {
    [$workspace, , , $target] = sdReady([
        'method' => 'POST', 'path' => '/query', 'params' => [['name' => 'region', 'binding' => 'fixed', 'value' => 'emea']],
        'body_template' => '{"region":{"$param":"region"}}', 'read_only_query' => true, 'confirm_read_only' => true,
    ]);
    $this->curl->queue = [FakeCurl::answer(401, '{}'), FakeCurl::answer(200, SD_BODY)];

    sdRun($workspace, $target, 1);

    $runs = sdRuns();
    expect($this->curl->calls)->toHaveCount(1)
        ->and($this->curl->calls[0][CURLOPT_HTTPHEADER])->toContain('Idempotency-Key: '.$runs[0]['id'])
        ->and($runs[0]['status'])->toBe('failed');
});

it('keeps every value, header, secret and body out of sync_runs, the log, the audit and the outbox', function () {
    [$workspace, $source, $endpoint, $target] = sdReady([
        'params' => [['name' => 'token', 'binding' => 'fixed', 'value' => 'v-'.SD_CANARY]],
        'headers' => [['name' => 'X-Team', 'binding' => 'fixed', 'value' => 'h-'.SD_CANARY]],
    ]);
    $this->curl->queue = [FakeCurl::answer(200, '{"note":"b-'.SD_CANARY.'"}'), FakeCurl::answer(500, '{"error":"e-'.SD_CANARY.'"}'), FakeCurl::answer(200, 'not json '.SD_CANARY, ['content-type' => ['text/plain']])];

    sdRun($workspace, $target, 1);
    sdRun($workspace, $target, 2);
    sdRun($workspace, $target, 3);

    expect($this->curl->calls[0][CURLOPT_URL])->toContain('v-'.SD_CANARY)
        ->and($this->curl->calls[0][CURLOPT_HTTPHEADER])->toContain('X-Team: h-'.SD_CANARY);

    $everything = json_encode([
        Cluster::rows(Cluster::superuser(), 'select * from sync_runs'),
        Cluster::rows(Cluster::superuser(), 'select * from audit_events'),
        Cluster::rows(Cluster::superuser(), 'select * from outbox_events'),
        Cluster::rows(Cluster::superuser(), 'select * from operations'),
        (string) file_get_contents($this->logFile),
    ], JSON_THROW_ON_ERROR);

    expect($everything)->not->toContain(SD_CANARY);
    // Only the Admin's own configuration and the stored body hold them, by design.
    expect(json_encode(Cluster::rows(Cluster::superuser(), "select encode(body, 'hex') as hex from raw_bodies")))->toContain(bin2hex('b-'.SD_CANARY));
});

it('gives role system only the dispatch columns, only the rows that can be due, and only two columns to change', function () {
    [$workspace, , , $target] = sdReady();
    $other = Cluster::workspace('Other');
    $notScheduled = Cluster::seedSyncTarget($other);
    $retired = Cluster::seedSyncTarget($other, ['refresh_interval_seconds' => 60, 'next_due_at' => '2020-01-01', 'retired_at' => '2020-01-02']);
    $due = Cluster::seedSyncTarget($other, ['refresh_interval_seconds' => 60, 'next_due_at' => '2020-01-01']);
    $system = Cluster::system();

    // It sees the scheduled, unretired targets of every Workspace (row-level security is on for it too), and nothing else.
    $seen = array_column(Cluster::rows($system, 'select id from sync_targets'), 'id');
    expect($seen)->toEqualCanonicalizing([$target, $due])->not->toContain($notScheduled)->not->toContain($retired);

    foreach (['fetch_key', 'params', 'etag', 'current_payload_id', 'endpoint_id', 'last_success_at'] as $column) {
        expect(fn () => $system->query("select {$column} from sync_targets"))->toThrow(PDOException::class, 'permission denied');
    }

    expect(fn () => $system->exec('update sync_targets set retired_at = now()'))->toThrow(PDOException::class, 'permission denied')
        ->and(fn () => $system->exec('update sync_targets set applied_seq = 99'))->toThrow(PDOException::class, 'permission denied')
        ->and(fn () => $system->exec('update sync_targets set payload_seq = 99'))->toThrow(PDOException::class, 'permission denied')
        ->and(fn () => $system->exec('delete from sync_targets'))->toThrow(PDOException::class, 'permission denied')
        ->and(fn () => $system->exec("insert into sync_targets (id, workspace_id) values (gen_random_uuid(), '{$other}')"))->toThrow(PDOException::class, 'permission denied');

    // It moves only what is due: the row that is not due yet (the registered target's next_due_at is now, then moved forward) stays.
    expect($system->exec("update sync_targets set dispatch_seq = dispatch_seq + 1, next_due_at = now() + interval '1 hour' where id = '{$due}'"))->toBe(1)
        ->and($system->exec("update sync_targets set dispatch_seq = dispatch_seq + 1 where id = '{$due}'"))->toBe(0)
        ->and($system->exec("update sync_targets set dispatch_seq = dispatch_seq + 1 where id = '{$retired}'"))->toBe(0);

    foreach (['raw_bodies', 'raw_observations'] as $table) {
        expect(Cluster::rows(Cluster::superuser(), 'select has_table_privilege(?, ?, ?) as p', ['system', $table, 'SELECT'])[0]['p'])->toBeFalse()
            ->and(Cluster::rows(Cluster::superuser(), 'select has_any_column_privilege(?, ?, ?) as p', ['system', $table, 'SELECT'])[0]['p'])->toBeFalse();
    }

    expect(Cluster::rows(Cluster::superuser(), "select rolbypassrls from pg_roles where rolname = 'system'")[0]['rolbypassrls'])->toBeFalse();
});

it('keeps the raw tier insert-only for the application, immutable for everyone, and isolated by Workspace', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $bodyA = Cluster::seedRawBody($a);
    $observationA = Cluster::seedRawObservation($a);
    Cluster::seedRawBody($b);
    $app = Cluster::pooledApp();

    foreach (['raw_bodies', 'raw_observations'] as $table) {
        expect(Cluster::rows(Cluster::superuser(), 'select has_table_privilege(?, ?, ?) as p', ['maintenance', $table, 'DELETE'])[0]['p'])->toBeTrue("maintenance DELETE on {$table}");

        foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
            expect(Cluster::rows(Cluster::superuser(), 'select has_table_privilege(?, ?, ?) as p', ['app', $table, $privilege])[0]['p'])->toBeFalse("app {$privilege} on {$table}");
        }

        foreach (['SELECT', 'INSERT'] as $privilege) {
            expect(Cluster::rows(Cluster::superuser(), 'select has_table_privilege(?, ?, ?) as p', ['app', $table, $privilege])[0]['p'])->toBeTrue("app {$privilege} on {$table}");
        }
    }

    // Not even the owner or the superuser can change a stored body or an observation.
    expect(fn () => Cluster::superuser()->exec("update raw_bodies set size_bytes = 1 where id = '{$bodyA}'"))->toThrow(PDOException::class, 'immutable')
        ->and(fn () => Cluster::superuser()->exec("update raw_observations set seq = 9 where id = '{$observationA}'"))->toThrow(PDOException::class, 'immutable');

    // A body must be what its address says.
    expect(fn () => Cluster::superuser()->exec("insert into raw_bodies (id, workspace_id, sync_target_id, content_hash, size_bytes, body) values (gen_random_uuid(), '{$a}', gen_random_uuid(), '".str_repeat('a', 64)."', 2, decode('7b7d', 'hex'))"))
        ->toThrow(PDOException::class, 'violates check constraint')
        ->and(fn () => Cluster::superuser()->exec("insert into raw_bodies (id, workspace_id, sync_target_id, content_hash, size_bytes, body) values (gen_random_uuid(), '{$a}', gen_random_uuid(), '".hash('sha256', '{}')."', 5, decode('7b7d', 'hex'))"))
        ->toThrow(PDOException::class, 'violates check constraint');

    // The body is bytea, never jsonb, and compressed with lz4.
    $column = Cluster::rows(Cluster::superuser(), "select format_type(a.atttypid, a.atttypmod) as type, a.attcompression as compression from pg_attribute a where a.attrelid = 'raw_bodies'::regclass and a.attname = 'body'")[0];
    expect($column)->toBe(['type' => 'bytea', 'compression' => 'l']);

    $seen = Cluster::inWorkspace($app, $a, fn ($pdo) => array_column(Cluster::rows($pdo, 'select id from raw_bodies'), 'id'));
    expect($seen)->toHaveCount(2)->and(Cluster::inWorkspace($app, $b, fn ($pdo) => Cluster::rows($pdo, "select id from raw_bodies where id = '{$bodyA}'")))->toBe([]);
});

it('partitions raw_observations by month like sync_runs, with the same security on every partition and an idempotent ensure step', function () {
    $partitions = array_column(array_filter(Cluster::partitions(), fn (array $p): bool => $p['parent'] === 'raw_observations'), 'partition');
    $current = 'raw_observations_y'.gmdate('Y').'m'.gmdate('m');
    $strategy = Cluster::rows(Cluster::superuser(), "select pg_get_partkeydef('raw_observations'::regclass) as key")[0]['key'];

    expect($strategy)->toBe('RANGE (observed_at)')->and($partitions)->toContain('raw_observations_default', $current);

    foreach (['raw_observations', ...$partitions] as $table) {
        $flags = Cluster::rows(Cluster::superuser(), 'select relrowsecurity, relforcerowsecurity, pg_get_userbyid(relowner) as owner from pg_class where oid = ?::regclass', [$table])[0];
        expect($flags)->toMatchArray(['relrowsecurity' => true, 'relforcerowsecurity' => true, 'owner' => 'migrator'], $table);

        foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
            expect(Cluster::rows(Cluster::superuser(), 'select has_table_privilege(?, ?::regclass, ?) as p', ['app', $table, $privilege])[0]['p'])->toBeFalse("app {$privilege} on {$table}");
        }
    }

    $app = Cluster::pooledApp();
    $first = (int) Cluster::rows($app, 'select rawstore_ensure_raw_observation_partitions(3) as n')[0]['n'];
    $second = (int) Cluster::rows($app, 'select rawstore_ensure_raw_observation_partitions(3) as n')[0]['n'];
    $new = 'raw_observations_y'.gmdate('Y', strtotime('first day of +3 months')).'m'.gmdate('m', strtotime('first day of +3 months'));

    expect($second)->toBe(0)->and($first)->toBeGreaterThanOrEqual(0)
        ->and(Cluster::rows(Cluster::superuser(), 'select has_table_privilege(?, ?::regclass, ?) as p', ['app', $new, 'UPDATE'])[0]['p'])->toBeFalse()
        ->and(Cluster::rows(Cluster::superuser(), 'select has_table_privilege(?, ?::regclass, ?) as p', ['app', $new, 'INSERT'])[0]['p'])->toBeTrue()
        ->and(fn () => Cluster::rows($app, 'select rawstore_ensure_raw_observation_partitions(99)'))->toThrow(PDOException::class, 'months_ahead');

    foreach (['system' => false, 'operator' => false, 'app' => true, 'maintenance' => true] as $role => $expected) {
        expect(Cluster::rows(Cluster::superuser(), "select has_function_privilege(?, 'rawstore_ensure_raw_observation_partitions(integer)', 'EXECUTE') as p", [$role])[0]['p'])->toBe($expected, $role);
    }

    // The command prepares both tables.
    $this->artisan('dashflow:partitions:ensure', ['--months' => '1'])->expectsOutputToContain('raw_observations partition')->assertSuccessful();

    // A row lands in the partition of its month.
    $a = Cluster::workspace('A');
    $id = Cluster::seedRawObservation($a);
    expect(Cluster::rows(Cluster::superuser(), 'select tableoid::regclass::text as t from raw_observations where id = ?', [$id])[0]['t'])->toBe($current);
});

it('gives the Admin the status of a target that has never succeeded, and adds the failure to nothing', function () {
    [$workspace, $source, $endpoint, $target] = sdReady();
    $this->curl->queue = [FakeCurl::answer(500, '{}')];

    sdRun($workspace, $target, 1);

    expect(app(SyncStatuses::class)->forEndpoints($workspace, [$endpoint])[$endpoint]->state)->toBe('waiting')
        ->and(test()->getJson("/api/v1/admin/data-sources/{$source}/endpoints", SD_HEADERS)->json('data.0.sync'))->toMatchArray(['state' => 'waiting', 'last_success_at' => null, 'payload_changed_at' => null, 'reason' => null, 'missing_test_values' => []]);
});

it('schedules a target registered while no interval was set once an interval is configured', function () {
    config(['dashflow.tunables.sync.refresh_intervals.value' => null]);
    [$workspace, $source, $endpoint] = sdReady();
    expect(sdTargets()[0]['next_due_at'])->toBeNull();

    config(['dashflow.tunables.sync.refresh_intervals.value' => '600,120']);
    $event = new OutboxEnvelope((string) Str::uuid7(), 'connector.endpoint.revised', 1, $workspace, 'endpoint:'.$endpoint, 9, CarbonImmutable::now(), null, null, ['data_source_id' => $source, 'endpoint_id' => $endpoint]);
    app(WorkspaceTransaction::class)->run($workspace, fn () => (new RegisterSyncTargets)->handle($event));

    expect(sdTargets())->toHaveCount(1)
        ->and(sdTargets()[0]['refresh_interval_seconds'])->toBe(120)
        ->and(sdTargets()[0]['next_due_at'])->not->toBeNull();
    Queue::fake();
    expect(app(DispatchDueSyncs::class)->run())->toBe(1);
});

it('skips an Endpoint whose stored values cannot make a key and carries on with the rest of the batch', function () {
    [$workspace, $source] = sdSetup();
    $bad = sdCreate($source, ['params' => [['name' => 'from', 'binding' => 'date_range_from']], 'test_values' => ['from' => '2026-10-01']]);
    sdCreate($source, ['path' => '/costs']);
    sdRelay();
    expect(sdTargets())->toHaveCount(2);

    // A value that passed validation once and is no longer a date (written behind the trigger's back).
    $pdo = Cluster::superuser();
    $pdo->exec('alter table endpoint_revisions disable trigger endpoint_revisions_immutable');
    $pdo->prepare('update endpoint_revisions set test_values = ?::jsonb where endpoint_id = ?')->execute(['{"from":"2026-02-30"}', $bad]);
    $pdo->exec('alter table endpoint_revisions enable trigger endpoint_revisions_immutable');
    $pdo->prepare('update data_sources set revision = 2 where id = ?')->execute([$source]);

    $event = new OutboxEnvelope((string) Str::uuid7(), 'connector.data_source.updated', 1, $workspace, 'data_source:'.$source, 9, CarbonImmutable::now(), null, null, ['data_source_id' => $source, 'revision' => 2]);
    app(WorkspaceTransaction::class)->run($workspace, fn () => (new RegisterSyncTargets)->handle($event));

    $current = sdTargets('retired_at is null');
    // The good Endpoint moved to Data Source revision 2; the bad one was skipped and keeps what it had.
    $byEndpoint = array_column($current, 'data_source_revision', 'endpoint_id');
    expect($current)->toHaveCount(2)->and($byEndpoint[$bad])->toBe(1)->and(array_values(array_diff($byEndpoint, [1])))->toBe([2])
        ->and((string) file_get_contents($this->logFile))->toContain('ingestion.register.endpoint_skipped')->not->toContain('2026-02-30');
});

it('records a failed result that lost the race as superseded and leaves the counters alone', function () {
    [$workspace, , , $target] = sdReady();

    app()->instance(EndpointFetcher::class, new class($target) implements EndpointFetcher
    {
        public function __construct(private readonly string $target) {}

        public function fetch(EndpointFetchSpec $spec): EndpointFetchResult
        {
            Cluster::superuser()->prepare('update sync_targets set dispatch_seq = 2, applied_seq = 2, payload_seq = 1 where id = ?')->execute([$this->target]);

            return new EndpointFetchResult(false, null, 500, 1, 0, ConnectionTestCode::FetchFailed, 'http_500', 'https://api.example.com/revenue', ['region'], dataSourceId: $spec->dataSourceId);
        }
    });

    sdRun($workspace, $target, 1);

    expect(sdTargets()[0])->toMatchArray(['applied_seq' => 2, 'payload_seq' => 1, 'consecutive_failures' => 0, 'last_checked_at' => null])
        ->and(array_column(sdRuns(), 'status'))->toBe(['superseded']);
});

it('sends nothing for a POST that is not read-only and records a failed run', function () {
    [$workspace, , $endpoint, $target] = sdReady([
        'method' => 'POST', 'path' => '/query', 'body_template' => '{"region":{"$param":"region"}}', 'read_only_query' => true, 'confirm_read_only' => true,
    ]);
    $pdo = Cluster::superuser();
    $pdo->exec('alter table endpoint_revisions drop constraint endpoint_revisions_post_readonly_check');
    $pdo->exec('alter table endpoint_revisions disable trigger endpoint_revisions_immutable');

    try {
        $pdo->prepare('update endpoint_revisions set read_only_query = false where endpoint_id = ?')->execute([$endpoint]);
        sdRun($workspace, $target, 1);
    } finally {
        $pdo->prepare('update endpoint_revisions set method = \'GET\', body_template = null, read_only_query = false where endpoint_id = ?')->execute([$endpoint]);
        $pdo->exec('alter table endpoint_revisions enable trigger endpoint_revisions_immutable');
        $pdo->exec("alter table endpoint_revisions add constraint endpoint_revisions_post_readonly_check check (method = 'GET' or read_only_query)");
    }

    expect($this->curl->calls)->toBe([])
        ->and(sdRuns())->toHaveCount(1)
        ->and(sdRuns()[0])->toMatchArray(['status' => 'failed', 'error_code' => 'config-error'])
        ->and(sdTargets()[0])->toMatchArray(['consecutive_failures' => 1, 'applied_seq' => 1]);
});

it('rolls a body that cannot be stored back and records a failed run, store-failed', function () {
    [$workspace, , , $target] = sdReady();
    $this->curl->queue = [FakeCurl::answer(200, SD_BODY)];
    app()->instance(RawStore::class, new class implements RawStore
    {
        public function put(string $workspaceId, string $syncTargetId, int $seq, int $dispatchSeq, string $body, ?string $requestId, DateTimeInterface $observedAt): RawPayload
        {
            throw new RuntimeException('disk full');
        }

        public function get(string $workspaceId, string $syncTargetId, string $payloadId): ?string
        {
            return null;
        }
    });

    sdRun($workspace, $target, 1);

    expect(sdTargets()[0])->toMatchArray(['payload_seq' => 0, 'current_payload_id' => null, 'applied_seq' => 1, 'consecutive_failures' => 1, 'last_success_at' => null])
        ->and(sdCount('raw_bodies'))->toBe(0)
        ->and(sdCount('outbox_events where type = \'ingestion.payload.changed\''))->toBe(0)
        ->and(sdRuns())->toHaveCount(1)
        ->and(sdRuns()[0])->toMatchArray(['status' => 'failed', 'error_code' => 'store-failed']);
});

// ---- Story 2.15: conditional requests and unchanged data ------------------------------------------------------------------------------

/** A 200 JSON answer with the given validators. */
function sdOk(string $body = SD_BODY, ?string $etag = null, ?string $modified = null): CurlResult
{
    return FakeCurl::answer(200, $body, ['content-type' => ['application/json']] + ($etag === null ? [] : ['etag' => [$etag]]) + ($modified === null ? [] : ['last-modified' => [$modified]]));
}

/** A 304, with the validators it may carry. */
function sdNotModified(?string $etag = null, ?string $modified = null): CurlResult
{
    return FakeCurl::answer(304, '', ($etag === null ? [] : ['etag' => [$etag]]) + ($modified === null ? [] : ['last-modified' => [$modified]]));
}

/** @return list<string> the request headers of call `$n` */
function sdHeaders(int $n): array
{
    return test()->curl->calls[$n][CURLOPT_HTTPHEADER];
}

function sdConditional(int $n): array
{
    return array_values(array_filter(sdHeaders($n), fn (string $line): bool => preg_match('/^If-(None-Match|Modified-Since):/i', $line) === 1));
}

/** The row counts that an unchanged success must not move. */
function sdCounts(): array
{
    return [sdCount('raw_bodies'), sdCount('raw_observations'), sdCount('outbox_events where type = \'ingestion.payload.changed\'')];
}

it('sends If-None-Match from the stored ETag and treats a 304 as an unchanged success that moves only the check times', function () {
    [$workspace, , $endpoint, $target] = sdReady();
    $this->curl->queue = [sdOk(SD_BODY, '"v1"', 'Wed, 21 Oct 2015 07:28:00 GMT'), sdNotModified()];

    sdRun($workspace, $target, 1);
    $first = sdTargets()[0];
    expect($first)->toMatchArray(['etag' => '"v1"', 'last_modified' => 'Wed, 21 Oct 2015 07:28:00 GMT'])
        ->and(sdConditional(0))->toBe([]);

    usleep(20000);
    sdRun($workspace, $target, 2);
    $second = sdTargets()[0];

    // The ETag wins over the Last-Modified, and is sent verbatim.
    expect(sdConditional(1))->toBe(['If-None-Match: "v1"'])
        ->and($second)->toMatchArray([
            'current_payload_id' => $first['current_payload_id'], 'payload_seq' => 1, 'payload_changed_at' => $first['payload_changed_at'], 'content_hash' => $first['content_hash'],
            'etag' => '"v1"', 'applied_seq' => 2, 'consecutive_failures' => 0,
        ])
        ->and(CarbonImmutable::parse($second['last_success_at'])->gt(CarbonImmutable::parse($first['last_success_at'])))->toBeTrue()
        ->and(CarbonImmutable::parse($second['last_checked_at'])->gt(CarbonImmutable::parse($first['last_checked_at'])))->toBeTrue()
        ->and(sdCounts())->toBe([1, 1, 1]);

    $runs = sdRuns();
    expect(array_column($runs, 'status'))->toBe(['succeeded', 'succeeded'])
        ->and(array_column($runs, 'outcome'))->toBe(['changed', 'not_modified'])
        ->and($runs[1])->toMatchArray(['http_status' => 304, 'error_code' => null]);

    // The Admin sees the 304 as a success and a check, and the data as of the first response.
    $status = app(SyncStatuses::class)->forEndpoints($workspace, [$endpoint])[$endpoint];
    expect($status->state)->toBe('succeeded')
        ->and($status->payloadChangedAt)->toBe(CarbonImmutable::parse($first['payload_changed_at'])->utc()->format('Y-m-d\\TH:i:s\\Z'))
        ->and($status->lastCheckedAt)->toBe(CarbonImmutable::parse($second['last_checked_at'])->utc()->format('Y-m-d\\TH:i:s\\Z'));
});

it('sends If-Modified-Since from the stored Last-Modified when there is no ETag, with the same 304 outcome', function () {
    [$workspace, , , $target] = sdReady();
    $this->curl->queue = [sdOk(SD_BODY, null, 'Wed, 21 Oct 2015 07:28:00 GMT'), sdNotModified(null, 'Thu, 22 Oct 2015 07:28:00 GMT')];

    sdRun($workspace, $target, 1);
    sdRun($workspace, $target, 2);

    expect(sdConditional(1))->toBe(['If-Modified-Since: Wed, 21 Oct 2015 07:28:00 GMT'])
        ->and(sdTargets()[0])->toMatchArray(['payload_seq' => 1, 'applied_seq' => 2, 'etag' => null, 'last_modified' => 'Thu, 22 Oct 2015 07:28:00 GMT'])
        ->and(sdCounts())->toBe([1, 1, 1])
        ->and(array_column(sdRuns(), 'outcome'))->toBe(['changed', 'not_modified']);
});

it('compares the lossless-canonical hash when the source gives no validator: whitespace and key order are unchanged, a number lexeme is a change', function () {
    [$workspace, , , $target] = sdReady();
    $this->curl->queue = [
        sdOk('{"b":[1,2,{"y":true,"x":null}],"a":"é\\u00e9/","n":1.0}'),
        sdOk("{ \"n\" : 1.0,\n \"a\":\"é\\u00e9/\" , \"b\" : [ 1, 2, { \"x\": null, \"y\": true } ] }"),
        sdOk('{"b":[1,2,{"y":true,"x":null}],"a":"é\\u00e9/","n":1}'),
        sdOk('{"b":[2,1,{"y":true,"x":null}],"a":"é\\u00e9/","n":1}'),
    ];

    sdRun($workspace, $target, 1);
    $first = sdTargets()[0];
    sdRun($workspace, $target, 2);

    // No validator was stored, so none was sent; the equal body is an unchanged success that stores nothing and emits nothing.
    expect(sdConditional(0))->toBe([])->and(sdConditional(1))->toBe([])
        ->and(sdTargets()[0])->toMatchArray(['payload_seq' => 1, 'applied_seq' => 2, 'content_hash' => $first['content_hash'], 'current_payload_id' => $first['current_payload_id'], 'payload_changed_at' => $first['payload_changed_at']])
        ->and(sdCounts())->toBe([1, 1, 1])
        ->and(array_column(sdRuns(), 'outcome'))->toBe(['changed', 'unchanged']);

    // 1.0 became 1: a different lexeme, so a change, stored with its own hash.
    sdRun($workspace, $target, 3);
    expect(sdTargets()[0])->toMatchArray(['payload_seq' => 2])->and(sdTargets()[0]['content_hash'])->not->toBe($first['content_hash'])
        ->and(sdCounts())->toBe([2, 2, 2]);

    // A list is kept in order.
    sdRun($workspace, $target, 4);
    expect(sdTargets()[0]['payload_seq'])->toBe(3)->and(sdCounts())->toBe([3, 3, 3]);
});

it('stores a changed body with its observation, event, new hash, validators and Data as of', function () {
    [$workspace, , $endpoint, $target] = sdReady();
    $this->curl->queue = [sdOk('{"total":1}', '"v1"'), sdOk('{"total":2}', '"v2"')];

    sdRun($workspace, $target, 1);
    $first = sdTargets()[0];
    usleep(20000);
    sdRun($workspace, $target, 2);
    $second = sdTargets()[0];

    expect(sdConditional(1))->toBe(['If-None-Match: "v1"'])
        ->and($second)->toMatchArray(['payload_seq' => 2, 'etag' => '"v2"', 'content_hash' => hash('sha256', '{"total":2}'), 'applied_seq' => 2])
        ->and($second['current_payload_id'])->not->toBe($first['current_payload_id'])
        ->and(CarbonImmutable::parse($second['payload_changed_at'])->gt(CarbonImmutable::parse($first['payload_changed_at'])))->toBeTrue()
        ->and($second['payload_changed_at'])->toBe($second['last_success_at'])
        ->and(sdCounts())->toBe([2, 2, 2])
        ->and(array_column(sdRuns(), 'outcome'))->toBe(['changed', 'changed']);

    $events = Cluster::rows(Cluster::superuser(), "select data from outbox_events where type = 'ingestion.payload.changed' order by (data->>'payload_seq')::int");
    expect(json_decode($events[1]['data'], true))->toEqual(['sync_target_id' => $target, 'endpoint_id' => $endpoint, 'payload_id' => $second['current_payload_id'], 'payload_seq' => 2, 'dispatch_seq' => 2]);
});

it('compares the hash of a 200 even when a validator was sent, and refreshes the validators it brings', function () {
    [$workspace, , , $target] = sdReady();
    $this->curl->queue = [sdOk(SD_BODY, '"v1"'), sdOk(' '.SD_BODY, '"v2"', 'Thu, 22 Oct 2015 07:28:00 GMT'), sdOk(SD_BODY)];

    sdRun($workspace, $target, 1);
    sdRun($workspace, $target, 2);

    expect(sdConditional(1))->toBe(['If-None-Match: "v1"'])
        ->and(sdTargets()[0])->toMatchArray(['payload_seq' => 1, 'etag' => '"v2"', 'last_modified' => 'Thu, 22 Oct 2015 07:28:00 GMT'])
        ->and(sdCounts())->toBe([1, 1, 1])
        ->and(array_column(sdRuns(), 'outcome'))->toBe(['changed', 'unchanged']);

    // A 200 that carries no validator replaces them with none: what is stored is what the latest answer said.
    sdRun($workspace, $target, 3);
    expect(sdConditional(2))->toBe(['If-None-Match: "v2"'])
        ->and(sdTargets()[0])->toMatchArray(['etag' => null, 'last_modified' => null, 'payload_seq' => 1]);
});

it('treats a body that cannot be canonicalised as changed, stored, with no hash', function () {
    [$workspace, , , $target] = sdReady();
    // Deeper than the limit that is set for hashing is a parse failure: the JSON check passes with no limit, the hash needs one.
    $deep = str_repeat('[', 600).str_repeat(']', 600);
    $this->curl->queue = [sdOk($deep), sdOk($deep)];

    sdRun($workspace, $target, 1);
    sdRun($workspace, $target, 2);

    expect(sdTargets()[0])->toMatchArray(['content_hash' => null, 'payload_seq' => 2])
        ->and(sdCounts())->toBe([1, 2, 2])
        ->and(array_column(sdRuns(), 'outcome'))->toBe(['changed', 'changed']);
});

it('sends nothing conditional for a revised Endpoint or Data Source: the new target starts empty and the old one is retired with its state', function () {
    [$workspace, $source, $endpoint, $first] = sdReady();
    $this->curl->queue = [sdOk(SD_BODY, '"v1"'), sdOk(SD_BODY, '"v9"'), sdOk(SD_BODY, '"v10"')];
    sdRun($workspace, $first, 1);

    sdRevise($source, $endpoint, 1, ['path' => '/revenue-v2'])->assertOk();
    sdRelay();
    $targets = sdTargets();
    $second = $targets[1];

    expect($targets[0])->toMatchArray(['id' => $first, 'etag' => '"v1"', 'content_hash' => hash('sha256', SD_CANONICAL)])
        ->and($targets[0]['retired_at'])->not->toBeNull()
        ->and($second)->toMatchArray(['etag' => null, 'last_modified' => null, 'content_hash' => null, 'current_payload_id' => null, 'payload_changed_at' => null]);

    // The new target's first run is a full fetch, a change although the body equals the old target's.
    sdRun($workspace, $second['id'], 1);
    expect(sdConditional(1))->toBe([])
        ->and(sdTargets("id = '{$second['id']}'")[0])->toMatchArray(['payload_seq' => 1, 'etag' => '"v9"'])
        ->and(sdCounts())->toBe([2, 2, 2]);

    // A Data Source revision does the same.
    test()->putJson("/api/v1/admin/data-sources/{$source}", [
        'name' => 'Sales API', 'base_url' => 'https://api.example.com/v1', 'headers' => [], 'timeout_seconds' => null, 'max_response_bytes' => null,
        'max_pages' => null, 'live_capable' => false, 'auth_type' => 'none', 'revision' => 1,
    ], SD_HEADERS)->assertOk();
    sdRelay();
    $third = sdTargets('retired_at is null');
    expect($third)->toHaveCount(1)->and($third[0])->toMatchArray(['etag' => null, 'content_hash' => null, 'current_payload_id' => null]);
    sdRun($workspace, $third[0]['id'], 1);
    expect(sdConditional(2))->toBe([]);

    // The retired target is never run again, and nothing is sent for it.
    $calls = count($this->curl->calls);
    $runs = count(sdRuns());
    sdRun($workspace, $first, 2);
    expect(count($this->curl->calls))->toBe($calls)->and(count(sdRuns()))->toBe($runs);
});

it('fails a 304 that nothing conditional explains, clears the conditional state and makes the next fetch a full one', function (bool $withPayload) {
    [$workspace, , , $target] = sdReady();

    if ($withPayload) {
        // A payload but no validator: the run sends nothing conditional, so a 304 is unsolicited.
        $this->curl->queue = [sdOk(SD_BODY), sdNotModified(), sdOk(SD_BODY, '"v1"')];
        sdRun($workspace, $target, 1);
        Cluster::superuser()->prepare('update sync_targets set etag = null, last_modified = null where id = ?')->execute([$target]);
        $run = 2;
    } else {
        $this->curl->queue = [sdNotModified(), sdOk(SD_BODY, '"v1"')];
        Cluster::superuser()->prepare('update sync_targets set etag = ?, content_hash = ? where id = ?')->execute(['"stale"', 'a'.str_repeat('0', 63), $target]);
        $run = 1;
    }

    sdRun($workspace, $target, $run);
    $failed = sdTargets()[0];
    $runs = sdRuns();

    expect($failed)->toMatchArray(['etag' => null, 'last_modified' => null, 'content_hash' => null, 'consecutive_failures' => 1, 'applied_seq' => $run])
        ->and(sdConditional($withPayload ? 1 : 0))->toBe([])
        ->and(end($runs))->toMatchArray(['status' => 'failed', 'error_code' => 'fetch-failed', 'outcome' => null, 'http_status' => 304]);

    // The next fetch is a full one, and with no hash left it stores the body again.
    sdRun($workspace, $target, $run + 1);
    expect(sdConditional($withPayload ? 2 : 1))->toBe([])
        ->and(sdTargets()[0])->toMatchArray(['etag' => '"v1"', 'consecutive_failures' => 0])
        ->and(sdCount('raw_observations'))->toBe($withPayload ? 2 : 1);
})->with(['a payload but nothing sent' => [true], 'no payload' => [false]]);

it('sends no conditional header and keeps no validator for a paged Data Source, which relies on the hash', function () {
    [$workspace, , , $target] = sdReady();
    Cluster::superuser()->prepare("update data_sources set pagination_style = 'page', pagination_param = 'p'")->execute();
    $page = fn (string $records, string $etag) => sdOk($records, $etag, 'Wed, 21 Oct 2015 07:28:00 GMT');
    $this->curl->queue = [$page('[{"a":1}]', '"p1"'), $page('[]', '"p2"'), $page('[{"a":1}]', '"p3"'), $page('[]', '"p4"')];

    sdRun($workspace, $target, 1);
    // Even a stored validator (from before the source was paged) is not sent.
    Cluster::superuser()->prepare('update sync_targets set etag = ? where id = ?')->execute(['"old"', $target]);
    sdRun($workspace, $target, 2);

    expect(sdConditional(0))->toBe([])->and(sdConditional(2))->toBe([])
        ->and(sdTargets()[0])->toMatchArray(['payload_seq' => 1, 'applied_seq' => 2, 'last_modified' => null])
        ->and(sdCounts())->toBe([1, 1, 1])
        ->and(array_column(sdRuns(), 'outcome'))->toBe(['changed', 'unchanged']);
    // The first run kept none; the second brought none either (the transport reports first-page headers only, which are not used).
    expect(sdTargets()[0]['etag'])->toBeNull();
});

it('keeps only a validator that is exactly one value of at most 512 visible ASCII characters', function (array $headers, ?string $etag) {
    [$workspace, , , $target] = sdReady();
    $this->curl->queue = [FakeCurl::answer(200, SD_BODY, ['content-type' => ['application/json']] + $headers)];

    sdRun($workspace, $target, 1);

    expect(sdTargets()[0])->toMatchArray(['etag' => $etag, 'payload_seq' => 1]);
})->with([
    'one' => [['etag' => ['W/"abc"']], 'W/"abc"'],
    'several' => [['etag' => ['"a"', '"b"']], null],
    'too long' => [['etag' => ['"'.str_repeat('a', 511).'"']], null],
    'the longest' => [['etag' => [str_repeat('a', 512)]], str_repeat('a', 512)],
    'not visible ASCII' => [['etag' => ["\"caf\u{e9}\""]], null],
    'empty' => [['etag' => ['']], null],
]);

it('keeps the validators when a run fails for any other reason, and a late 304 changes nothing', function () {
    [$workspace, , , $target] = sdReady();
    $this->curl->queue = [sdOk(SD_BODY, '"v1"'), FakeCurl::answer(500, '{}'), new EgressTransportFailed('timeout', 28), sdNotModified('"v2"')];

    sdRun($workspace, $target, 5);
    $good = sdTargets()[0];
    // A 5xx and a timeout are failures that keep the validators, so the next run still sends them.
    sdRun($workspace, $target, 6);
    sdRun($workspace, $target, 7);
    expect(sdTargets()[0])->toMatchArray(['etag' => '"v1"', 'content_hash' => $good['content_hash'], 'consecutive_failures' => 2, 'current_payload_id' => $good['current_payload_id']])
        ->and(sdConditional(1))->toBe(['If-None-Match: "v1"'])->and(sdConditional(2))->toBe(['If-None-Match: "v1"']);

    // A late run is superseded before anything is sent.
    $before = sdTargets()[0];
    sdRun($workspace, $target, 4);
    expect(sdTargets()[0])->toBe($before)->and($this->curl->calls)->toHaveCount(3);
});

it('supersedes a 304 that loses the race at commit, or that answers for a validator that has since moved on', function (string $race) {
    [$workspace, , , $target] = sdReady();
    $this->curl->queue = [sdOk(SD_BODY, '"v1"')];
    sdRun($workspace, $target, 1);
    $before = sdTargets()[0];

    app()->instance(EndpointFetcher::class, new class($target, $race) implements EndpointFetcher
    {
        public function __construct(private readonly string $target, private readonly string $race) {}

        public function fetch(EndpointFetchSpec $spec): EndpointFetchResult
        {
            // While the conditional request is out, another dispatch commits on its own connection.
            $sql = $this->race === 'newer'
                ? 'update sync_targets set dispatch_seq = 3, applied_seq = 3 where id = ?'
                : "update sync_targets set etag = '\"v2\"', dispatch_seq = 1 where id = ?";
            Cluster::superuser()->prepare($sql)->execute([$this->target]);

            return new EndpointFetchResult(true, null, 304, 1, 0, null, null, 'https://api.example.com/revenue', ['region'], dataSourceId: $spec->dataSourceId, notModified: true);
        }
    });

    sdRun($workspace, $target, 2);

    $row = sdTargets()[0];
    expect($row['payload_seq'])->toBe(1)
        ->and($row['current_payload_id'])->toBe($before['current_payload_id'])
        ->and($row['last_success_at'])->toBe($before['last_success_at'])
        ->and($row['last_checked_at'])->toBe($before['last_checked_at'])
        ->and($row['applied_seq'])->toBe($race === 'newer' ? 3 : 1)
        ->and(sdCounts())->toBe([1, 1, 1])
        ->and(array_column(sdRuns(), 'status'))->toBe(['succeeded', 'superseded']);
})->with(['a newer dispatch applied' => ['newer'], 'the validator moved' => ['validator']]);

it('keeps a validator, the hash and the bodies out of sync_runs, the log, the audit and the outbox', function () {
    [$workspace, , , $target] = sdReady();
    $etag = '"e-'.SD_CANARY.'"';
    $this->curl->queue = [sdOk('{"note":"b-'.SD_CANARY.'"}', $etag, 'Mon, 01 Jan 2024 '.SD_CANARY), sdNotModified(), sdOk('{"note":"c-'.SD_CANARY.'"}', $etag), sdNotModified('"n-'.SD_CANARY.'"')];

    foreach ([1, 2, 3, 4] as $seq) {
        sdRun($workspace, $target, $seq);
    }

    expect(sdConditional(1))->toBe(['If-None-Match: '.$etag]);

    $everything = json_encode([
        Cluster::rows(Cluster::superuser(), 'select * from sync_runs'),
        Cluster::rows(Cluster::superuser(), 'select * from audit_events'),
        Cluster::rows(Cluster::superuser(), 'select * from outbox_events'),
        Cluster::rows(Cluster::superuser(), 'select * from operations'),
        (string) file_get_contents($this->logFile),
    ], JSON_THROW_ON_ERROR);

    expect($everything)->not->toContain(SD_CANARY);
    // The conditional state is the target's own, by design.
    expect(sdTargets()[0]['etag'])->toContain(SD_CANARY);
});

it('counts each outcome on its own metric: payload_changed for a change only', function () {
    [$workspace, , , $target] = sdReady();
    $recorded = [];
    app()->instance(MetricEmitter::class, new class($recorded) implements MetricEmitter
    {
        /** @param  list<string>  $seen */
        public function __construct(public array &$seen) {}

        public function increment(string $name, array $labels = [], int $by = 1): void
        {
            $this->seen[] = $name;
        }
    });
    $this->curl->queue = [sdOk(SD_BODY, '"v1"'), sdNotModified(), sdOk(' '.SD_BODY), sdOk('{"x":1}')];

    foreach ([1, 2, 3, 4] as $seq) {
        sdRun($workspace, $target, $seq);
    }

    $counts = array_count_values(array_filter($recorded, fn (string $name): bool => str_starts_with($name, 'dashflow.ingestion.')));
    expect($counts['dashflow.ingestion.payload_changed'])->toBe(2)
        ->and($counts['dashflow.ingestion.fetch_not_modified'])->toBe(1)
        ->and($counts['dashflow.ingestion.fetch_unchanged'])->toBe(1)
        ->and($counts['dashflow.ingestion.fetch_succeeded'])->toBe(4);
});

it('backfills Data as of and nulls the old byte hashes when the conditional-state migration runs again, and the next run is a change', function () {
    [$workspace, , , $target] = sdReady();
    $this->curl->queue = [sdOk(SD_BODY), sdOk(SD_BODY)];
    sdRun($workspace, $target, 1);

    try {
        // Four steps: the newest migrations are Story 2.18's (health), Story 2.17's (attempt), 2.16's (retention), then 2.15's (conditional state).
        expect(Artisan::call('migrate:rollback', ['--database' => 'migrator', '--step' => 4, '--force' => true]))->toBe(0);

        // A target as 2.14 left it: a payload, the hash of the bytes and a last success.
        Cluster::superuser()->prepare('update sync_targets set content_hash = ?, last_success_at = ? where id = ?')->execute([hash('sha256', SD_BODY), '2026-10-01 10:00:00+00', $target]);
    } finally {
        Artisan::call('migrate', ['--database' => 'migrator', '--force' => true]);
    }

    $row = sdTargets()[0];
    expect($row['payload_changed_at'])->toBe($row['last_success_at'])->and($row['payload_changed_at'])->toBe('2026-10-01 10:00:00+00')
        ->and($row['content_hash'])->toBeNull();

    // With no hash to compare, the next run is one `changed` run.
    sdRun($workspace, $target, 2);
    // The rollback dropped the first run's outcome with the column.
    expect(array_column(sdRuns(), 'outcome'))->toBe([null, 'changed'])->and(sdCount('raw_observations'))->toBe(2);
});

// ---- Story 2.17: retry, rate limit and circuit breaker. The governor is the in-memory one (no Valkey in the suite), the jitter is fixed. ----

/** @param  array<string, string>  $settings  dotted `dashflow.tunables.*` / `dashflow.fetch.*` names without `.value` */
function rbSettings(array $settings): void
{
    foreach ($settings as $name => $value) {
        config(['dashflow.'.$name.'.value' => $value]);
    }
}

function rbRetryOn(string $attempts = '3'): void
{
    rbSettings(['tunables.retry.base' => '2', 'tunables.retry.cap' => '60', 'tunables.retry.max_attempts' => $attempts]);
}

function rbGovernor(): FakeGovernor
{
    $governor = new FakeGovernor;
    app()->instance(SourceGovernor::class, $governor);

    return $governor;
}

/** The target is due again in five minutes, as the dispatcher leaves it. */
function rbDue(string $target, int $seconds = 300): void
{
    Cluster::superuser()->prepare('update sync_targets set next_due_at = now() + make_interval(secs => ?) where id = ?')->execute([$seconds, $target]);
}

/** One attempt of a dispatch, run by hand (no queue), so a retry that was queued can be inspected instead of run. */
function rbAttempt(string $workspace, string $target, int $seq, int $attempt = 1): void
{
    sdDispatched($target, $seq);
    app(WorkspaceTransaction::class)->run($workspace, fn () => (new FetchJob($workspace, $target, $seq, $attempt))->handle(app(FetchSyncTarget::class)));
}

function rbRunStatuses(): array
{
    return array_map(fn (array $run): string => $run['status'].'/'.($run['attempt'] ?? '-'), sdRuns());
}

function rbMetrics(array &$seen): void
{
    app()->instance(MetricEmitter::class, new class($seen) implements MetricEmitter
    {
        /** @param  list<string>  $seen */
        public function __construct(public array &$seen) {}

        public function increment(string $name, array $labels = [], int $by = 1): void
        {
            expect(array_keys($labels))->toBe(['workspace_id']);
            $this->seen[] = $name;
        }
    });
}

it('retries a source that fails twice and then answers: three runs within one interval, one commit', function () {
    [$workspace, , , $target] = sdReady();
    rbRetryOn();
    rbGovernor();
    app()->instance(Jitter::class, new FixedJitter(0.5));
    rbDue($target);
    $this->curl->queue = [FakeCurl::answer(500, '{}'), new EgressTransportFailed('timeout', 28), sdOk()];

    // A synchronous queue runs each requeued attempt in place.
    sdRun($workspace, $target, 1);

    expect(rbRunStatuses())->toBe(['retrying/1', 'retrying/2', 'succeeded/3'])
        ->and(sdRuns()[0]['error_code'])->toBe('fetch-failed')
        ->and(sdTargets()[0])->toMatchArray(['applied_seq' => 1, 'payload_seq' => 1, 'consecutive_failures' => 0])
        ->and(sdCount('raw_observations'))->toBe(1)
        ->and(sdCount('outbox_events where type = \'ingestion.payload.changed\''))->toBe(1);
});

it('queues the retry with the jittered delay and leaves the target untouched while it is retrying', function () {
    [$workspace, , , $target] = sdReady();
    rbRetryOn();
    rbGovernor();
    $jitter = new FixedJitter(1.0);
    app()->instance(Jitter::class, $jitter);
    rbDue($target);
    Queue::fake();
    $this->curl->queue = [FakeCurl::answer(502, '{}')];
    $before = sdTargets()[0];

    rbAttempt($workspace, $target, 1);

    $after = sdTargets()[0];
    expect(rbRunStatuses())->toBe(['retrying/1'])
        ->and($jitter->ceilings)->toBe([2])
        ->and($after['consecutive_failures'])->toBe($before['consecutive_failures'])
        ->and($after['applied_seq'])->toBe($before['applied_seq'])
        ->and($after['last_checked_at'])->toBe($before['last_checked_at']);

    // The same fenced job, one attempt later, with the delay; a second failure doubles the ceiling.
    Queue::assertPushed(FetchJob::class, fn (FetchJob $job): bool => $job->attempt === 2 && $job->dispatchSeq === 1 && $job->delay === 2);
    $this->curl->queue = [FakeCurl::answer(502, '{}')];
    rbAttempt($workspace, $target, 1, 2);
    expect($jitter->ceilings)->toBe([2, 4]);
    Queue::assertPushed(FetchJob::class, fn (FetchJob $job): bool => $job->attempt === 3 && $job->delay === 4);
});

it('makes the last attempt the failure: failed run, one counted failure, the last good payload kept', function () {
    [$workspace, , , $target] = sdReady();
    rbRetryOn('2');
    rbGovernor();
    app()->instance(Jitter::class, new FixedJitter(0.0));
    $this->curl->queue = [sdOk()];
    sdRun($workspace, $target, 1);
    $good = sdTargets()[0];
    rbDue($target);
    $this->curl->queue = [FakeCurl::answer(500, '{}'), FakeCurl::answer(500, '{}')];

    sdRun($workspace, $target, 2);

    expect(rbRunStatuses())->toBe(['succeeded/1', 'retrying/1', 'failed/2'])
        ->and(sdTargets()[0])->toMatchArray(['applied_seq' => 2, 'consecutive_failures' => 1, 'current_payload_id' => $good['current_payload_id']]);
});

it('does not retry when the delay would pass the next due time, or when retries are not configured', function () {
    [$workspace, , , $target] = sdReady();
    rbRetryOn();
    rbGovernor();
    app()->instance(Jitter::class, new FixedJitter(1.0));
    rbDue($target, 1);
    Queue::fake();
    $this->curl->queue = [FakeCurl::answer(500, '{}')];

    rbAttempt($workspace, $target, 1);

    expect(rbRunStatuses())->toBe(['failed/1'])->and(sdTargets()[0]['consecutive_failures'])->toBe(1);
    Queue::assertNothingPushed();

    // Unset: exactly as Story 2.15, and the governor's store is never opened.
    rbSettings(['tunables.retry.base' => null, 'tunables.retry.cap' => null, 'tunables.retry.max_attempts' => null]);
    $redis = Mockery::mock(RedisFactory::class);
    $redis->shouldNotReceive('connection');
    app()->instance(SourceGovernor::class, new ValkeySourceGovernor($redis));
    rbDue($target);
    $this->curl->queue = [FakeCurl::answer(500, '{}')];

    rbAttempt($workspace, $target, 2);

    expect(rbRunStatuses())->toBe(['failed/1', 'failed/1'])->and(sdTargets()[0]['consecutive_failures'])->toBe(2);
    Queue::assertNothingPushed();
});

it('honours Retry-After up to the cap, sets the penalty, empties the bucket, and makes other targets of the source wait', function () {
    [$workspace, $source, , $target] = sdReady();
    rbRetryOn();
    rbSettings(['tunables.budgets.max_fetch_rate_per_data_source' => '10']);
    $governor = rbGovernor();
    app()->instance(Jitter::class, new FixedJitter(0.0));
    rbDue($target);
    Queue::fake();
    $seen = [];
    rbMetrics($seen);
    $this->curl->queue = [FakeCurl::answer(429, '{}', ['retry-after' => ['9999']])];

    rbAttempt($workspace, $target, 1);

    $key = $workspace.':'.$source;
    expect(rbRunStatuses())->toBe(['retrying/1'])
        ->and($governor->penalties[$key] - $governor->now)->toBe(60.0)
        ->and($governor->buckets[$key]['tokens'])->toBe(0.0)
        ->and($governor->records)->toBe([['throttled', false]])
        ->and($seen)->toContain('dashflow.connector.throttled')->toContain('dashflow.connector.fetch_retried');
    Queue::assertPushed(FetchJob::class, fn (FetchJob $job): bool => $job->attempt === 2 && $job->delay === 60);

    // Another target of the same source asks for a call while the penalty stands: it is not sent.
    $denied = $governor->admit($workspace, $source, app(SyncSettings::class)->governorLimits());
    expect($denied->reason)->toBe(Admission::RATE_LIMITED)->and($denied->waitSeconds)->toBe(60);
});

it('retries a 503 with Retry-After as throttled, and a 503 without one as transient', function () {
    [$workspace, , , $target] = sdReady();
    rbRetryOn();
    $governor = rbGovernor();
    app()->instance(Jitter::class, new FixedJitter(0.0));
    rbDue($target);
    Queue::fake();
    $this->curl->queue = [FakeCurl::answer(503, '{}', ['retry-after' => ['Wed, 21 Oct 2099 07:28:00 GMT']]), FakeCurl::answer(503, '{}')];

    rbAttempt($workspace, $target, 1);
    $governor->now += 61;
    rbAttempt($workspace, $target, 1, 2);

    // The date is far away: clamped to the cap. Then a plain 503 counts for the breaker.
    Queue::assertPushed(FetchJob::class, fn (FetchJob $job): bool => $job->attempt === 2 && $job->delay === 60);
    expect($governor->records)->toBe([['throttled', false], ['fail', false]]);
});

it('does not retry another 4xx: failed, config-error with the status, and the breaker is reset not counted', function () {
    [$workspace, , , $target] = sdReady();
    rbRetryOn();
    rbSettings(['tunables.circuit_breaker.failure_count' => '2', 'tunables.circuit_breaker.cool_down' => '60']);
    $governor = rbGovernor();
    rbDue($target);
    Queue::fake();
    $this->curl->queue = [FakeCurl::answer(404, '{}'), FakeCurl::answer(404, '{}'), FakeCurl::answer(404, '{}')];

    foreach ([1, 2, 3] as $seq) {
        rbAttempt($workspace, $target, $seq);
    }

    expect(rbRunStatuses())->toBe(['failed/1', 'failed/1', 'failed/1'])
        ->and(sdRuns()[0])->toMatchArray(['error_code' => 'config-error', 'http_status' => 404])
        ->and($governor->breakers)->toBe([])
        ->and(array_unique(array_column($governor->records, 0)))->toBe(['ok']);
    Queue::assertNothingPushed();
});

it('does not retry a bad body, keeps the payload and resets the breaker', function (string $kind) {
    [$workspace, , , $target] = sdReady();
    rbRetryOn();
    rbSettings(['tunables.circuit_breaker.failure_count' => '3', 'tunables.circuit_breaker.cool_down' => '60']);
    $governor = rbGovernor();
    rbDue($target);
    Queue::fake();
    $this->curl->queue = [sdOk(), sdDown($kind)];
    rbAttempt($workspace, $target, 1);
    $good = sdTargets()[0];

    rbAttempt($workspace, $target, 2);

    expect(rbRunStatuses())->toBe(['succeeded/1', 'failed/1'])
        ->and(sdRuns()[1]['error_code'])->not->toBe('config-error')
        ->and(sdTargets()[0])->toMatchArray(['current_payload_id' => $good['current_payload_id'], 'consecutive_failures' => 1])
        ->and($governor->records)->toBe([['ok', false], ['ok', false]]);
    Queue::assertNothingPushed();
})->with(['not json' => ['html'], 'truncated' => ['truncated']]);

it('does not retry a POST whose outcome is ambiguous, and retries one that cannot have been sent', function () {
    [$workspace, , , $target] = sdReady([
        'method' => 'POST', 'path' => '/query', 'body_template' => '{"region":{"$param":"region"}}', 'read_only_query' => true, 'confirm_read_only' => true,
    ]);
    rbRetryOn();
    rbSettings(['tunables.circuit_breaker.failure_count' => '9', 'tunables.circuit_breaker.cool_down' => '60']);
    $governor = rbGovernor();
    app()->instance(Jitter::class, new FixedJitter(0.0));
    rbDue($target);
    Queue::fake();
    $this->curl->queue = [new EgressTransportFailed('x', 28)];

    rbAttempt($workspace, $target, 1);

    expect(rbRunStatuses())->toBe(['failed/1'])->and($governor->records)->toBe([['fail', false]]);
    Queue::assertNothingPushed();

    $this->curl->queue = [new EgressTransportFailed('x', 7)];
    rbAttempt($workspace, $target, 2);
    Queue::assertPushed(FetchJob::class, fn (FetchJob $job): bool => $job->attempt === 2 && $job->dispatchSeq === 2);

    $this->curl->queue = [FakeCurl::answer(500, '{}')];
    rbAttempt($workspace, $target, 3);
    Queue::assertPushed(FetchJob::class, 1);
    expect(rbRunStatuses())->toBe(['failed/1', 'retrying/1', 'failed/1']);
});

it('opens the breaker, skips every call until the cool-down, and lets one probe decide', function () {
    [$workspace, $source, , $target] = sdReady();
    rbSettings(['tunables.circuit_breaker.failure_count' => '2', 'tunables.circuit_breaker.cool_down' => '60']);
    $governor = rbGovernor();
    $seen = [];
    rbMetrics($seen);
    $this->curl->queue = [FakeCurl::answer(500, '{}'), FakeCurl::answer(500, '{}')];

    rbAttempt($workspace, $target, 1);
    rbAttempt($workspace, $target, 2);
    $failed = sdTargets()[0];

    // Open: no call, a `skipped` run, and the target changes only by `applied_seq`.
    rbAttempt($workspace, $target, 3);
    $skipped = sdTargets()[0];

    expect(rbRunStatuses())->toBe(['failed/1', 'failed/1', 'skipped/1'])
        ->and(sdRuns()[2])->toMatchArray(['status' => 'skipped', 'error_code' => 'circuit-open', 'http_status' => null])
        ->and(count($this->curl->calls))->toBe(2)
        ->and($skipped)->toMatchArray(['applied_seq' => 3, 'consecutive_failures' => 2, 'last_checked_at' => $failed['last_checked_at']])
        ->and($seen)->toContain('dashflow.connector.circuit_opened')->toContain('dashflow.connector.fetch_skipped')->toContain('dashflow.connector.fetch_skipped_circuit_open');

    // Cool-down over: two jobs race, one wins the probe, the other is skipped. A failed probe reopens for another cool-down.
    $governor->now += 61;
    $limits = app(SyncSettings::class)->governorLimits();
    $this->curl->queue = [FakeCurl::answer(500, '{}')];
    $racer = $governor->admit($workspace, $source, $limits);
    expect($racer->probe)->toBeTrue();
    rbAttempt($workspace, $target, 4);
    expect(sdRuns()[3]['status'])->toBe('skipped')->and(count($this->curl->calls))->toBe(2);
    $governor->record($workspace, $source, CallOutcome::Failed, $limits, true);

    $governor->now += 61;
    $this->curl->queue = [FakeCurl::answer(500, '{}')];
    rbAttempt($workspace, $target, 5);
    expect(sdRuns()[4]['status'])->toBe('failed')->and(count($this->curl->calls))->toBe(3);
    rbAttempt($workspace, $target, 6);
    expect(sdRuns()[5])->toMatchArray(['status' => 'skipped', 'error_code' => 'circuit-open']);

    // A successful probe closes the breaker and calls flow again.
    $governor->now += 61;
    $this->curl->queue = [sdOk(), sdOk('{"x":2}')];
    rbAttempt($workspace, $target, 7);
    rbAttempt($workspace, $target, 8);
    expect(array_slice(rbRunStatuses(), 6))->toBe(['succeeded/1', 'succeeded/1'])->and($seen)->toContain('dashflow.connector.circuit_closed');
});

it('gives no retry to the half-open probe', function () {
    [$workspace, $source, , $target] = sdReady();
    rbRetryOn();
    rbSettings(['tunables.circuit_breaker.failure_count' => '1', 'tunables.circuit_breaker.cool_down' => '60']);
    $governor = rbGovernor();
    app()->instance(Jitter::class, new FixedJitter(0.0));
    rbDue($target);
    Queue::fake();
    $this->curl->queue = [FakeCurl::answer(500, '{}'), FakeCurl::answer(500, '{}')];
    $governor->breakers[$workspace.':'.$source] = ['state' => 'open', 'failures' => 1, 'open_until' => $governor->now - 1, 'probe_until' => 0.0];

    rbAttempt($workspace, $target, 1);

    expect(rbRunStatuses())->toBe(['failed/1']);
    Queue::assertNothingPushed();
    expect($governor->breakers[$workspace.':'.$source]['open_until'])->toBe($governor->now + 60);
});

it('records a rate-limited skip when the bucket is empty and nothing fits, and requeues with the governor wait when it fits', function () {
    [$workspace, , , $target] = sdReady();
    rbSettings(['tunables.budgets.max_fetch_rate_per_data_source' => '1']);
    rbGovernor();
    rbDue($target);
    Queue::fake();
    $seen = [];
    rbMetrics($seen);
    $this->curl->queue = [sdOk(), sdOk('{"x":2}')];

    rbAttempt($workspace, $target, 1);
    // No retry settings: a denied call is recorded and the target moves only by `applied_seq`.
    rbAttempt($workspace, $target, 2);

    expect(rbRunStatuses())->toBe(['succeeded/1', 'skipped/1'])
        ->and(sdRuns()[1]['error_code'])->toBe('rate-limited')
        ->and(sdTargets()[0])->toMatchArray(['applied_seq' => 2, 'consecutive_failures' => 0])
        ->and(count($this->curl->calls))->toBe(1)
        ->and($seen)->toContain('dashflow.connector.fetch_skipped_rate_limited');
    Queue::assertNothingPushed();

    // With retry settings the same denial is requeued with the wait the bucket reports, and is neither an attempt nor a run.
    rbRetryOn();
    rbAttempt($workspace, $target, 3);
    expect(rbRunStatuses())->toBe(['succeeded/1', 'skipped/1']);
    Queue::assertPushed(FetchJob::class, fn (FetchJob $job): bool => $job->attempt === 1 && $job->dispatchSeq === 3 && $job->delay === 60);

    // A wait that passes the next due time is a skip.
    rbDue($target, 30);
    rbAttempt($workspace, $target, 4);
    expect(rbRunStatuses())->toBe(['succeeded/1', 'skipped/1', 'skipped/1']);
});

it('caps the calls in flight per Data Source: a denied call waits retry.base, and the slot is released after a call', function () {
    [$workspace, $source, , $target] = sdReady();
    rbRetryOn();
    rbSettings(['fetch.data_source_concurrency' => '1']);
    $governor = rbGovernor();
    rbDue($target);
    Queue::fake();
    $this->curl->queue = [sdOk()];
    $key = $workspace.':'.$source;

    rbAttempt($workspace, $target, 1);
    expect($governor->inflight[$key])->toBe(0);

    $governor->inflight[$key] = 1;
    rbAttempt($workspace, $target, 2);

    expect(rbRunStatuses())->toBe(['succeeded/1'])->and(count($this->curl->calls))->toBe(1);
    Queue::assertPushed(FetchJob::class, fn (FetchJob $job): bool => $job->attempt === 1 && $job->dispatchSeq === 2 && $job->delay === 2);

    // Released in `finally` even when the fetch throws.
    $governor->inflight[$key] = 0;
    app()->instance(EndpointFetcher::class, new class implements EndpointFetcher
    {
        public function fetch(EndpointFetchSpec $spec): EndpointFetchResult
        {
            throw new RuntimeException('boom');
        }
    });
    expect(fn () => rbAttempt($workspace, $target, 3))->toThrow(RuntimeException::class)
        ->and($governor->inflight[$key])->toBe(0);
});

it('takes at most the fair share of targets per Workspace in one tick, and other Workspaces still dispatch', function () {
    [$workspaceA, $sourceA] = sdSetup();
    foreach (['/a', '/b', '/c'] as $path) {
        sdCreate($sourceA, ['path' => $path]);
    }
    [$workspaceB, $sourceB] = sdSetup();
    sdCreate($sourceB, ['path' => '/z']);
    sdRelay();
    expect(sdCount('sync_targets'))->toBe(4);
    Queue::fake();
    rbSettings(['fetch.workspace_fair_share' => '2']);

    expect(app(DispatchDueSyncs::class)->run())->toBe(3);
    $pushed = Queue::pushed(FetchJob::class);
    expect($pushed->where('workspaceId', $workspaceA)->count())->toBe(2)
        ->and($pushed->where('workspaceId', $workspaceB)->count())->toBe(1);

    // Unset: the whole backlog, as before.
    Cluster::superuser()->exec('update sync_targets set next_due_at = now() - interval \'1 second\'');
    rbSettings(['fetch.workspace_fair_share' => null]);
    expect(app(DispatchDueSyncs::class)->run())->toBe(4);
});

it('supersedes a late retry before any call', function () {
    [$workspace, , , $target] = sdReady();
    rbRetryOn();
    $governor = rbGovernor();
    rbDue($target);
    $this->curl->queue = [sdOk()];
    rbAttempt($workspace, $target, 2);

    rbAttempt($workspace, $target, 1, 2);

    expect(rbRunStatuses())->toBe(['succeeded/1', 'superseded/2'])
        ->and(count($this->curl->calls))->toBe(1)
        ->and($governor->admits)->toHaveCount(1);
});

it('records a failed run and counts one failure for a job that throws or is killed, under the fence', function () {
    [$workspace, , , $target] = sdReady();
    rbGovernor();
    app()->instance(EndpointFetcher::class, new class implements EndpointFetcher
    {
        public function fetch(EndpointFetchSpec $spec): EndpointFetchResult
        {
            throw new RuntimeException('boom CANARY-sd-91c3e7');
        }
    });

    // The synchronous queue hands the exception to `failed()`, as a worker does.
    expect(fn () => sdRun($workspace, $target, 1))->toThrow(RuntimeException::class);

    expect(sdRuns())->toHaveCount(1)
        ->and(sdRuns()[0])->toMatchArray(['status' => 'failed', 'error_code' => 'job-failed', 'dispatch_seq' => 1, 'attempt' => 1])
        ->and(sdRuns()[0]['request_id'])->not->toBeNull()
        ->and(sdTargets()[0])->toMatchArray(['consecutive_failures' => 1, 'applied_seq' => 1]);

    // A killed worker's job: the hook alone, once; a dispatch that has already been applied is not counted again.
    sdDispatched($target, 2);
    (new FetchJob($workspace, $target, 2, 1))->failed(new RuntimeException('killed'));
    (new FetchJob($workspace, $target, 2, 1))->failed(new RuntimeException('killed'));
    expect(array_column(sdRuns(), 'error_code'))->toBe(['job-failed', 'job-failed'])
        ->and(sdTargets()[0]['consecutive_failures'])->toBe(2);
    expect(json_encode(sdRuns()).(string) file_get_contents($this->logFile))->not->toContain(SD_CANARY);
});

it('admits the call, logs and does not crash when Valkey is down', function () {
    [$workspace, , , $target] = sdReady();
    rbRetryOn();
    rbSettings(['tunables.circuit_breaker.failure_count' => '2', 'tunables.circuit_breaker.cool_down' => '60', 'tunables.budgets.max_fetch_rate_per_data_source' => '5']);
    $redis = Mockery::mock(RedisFactory::class);
    $redis->shouldReceive('connection')->andThrow(new RuntimeException('down CANARY-sd-91c3e7'));
    app()->instance(SourceGovernor::class, new ValkeySourceGovernor($redis));
    $this->curl->queue = [sdOk()];

    sdRun($workspace, $target, 1);

    $log = (string) file_get_contents($this->logFile);
    expect(rbRunStatuses())->toBe(['succeeded/1'])
        ->and($log)->toContain('connector.governor.unavailable')->not->toContain(SD_CANARY);
});

it('keeps a secret out of runs, logs and metrics when it is in a Retry-After or a header', function () {
    [$workspace, , , $target] = sdReady();
    rbRetryOn();
    rbGovernor();
    app()->instance(Jitter::class, new FixedJitter(0.0));
    rbDue($target);
    Queue::fake();
    $seen = [];
    rbMetrics($seen);
    $this->curl->queue = [FakeCurl::answer(429, '{"e":"'.SD_CANARY.'"}', ['retry-after' => [SD_CANARY], 'x-secret' => [SD_CANARY]]), FakeCurl::answer(503, '', ['retry-after' => ['Thu, 01 Jan 2099 '.SD_CANARY]])];

    rbAttempt($workspace, $target, 1);
    rbAttempt($workspace, $target, 2, 2);

    $everything = json_encode([
        Cluster::rows(Cluster::superuser(), 'select * from sync_runs'), Cluster::rows(Cluster::superuser(), 'select * from audit_events'),
        Cluster::rows(Cluster::superuser(), 'select * from outbox_events'), $seen, (string) file_get_contents($this->logFile),
    ], JSON_THROW_ON_ERROR);

    expect($everything)->not->toContain(SD_CANARY)
        // An invalid Retry-After falls back to the jittered backoff.
        ->and(rbRunStatuses())->toBe(['retrying/1', 'retrying/2']);
});

it('records the final failure when the retry cannot be queued, under its own run id', function () {
    [$workspace, , , $target] = sdReady();
    rbRetryOn();
    rbGovernor();
    app()->instance(Jitter::class, new FixedJitter(0.0));
    rbDue($target);
    $bus = Mockery::mock(BusDispatcher::class);
    $bus->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));
    app()->instance(BusDispatcher::class, $bus);
    $this->curl->queue = [FakeCurl::answer(500, '{}')];

    rbAttempt($workspace, $target, 1);

    $runs = sdRuns();
    // Both rows share the start time of the call, so only the set of rows is asserted.
    $statuses = rbRunStatuses();
    sort($statuses);
    expect($statuses)->toBe(['failed/1', 'retrying/1'])
        ->and($runs[0]['id'])->not->toBe($runs[1]['id'])
        ->and(sdTargets()[0])->toMatchArray(['applied_seq' => 1, 'consecutive_failures' => 1]);
});

it('waits at least one second and penalises for at least one second on Retry-After 0', function () {
    [$workspace, $source, , $target] = sdReady();
    rbRetryOn();
    $governor = rbGovernor();
    app()->instance(Jitter::class, new FixedJitter(0.0));
    rbDue($target);
    Queue::fake();
    $this->curl->queue = [FakeCurl::answer(429, '{}', ['retry-after' => ['0']])];

    rbAttempt($workspace, $target, 1);

    Queue::assertPushed(FetchJob::class, fn (FetchJob $job): bool => $job->attempt === 2 && $job->delay === 1);
    expect($governor->penalties[$workspace.':'.$source] - $governor->now)->toBe(1.0);
});

it('counts a governor wait as a requeue, not a retry', function () {
    [$workspace, $source, , $target] = sdReady();
    rbRetryOn();
    rbSettings(['fetch.data_source_concurrency' => '1']);
    $governor = rbGovernor();
    $governor->inflight[$workspace.':'.$source] = 1;
    rbDue($target);
    Queue::fake();
    $seen = [];
    rbMetrics($seen);

    rbAttempt($workspace, $target, 1);

    expect($seen)->toContain('dashflow.connector.fetch_requeued')->not->toContain('dashflow.connector.fetch_retried');
});

it('gives the half-open probe lease and the concurrency slot back when the fetcher throws', function () {
    [$workspace, $source, , $target] = sdReady();
    rbSettings(['tunables.circuit_breaker.failure_count' => '1', 'tunables.circuit_breaker.cool_down' => '60', 'fetch.data_source_concurrency' => '1']);
    $governor = rbGovernor();
    $key = $workspace.':'.$source;
    $governor->breakers[$key] = ['state' => 'open', 'failures' => 1, 'open_until' => $governor->now - 1, 'probe_until' => 0.0];
    app()->instance(EndpointFetcher::class, new class implements EndpointFetcher
    {
        public function fetch(EndpointFetchSpec $spec): EndpointFetchResult
        {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => rbAttempt($workspace, $target, 1))->toThrow(RuntimeException::class);

    expect($governor->breakers[$key]['probe_until'])->toBe(0.0)
        ->and($governor->breakers[$key]['state'])->toBe('open')
        ->and($governor->inflight[$key])->toBe(0);
});
