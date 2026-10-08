<?php

use App\Models\User;
use App\Modules\Connector\Contracts\ConnectionTestCode;
use App\Modules\Connector\Contracts\EgressTransportFailed;
use App\Modules\Connector\Contracts\EndpointFetcher;
use App\Modules\Connector\Contracts\EndpointFetchResult;
use App\Modules\Connector\Contracts\EndpointFetchSpec;
use App\Modules\Connector\Contracts\HostResolver;
use App\Modules\Connector\Contracts\ResponseLimitExceeded;
use App\Modules\Connector\Infrastructure\CurlClient;
use App\Modules\Connector\Infrastructure\CurlResult;
use App\Modules\Ingestion\Application\DispatchDueSyncs;
use App\Modules\Ingestion\Application\FetchJob;
use App\Modules\Ingestion\Application\RegisterSyncTargets;
use App\Modules\Ingestion\Contracts\FetchKeyInput;
use App\Modules\Ingestion\Contracts\FetchKeyResolver;
use App\Modules\Ingestion\Contracts\SyncStatuses;
use App\Modules\RawStore\Contracts\RawPayload;
use App\Modules\RawStore\Contracts\RawStore;
use App\Platform\Outbox\OutboxEnvelope;
use App\Platform\Outbox\OutboxRelay;
use App\Platform\Tenancy\WorkspaceMismatchException;
use App\Platform\Tenancy\WorkspaceTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;
use Tests\Unit\Support\FakeCurl;
use Tests\Unit\Support\FakeResolver;

// Story 2.14 against the real PostgreSQL: a saved Endpoint revision becomes one sync target (through the outbox), the dispatcher
// (role `system`) fences each run with `dispatch_seq`, the `fetch-scheduled` job keeps the last good response as exact bytes plus an
// immutable observation, and a failed or late run changes nothing it must not. The queue is `sync`; the curl handler and the resolver are fakes.
const SD_HEADERS = ['Referer' => 'http://localhost:8000'];
const SD_CANARY = 'CANARY-sd-91c3e7';
const SD_BODY = '{"total":12345678901234567890.12,"rate":1.10}';

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
    expect($data['sync'])->toBe(['state' => 'not_scheduled', 'last_success_at' => null, 'reason' => 'test_values', 'missing_test_values' => ['from']])
        ->and($data['test_values'])->toBe(['to' => '2026-10-31']);

    // Saving the missing value makes the target.
    sdRevise($source, $endpoint, 1, ['params' => [['name' => 'from', 'binding' => 'date_range_from'], ['name' => 'to', 'binding' => 'date_range_to']], 'test_values' => ['from' => '2026-10-01', 'to' => '2026-10-31']])->assertOk();
    sdRelay();

    expect(sdTargets())->toHaveCount(1)
        ->and(test()->getJson("/api/v1/admin/data-sources/{$source}/endpoints/{$endpoint}", SD_HEADERS)->json('data.sync'))->toBe(['state' => 'waiting', 'last_success_at' => null, 'reason' => null, 'missing_test_values' => []]);
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
        ->and(test()->getJson("/api/v1/admin/data-sources/{$source}/endpoints/{$endpoint}", SD_HEADERS)->json('data.sync'))->toBe(['state' => 'not_scheduled', 'last_success_at' => null, 'reason' => 'user_context', 'missing_test_values' => []]);
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
        ->and($row)->toMatchArray(['current_payload_id' => $bodies[0]['id'], 'content_hash' => hash('sha256', SD_BODY), 'payload_seq' => 1, 'applied_seq' => 1, 'dispatch_seq' => 1, 'consecutive_failures' => 0])
        ->and($row['last_success_at'])->not->toBeNull()->and($row['last_checked_at'])->not->toBeNull();

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
        ->and($listed)->toBe(['state' => 'succeeded', 'last_success_at' => $status->lastSuccessAt, 'reason' => null, 'missing_test_values' => []])
        ->and($status->lastSuccessAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

/** What the source answers when it is down, by kind. */
function sdDown(string $kind): CurlResult|Throwable
{
    return match ($kind) {
        '500' => FakeCurl::answer(500, '{"error":"down"}'),
        'html' => FakeCurl::answer(200, '<html>maintenance</html>', ['content-type' => ['text/html']]),
        'truncated' => FakeCurl::answer(200, '{"total":'),
        'timeout' => new EgressTransportFailed(28),
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
    'a refused login' => ['refused', 'fetch-failed'],
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

it('stores the same bytes of a target once, with an observation for each response', function () {
    [$workspace, , , $target] = sdReady();
    $this->curl->queue = [FakeCurl::answer(200, SD_BODY), FakeCurl::answer(200, SD_BODY)];

    sdRun($workspace, $target, 1);
    sdRun($workspace, $target, 2);

    $observations = Cluster::rows(Cluster::superuser(), 'select seq, dispatch_seq, payload_id from raw_observations order by seq');
    expect(sdCount('raw_bodies'))->toBe(1)
        ->and($observations)->toHaveCount(2)
        ->and(array_column($observations, 'seq'))->toBe([1, 2])
        ->and($observations[0]['payload_id'])->toBe($observations[1]['payload_id'])
        ->and(sdTargets()[0]['payload_seq'])->toBe(2);
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
        ->and(test()->getJson("/api/v1/admin/data-sources/{$source}/endpoints", SD_HEADERS)->json('data.0.sync'))->toBe(['state' => 'waiting', 'last_success_at' => null, 'reason' => null, 'missing_test_values' => []]);
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
        ->and(sdRuns()[0])->toMatchArray(['status' => 'failed', 'error_code' => 'fetch-failed'])
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
