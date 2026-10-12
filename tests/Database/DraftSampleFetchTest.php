<?php

use App\Modules\Blocks\Application\PurgeDiscardedDrafts;
use App\Modules\Blocks\Application\PurgeDiscardedDraftsJob;
use App\Modules\Blocks\Contracts\ErrorCode as BlocksErrorCode;
use App\Modules\Connector\Contracts\HostResolver;
use App\Modules\Connector\Contracts\ResponseLimitExceeded;
use App\Modules\Connector\Infrastructure\CurlClient;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use App\Platform\Operations\Operations;
use App\Platform\Operations\RunOperation;
use App\Platform\Tenancy\TenantKey;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;
use Tests\Unit\Support\FakeCurl;
use Tests\Unit\Support\FakeResolver;

// Story 3.9 against the real PostgreSQL: Fetch from API in wizard step 2. The request starts a `draft_sample_fetch` Operation with the Draft's revision
// as its `subject_revision`; `worker-connector` runs it through the guard (the machinery of Story 2.10), the body travels only as the Operation's
// encrypted Valkey blob and the Operation's Blocks handler writes `draft_samples` (origin fetched) with the sealed storage, limits and shape of a paste.
// Helpers: sf* (SampleFetchTest, the Endpoint and the Operation reads), bd* (DraftBlocksTest, the Draft) and spl* (DraftSampleTest).
const DSF_BODY = '{"data":{"monthly":[{"month":"Jan","revenue":12345678901234567890.10,"rate":1.0},{"month":"Feb","revenue":2.50,"rate":1.10},{"month":"Mar","revenue":3,"rate":null}]},"note":"CANARY-dsf-body-7c2e"}';
const DSF_CANARY = 'CANARY-dsf-7c2e';

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();

    $this->keyFile = tempnam(sys_get_temp_dir(), 'dashflow-data-key');
    file_put_contents($this->keyFile, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    config([
        'dashflow.secrets.data_key_path.value' => $this->keyFile,
        'dashflow.tunables.guards.max_bytes.value' => '5000',
        'dashflow.tunables.guards.depth_limit.value' => '10',
        'dashflow.tunables.guards.platform_timeout_ceiling.value' => '300',
        'dashflow.tunables.timeouts.draft_sample_ttl.value' => '600',
        'dashflow.tunables.timeouts.edit_lock_ttl.value' => null,
        'dashflow.sample_fetch.membership_limit.value' => '100',
        'dashflow.sample_fetch.workspace_limit.value' => null,
        'dashflow.sample_fetch.window.value' => '60',
    ]);

    $this->logFile = tempnam(sys_get_temp_dir(), 'dashflow-log');
    config(['logging.default' => 'single', 'logging.channels.single.path' => $this->logFile]);

    $this->resolver = new FakeResolver(['api.example.com' => ['93.184.216.34'], 'blocked.example.com' => ['127.0.0.1'], 'other.example.com' => ['93.184.216.40']]);
    $this->curl = new FakeCurl;
    app()->instance(HostResolver::class, $this->resolver);
    app()->instance(CurlClient::class, $this->curl);
});

afterEach(function () {
    @unlink($this->keyFile);
    @unlink($this->logFile);
    $this->travelBack();
});

/**
 * A signed-in Admin who may build blocks and manage Data Sources, a Data Source, an Endpoint with a Fixed, a date-range and a period parameter,
 * and a Draft; returns [workspace, membership, data source, endpoint, draft].
 *
 * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
 */
function dsfWorld(array $endpoint = [], string $host = 'api.example.com'): array
{
    $workspace = Cluster::workspace('Acme');
    [, $membership] = sfAdmin($workspace, ['data_sources.manage', 'blocks.edit']);
    $source = Cluster::seedDataSource($workspace, 'Sales API', $host);
    $endpointId = sfEndpoint($source, $endpoint + [
        'path' => '/revenue',
        'params' => [
            ['name' => 'region', 'binding' => 'fixed', 'value' => 'EU'],
            ['name' => 'from', 'binding' => 'date_range_from'],
            ['name' => 'to', 'binding' => 'date_range_to'],
        ],
        'headers' => [],
    ]);
    $draft = bdCreate()->assertCreated()->json('data.draft_id');

    return [$workspace, $membership, $source, $endpointId, $draft];
}

function dsfUrl(string $draft): string
{
    return splUrl($draft, '/fetch');
}

/** The body of a fetch request: the Data Source, the Endpoint, the Default date range choice and the acknowledgement. */
function dsfBody(string $source, string $endpoint, array $overrides = []): array
{
    return $overrides + [
        'data_source_id' => $source,
        'endpoint_id' => $endpoint,
        'bindings' => [],
        'default_date_range' => ['preset' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'],
        'request_key' => '["ds","ep",[],"this_month",null]',
        'contains_personal_data' => true,
        'lock_epoch' => 1,
    ];
}

function dsfFetch(string $draft, string $source, string $endpoint, array $overrides = [])
{
    return test()->postJson(dsfUrl($draft), dsfBody($source, $endpoint, $overrides), BD_HEADERS);
}

/** Starts a fetch that is accepted and returns the Operation ID (the queue is `sync`: it has run when this returns). */
function dsfRun(string $draft, string $source, string $endpoint, array $overrides = []): string
{
    return (string) dsfFetch($draft, $source, $endpoint, $overrides)->assertStatus(202)->json('data.operation_id');
}

/** Everything a fetch could have leaked into, as one string (the sealed sample row is the one place the body may be, and it is sealed). */
function dsfEverything(): string
{
    return json_encode([
        Cluster::rows(Cluster::superuser(), 'select * from operations'),
        Cluster::rows(Cluster::superuser(), 'select * from sync_runs'),
        Cluster::rows(Cluster::superuser(), 'select * from audit_events'),
        Cluster::rows(Cluster::superuser(), 'select * from outbox_events'),
        Cluster::rows(Cluster::superuser(), 'select id, request_key, fetch_status, fetch_latency_ms, sample_revision, size_bytes, source from draft_samples'),
        Cluster::rows(Cluster::superuser(), 'select * from block_versions'),
        (string) file_get_contents(test()->logFile),
    ], JSON_THROW_ON_ERROR);
}

it('starts a draft_sample_fetch Operation for the Draft revision, runs it through the guard and stores the sample as origin fetched, sealed', function () {
    [$workspace, $membership, $source, $endpoint, $draft] = dsfWorld();
    $this->curl->queue = [FakeCurl::answer(200, DSF_BODY)];
    $revision = Cluster::rows(Cluster::superuser(), 'select revision from block_versions where id = ?', [$draft])[0]['revision'];

    $started = dsfFetch($draft, $source, $endpoint, ['bindings' => ['region' => 'US']])->assertStatus(202)->assertHeader('Cache-Control', 'no-store, private');
    $id = $started->json('data.operation_id');

    expect(Str::isUuid($id))->toBeTrue()
        ->and($started->json('data.draft_revision'))->toBe($revision)
        ->and($started->json('data.status'))->toBe('queued');

    $operation = Cluster::rows(Cluster::superuser(), 'select * from operations where id = ?', [$id])[0];
    expect($operation)->toMatchArray(['kind' => 'draft_sample_fetch', 'status' => 'succeeded', 'subject_type' => 'draft', 'subject_id' => $draft, 'subject_revision' => $revision, 'requester_membership_id' => $membership])
        // The lifetime is the platform timeout ceiling.
        ->and(strtotime($operation['expires_at']) - strtotime($operation['created_at']))->toBeBetween(299, 301);

    // One GET through the guard: the Fixed value from the block, the dates from the range.
    expect($this->curl->calls)->toHaveCount(1)
        ->and($this->curl->calls[0][CURLOPT_URL])->toBe('https://api.example.com/revenue?region=US&from=2026-01-01&to=2026-01-31')
        ->and($this->curl->calls[0][CURLOPT_RESOLVE])->toBe(['api.example.com:443:93.184.216.34']);

    // The summary: the Test endpoint's, plus the Draft revision, the sample revision and where the records are. No body.
    $summary = sfPoll($id)->assertOk()->json('data');
    expect($summary['status'])->toBe('succeeded')
        ->and($summary['kind'])->toBe('draft_sample_fetch')
        ->and($summary['result'])->toMatchArray(['ok' => true, 'status' => 200, 'code' => null, 'host' => 'api.example.com', 'draft_revision' => $revision, 'sample_revision' => 1, 'records' => 3, 'records_path' => 'data.monthly[]'])
        ->and($summary['result']['latency_ms'])->toBeInt()
        ->and(json_encode($summary))->not->toContain('CANARY');

    // The row: origin fetch, sealed, with the status, the latency and the request key; the sample is the Draft's current one.
    $rows = splRows();
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['workspace_id' => $workspace, 'block_version_id' => $draft, 'source' => 'fetch', 'contains_personal_data' => true, 'size_bytes' => strlen(DSF_BODY), 'sample_revision' => 1, 'fetch_status' => 200, 'request_key' => '["ds","ep",[],"this_month",null]'])
        ->and($rows[0]['fetch_latency_ms'])->not->toBeNull()
        ->and($rows[0]['body'])->not->toContain(DSF_CANARY)->not->toContain('12345678901234567890')
        ->and(strtotime($rows[0]['expires_at']) - strtotime($rows[0]['created_at']))->toBe(600);

    $read = $this->getJson(splUrl($draft), BD_HEADERS)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $nodes = array_column($read->json('data.shape.nodes'), null, 'path');
    expect($read->json('data'))->toMatchArray(['origin' => 'fetched', 'text' => DSF_BODY, 'sample_revision' => 1, 'fetch_status' => 200, 'request_key' => '["ds","ep",[],"this_month",null]', 'valid' => true])
        ->and($read->json('data.shape.records'))->toBe(['path' => '$.data.monthly[*]', 'display' => 'data.monthly[]', 'count' => 3])
        ->and($nodes['$.data.monthly[*].revenue']['example'])->toBe('12345678901234567890.10')
        ->and($nodes['$.data.monthly[*].rate']['example'])->toBe('1.0');

    // The blob was handed over and dropped; the body is in no other place.
    expect(Cache::get(TenantKey::cache($workspace, 'sample:'.$id)))->toBeNull()
        ->and(dsfEverything())->not->toContain('CANARY-dsf-body')->not->toContain('12345678901234567890')
        ->and(sfCount('raw_bodies'))->toBe(0);
});

it('sends the Fixed parameter\'s own value, and a date-bound header and period parameter their dates', function () {
    [, , $source, $endpoint, $draft] = dsfWorld(['params' => [
        ['name' => 'id', 'binding' => 'fixed', 'value' => 'c-42'],
        ['name' => 'start', 'binding' => 'period_start'],
        ['name' => 'end', 'binding' => 'period_end'],
    ], 'headers' => [['name' => 'X-Day', 'binding' => 'date_range_to']], 'path' => '/customers/{id}']);
    $this->curl->queue = [FakeCurl::answer(200, '{"a":1}')];

    // A Block with its own period selector starts at its Default date range; the server derives both dates, none comes from the client.
    bdSave($draft, 1, 1, ['config' => ['display' => ['period' => 'own_selector']]])->assertOk();
    dsfRun($draft, $source, $endpoint, ['default_date_range' => ['preset' => 'custom', 'from' => '2026-02-01', 'to' => '2026-02-28']]);

    expect($this->curl->calls[0][CURLOPT_URL])->toBe('https://api.example.com/customers/c-42?start=2026-02-01&end=2026-02-28')
        ->and($this->curl->calls[0][CURLOPT_HTTPHEADER])->toContain('X-Day: 2026-02-28');
});

it('replaces the sample with a re-fetch and raises sample_revision, and a paste raises it too', function () {
    [, , $source, $endpoint, $draft] = dsfWorld();
    $this->curl->queue = [FakeCurl::answer(200, '{"a":1}'), FakeCurl::answer(200, '{"b":[{"x":2}]}')];

    dsfRun($draft, $source, $endpoint);
    $first = splRows();
    $second = dsfRun($draft, $source, $endpoint, ['request_key' => 'second']);

    $rows = splRows();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe($first[0]['id'])
        ->and($rows[0])->toMatchArray(['sample_revision' => 2, 'request_key' => 'second', 'source' => 'fetch'])
        ->and(sfPoll($second)->json('data.result.sample_revision'))->toBe(2);
    $this->getJson(splUrl($draft), BD_HEADERS)->assertOk()->assertJsonPath('data.text', '{"b":[{"x":2}]}')->assertJsonPath('data.shape.records.display', 'b[]')->assertJsonPath('data.sample_revision', 2);

    // A paste replaces a fetched sample, raises the revision and is a pasted sample again.
    splPut($draft, '{"c":3}')->assertOk()->assertJsonPath('data.origin', 'pasted')->assertJsonPath('data.sample_revision', 3)->assertJsonPath('data.request_key', null)->assertJsonPath('data.fetch_status', null);
    expect(splRows()[0])->toMatchArray(['source' => 'paste', 'sample_revision' => 3, 'request_key' => null, 'fetch_status' => null, 'fetch_latency_ms' => null]);
});

it('stores the sample against the key of the request when the Draft revision moved while the fetch ran', function () {
    Queue::fake();
    [$workspace, , $source, $endpoint, $draft] = dsfWorld();
    $this->curl->queue = [FakeCurl::answer(200, '{"a":[1,2]}')];

    $id = dsfRun($draft, $source, $endpoint, ['request_key' => 'earlier-parameters']);
    // The Admin edits the Draft: its revision moves on before the worker gets to the job.
    bdSave($draft, 1, 1, ['name' => 'Renamed'])->assertOk();

    app(WorkspaceTransaction::class)->runJob(Queue::pushed(RunOperation::class)->first(), fn ($job) => $job->handle(app(Operations::class)));

    $summary = sfPoll($id)->json('data');
    expect($summary['status'])->toBe('succeeded')
        ->and($summary['result'])->toMatchArray(['ok' => true, 'draft_revision' => 1, 'sample_revision' => 1])
        ->and(splRows()[0])->toMatchArray(['request_key' => 'earlier-parameters', 'sample_revision' => 1]);
    // The Draft is at revision 2 and the stored sample says which request it belongs to: the wizard compares that with its own.
    $this->getJson(splUrl($draft), BD_HEADERS)->assertOk()->assertJsonPath('data.request_key', 'earlier-parameters');
    expect(Cluster::rows(Cluster::superuser(), 'select revision from block_versions where id = ?', [$draft])[0]['revision'])->toBe(2);
});

it('maps each failure to its user code, stores nothing and leaves an earlier sample alone', function (string $host, array $curl, string $code, string $reason, ?int $status) {
    [, , $source, $endpoint, $draft] = dsfWorld(host: $host);
    splPut($draft, '{"earlier":"sample"}')->assertOk();
    $this->curl->queue = $curl;

    $id = dsfRun($draft, $source, $endpoint);
    $summary = sfPoll($id)->json('data');

    expect($summary['status'])->toBe('failed')
        ->and($summary['result'])->toMatchArray(['ok' => false, 'code' => $code, 'reason' => $reason, 'status' => $status, 'sample_revision' => null])
        ->and(splRows())->toHaveCount(1)
        ->and(splRows()[0])->toMatchArray(['source' => 'paste', 'sample_revision' => 1])
        ->and(dsfEverything())->not->toContain('CANARY-dsf-body');
    $this->getJson(splUrl($draft), BD_HEADERS)->assertOk()->assertJsonPath('data.text', '{"earlier":"sample"}');
})->with([
    'host not on the allowlist' => ['other.example.com', [], 'host-not-allowlisted', 'host_not_allowlisted', null],
    'a blocked address' => ['blocked.example.com', [], 'blocked-address', 'blocked_address', null],
    'not JSON' => ['api.example.com', [FakeCurl::answer(200, '<html>CANARY-dsf-body</html>', ['content-type' => ['text/html']])], 'not-json', 'connector.not_json:content_type', 200],
    'JSON that does not parse' => ['api.example.com', [FakeCurl::answer(200, '{"a":CANARY-dsf-body}')], 'not-json', 'connector.not_json:parse', 200],
    'a server error' => ['api.example.com', [FakeCurl::answer(500, '{"error":"CANARY-dsf-body"}')], 'fetch-failed', 'http_500', 500],
]);

it('answers a response over the limit with response-too-large and stores no sample, truncated or not', function () {
    [, , $source, $endpoint, $draft] = dsfWorld();
    $this->curl->queue = [new ResponseLimitExceeded(4097, 4096, 200)];

    $id = dsfRun($draft, $source, $endpoint);

    expect(sfPoll($id)->json('data.result'))->toMatchArray(['ok' => false, 'code' => 'response-too-large', 'reason' => 'connector.limit_exceeded', 'size_bytes' => 4097, 'limit_bytes' => 4096])
        ->and(splRows())->toBe([]);
});

it('does not store a response that passes the transport but is over the platform byte limit of a sample', function () {
    [, , $source, $endpoint, $draft] = dsfWorld();
    config(['dashflow.tunables.guards.max_bytes.value' => '40']);
    $this->curl->queue = [FakeCurl::answer(200, '{"a":"'.str_repeat('x', 100).'"}')];

    $id = dsfRun($draft, $source, $endpoint);
    $summary = sfPoll($id)->json('data');

    // Nothing is stored and the Admin is told it was too large, never given a truncated sample.
    expect($summary['status'])->toBe('failed')
        ->and($summary['result']['code'])->toBe('response-too-large')
        ->and($summary['result']['limit_bytes'])->toBe(40)
        ->and(splRows())->toBe([]);
});

it('fails with fetch-failed and blob_unavailable when the data key is unusable, and stores nothing', function () {
    [, , $source, $endpoint, $draft] = dsfWorld();
    file_put_contents($this->keyFile, 'placeholder-not-a-real-key');
    $this->curl->queue = [FakeCurl::answer(200, DSF_BODY)];

    $id = dsfRun($draft, $source, $endpoint);

    expect(sfPoll($id)->json('data'))->toMatchArray(['status' => 'failed'])
        ->and(sfPoll($id)->json('data.result'))->toMatchArray(['ok' => false, 'code' => 'fetch-failed', 'reason' => 'blob_unavailable'])
        ->and(splRows())->toBe([])
        ->and(dsfEverything())->not->toContain('CANARY-dsf-body');
});

it('answers 429 with retry_after in the envelope and a Retry-After header, enqueues nothing and counts per membership', function () {
    Queue::fake();
    [, , $source, $endpoint, $draft] = dsfWorld();
    config(['dashflow.sample_fetch.membership_limit.value' => '2', 'dashflow.sample_fetch.window.value' => '60']);

    dsfFetch($draft, $source, $endpoint)->assertStatus(202);
    dsfFetch($draft, $source, $endpoint)->assertStatus(202);
    $third = dsfFetch($draft, $source, $endpoint)->assertStatus(429)->assertHeader('Retry-After');

    expect($third->json('error.code'))->toBe(PlatformErrorCode::TooManyRequests->value)
        ->and($third->json('error.retry_after'))->toBeBetween(1, 60)
        ->and($third->json('reason'))->toBe('sample-fetch-throttled')
        ->and(sfCount('operations'))->toBe(2);
    Queue::assertPushed(RunOperation::class, 2);
});

it('refuses with a reason naming the setting when the rate limit, its window, the timeout ceiling, the sample TTL or a limit is unset: no Operation, no request', function (string $path, mixed $value, string $reason, ?string $setting) {
    Queue::fake();
    [, , $source, $endpoint, $draft] = dsfWorld();
    config([$path => $value]);

    $response = dsfFetch($draft, $source, $endpoint)->assertStatus(422);

    expect($response->json('error.code'))->toBe(BlocksErrorCode::SampleInvalid->value)
        ->and($response->json('reason'))->toBe($reason)
        ->and($response->json('setting'))->toBe($setting)
        ->and(sfCount('operations'))->toBe(0)
        ->and($this->curl->calls)->toBe([])
        ->and(splRows())->toBe([]);
    Queue::assertNothingPushed();
})->with([
    'membership limit unset' => ['dashflow.sample_fetch.membership_limit.value', null, 'setting_not_configured', 'DASHFLOW_SAMPLE_FETCH_MEMBERSHIP_LIMIT'],
    'window unset' => ['dashflow.sample_fetch.window.value', null, 'setting_not_configured', 'DASHFLOW_SAMPLE_FETCH_WINDOW_SECONDS'],
    'timeout ceiling unset' => ['dashflow.tunables.guards.platform_timeout_ceiling.value', null, 'setting_not_configured', 'DASHFLOW_GUARD_PLATFORM_TIMEOUT_CEILING'],
    'timeout ceiling malformed' => ['dashflow.tunables.guards.platform_timeout_ceiling.value', '5m', 'setting_not_configured', 'DASHFLOW_GUARD_PLATFORM_TIMEOUT_CEILING'],
    'sample TTL unset' => ['dashflow.tunables.timeouts.draft_sample_ttl.value', null, 'ttl_not_configured', null],
    'byte limit unset' => ['dashflow.tunables.guards.max_bytes.value', null, 'limit_not_configured', null],
    'depth limit unset' => ['dashflow.tunables.guards.depth_limit.value', null, 'limit_not_configured', null],
]);

it('returns 404 for every other member: initiator-only Operations', function () {
    Queue::fake();
    [$workspace, , $source, $endpoint, $draft] = dsfWorld();
    $id = dsfRun($draft, $source, $endpoint);
    sfPoll($id)->assertOk();

    [$user] = bdMember($workspace, 'grace@example.test', 'admin', ['blocks.edit', 'data_sources.manage']);
    sfSignIn($user, $workspace);

    sfPoll($id)->assertNotFound();
    expect(sfPoll($id)->getContent())->toBe('');
});

it('answers 404 for a Draft of another Workspace, a missing one and a discarded one, and starts nothing', function () {
    Queue::fake();
    [, , $source, $endpoint, $draft] = dsfWorld();
    $foreign = Cluster::seedBlockVersion(Cluster::workspace('Other'));

    foreach ([$foreign, (string) Str::uuid7()] as $missing) {
        dsfFetch($missing, $source, $endpoint)->assertNotFound();
    }

    $this->deleteJson(BD_URL."/{$draft}", [], BD_HEADERS)->assertOk();
    dsfFetch($draft, $source, $endpoint)->assertNotFound();

    expect(sfCount('operations'))->toBe(0);
    Queue::assertNotPushed(RunOperation::class);
});

it('refuses an Endpoint or Data Source of another Workspace or one that does not exist, starting nothing', function () {
    Queue::fake();
    [, , $source, $endpoint, $draft] = dsfWorld();
    $other = Cluster::workspace('Other');
    $foreignSource = Cluster::seedDataSource($other, 'Foreign');
    $foreignEndpoint = Cluster::seedEndpoint($other, $foreignSource);

    foreach ([[$foreignSource, $foreignEndpoint], [$source, (string) Str::uuid7()], [(string) Str::uuid7(), $endpoint], [$source, $foreignEndpoint]] as [$s, $e]) {
        dsfFetch($draft, $s, $e)->assertStatus(422)->assertJsonPath('error.code', BlocksErrorCode::SampleInvalid->value)->assertJsonPath('reason', 'endpoint_not_found');
    }

    expect(sfCount('operations'))->toBe(0);
    Queue::assertNothingPushed();
});

it('writes only with the edit-lock claim: a stale epoch or a token that does not hold the lock is a 423 and nothing is started or counted', function () {
    Queue::fake();
    config(['dashflow.tunables.timeouts.edit_lock_ttl.value' => '60']);
    [, , $source, $endpoint, $draft] = dsfWorld();
    $lock = $this->postJson(BD_URL."/{$draft}/lock", [], BD_HEADERS)->assertOk()->json('data');

    dsfFetch($draft, $source, $endpoint)->assertStatus(423)->assertJsonPath('error.code', PlatformErrorCode::EditLockLost->value);
    dsfFetch($draft, $source, $endpoint, ['lock_token' => str_repeat('a', 32)])->assertStatus(423);
    dsfFetch($draft, $source, $endpoint, ['lock_token' => $lock['token'], 'lock_epoch' => 7])->assertStatus(423);
    expect(sfCount('operations'))->toBe(0);

    dsfFetch($draft, $source, $endpoint, ['lock_token' => $lock['token']])->assertStatus(202);
    expect(sfCount('operations'))->toBe(1);
});

it('ends the Operation as stale and stores nothing when the edit lock was taken over while the fetch ran', function () {
    Queue::fake();
    [, , $source, $endpoint, $draft] = dsfWorld();
    $this->curl->queue = [FakeCurl::answer(200, DSF_BODY)];

    $id = dsfRun($draft, $source, $endpoint);
    Cluster::superuser()->prepare('UPDATE block_versions SET lock_epoch = lock_epoch + 1 WHERE id = ?')->execute([$draft]);
    app(WorkspaceTransaction::class)->runJob(Queue::pushed(RunOperation::class)->first(), fn ($job) => $job->handle(app(Operations::class)));

    $summary = sfPoll($id)->json('data');
    expect($summary['status'])->toBe('stale')
        ->and($summary['result'])->toMatchArray(['ok' => false, 'reason' => 'lock_lost'])
        ->and(splRows())->toBe([])
        ->and(Cache::get(TenantKey::cache(Cluster::rows(Cluster::superuser(), 'select workspace_id from operations')[0]['workspace_id'], 'sample:'.$id)))->toBeNull();
});

it('requires the personal-data acknowledgement before anything is queued', function () {
    Queue::fake();
    [, , $source, $endpoint, $draft] = dsfWorld();

    dsfFetch($draft, $source, $endpoint, ['contains_personal_data' => false])->assertStatus(422)->assertJsonPath('reason', 'acknowledgement_required');
    $this->postJson(dsfUrl($draft), array_diff_key(dsfBody($source, $endpoint), ['contains_personal_data' => 1]), BD_HEADERS)->assertStatus(422);

    expect(sfCount('operations'))->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses a missing, malformed or unsafe value with a 422 naming the parameter, and queues and sends nothing', function (array $overrides, string $field, string $reason) {
    Queue::fake();
    [, , $source, $endpoint, $draft] = dsfWorld(['path' => '/revenue/{region}', 'params' => [
        ['name' => 'region', 'binding' => 'fixed', 'value' => 'EU'],
        ['name' => 'from', 'binding' => 'date_range_from'],
    ]]);

    $response = dsfFetch($draft, $source, $endpoint, $overrides)->assertStatus(422);

    expect($response->json('error.code'))->toBe(PlatformErrorCode::ValidationFailed->value)
        ->and(array_keys($response->json('errors')))->toContain("values.{$field}")
        ->and($response->json('reasons')["values.{$field}"])->toBe($reason)
        ->and(json_encode($response->json()))->not->toContain('CANARY')
        ->and(sfCount('operations'))->toBe(0)
        ->and($this->curl->calls)->toBe([]);
    Queue::assertNothingPushed();
})->with([
    'no range for a date parameter' => [['default_date_range' => null], 'from', 'param-value-required'],
    'a slash in a path value' => [['bindings' => ['region' => 'a/b-CANARY']], 'region', 'param-value-invalid'],
    'a dot segment' => [['bindings' => ['region' => '..']], 'region', 'param-value-invalid'],
    'a control character' => [['bindings' => ['region' => "e\x07CANARY"]], 'region', 'param-value-invalid'],
]);

it('refuses an Endpoint that needs user context with the refusal of Test endpoint, and a POST that is not read-only', function () {
    Queue::fake();
    [, , $source, $endpoint, $draft] = dsfWorld(['params' => [
        ['name' => 'who', 'binding' => 'user_email'],
        ['name' => 'from', 'binding' => 'date_range_from'],
    ]]);

    dsfFetch($draft, $source, $endpoint)->assertStatus(422)->assertJsonPath('reasons', ['endpoint' => 'requires-user-context']);
    expect(sfCount('operations'))->toBe(0);
    Queue::assertNothingPushed();
});

it('ends the Operation as expired with nothing stored when it is past its lifetime before it runs', function () {
    Queue::fake();
    [, , $source, $endpoint, $draft] = dsfWorld();

    $id = dsfRun($draft, $source, $endpoint);
    Cluster::superuser()->prepare("UPDATE operations SET expires_at = now() - interval '1 second'")->execute();
    app(WorkspaceTransaction::class)->runJob(Queue::pushed(RunOperation::class)->first(), fn ($job) => $job->handle(app(Operations::class)));

    expect(sfPoll($id)->json('data.status'))->toBe('expired')
        ->and($this->curl->calls)->toBe([])
        ->and(splRows())->toBe([]);
});

it('stores no partial sample when the platform timeout ceiling passes while the fetch runs: the Operation reads as expired', function () {
    Queue::fake();
    [, , $source, $endpoint, $draft] = dsfWorld();
    config(['dashflow.tunables.guards.platform_timeout_ceiling.value' => '30']);
    $this->curl->queue = [FakeCurl::answer(200, DSF_BODY)];

    $id = dsfRun($draft, $source, $endpoint);
    // The response comes back after the ceiling.
    $this->travel(31)->seconds();
    app(WorkspaceTransaction::class)->runJob(Queue::pushed(RunOperation::class)->first(), fn ($job) => $job->handle(app(Operations::class)));
    $this->travelBack();

    expect(splRows())->toBe([])
        ->and(sfPoll($id)->json('data.result'))->toMatchArray(['ok' => false, 'reason' => 'timeout'])
        ->and(Cache::get(TenantKey::cache(Cluster::rows(Cluster::superuser(), 'select workspace_id from operations')[0]['workspace_id'], 'sample:'.$id)))->toBeNull();
});

it('needs blocks.edit: another permission, a User-area session and no permission get 403 and start nothing', function () {
    Queue::fake();
    [$workspace, , $source, $endpoint, $draft] = dsfWorld();
    $this->flushSession();
    bdAdmin($workspace, ['data_sources.manage'], 'nobody@example.test');

    dsfFetch($draft, $source, $endpoint)->assertForbidden();
    expect(sfCount('operations'))->toBe(0);
    Queue::assertNothingPushed();
});

it('keeps a value, the body and the request out of the audit trail, the outbox, sync_runs, the operations and the log', function () {
    [, , $source, $endpoint, $draft] = dsfWorld();
    $this->curl->queue = [FakeCurl::answer(200, DSF_BODY)];

    dsfRun($draft, $source, $endpoint, ['bindings' => ['region' => 'r-'.DSF_CANARY]]);

    expect($this->curl->calls[0][CURLOPT_URL])->toContain('r-'.DSF_CANARY)
        ->and(dsfEverything())->not->toContain(DSF_CANARY)->not->toContain('CANARY-dsf-body');
});

it('deletes a fetched sample on discard like a pasted one: unreadable at once, the row gone after the purge', function () {
    [$workspace, , $source, $endpoint, $draft] = dsfWorld();
    $this->curl->queue = [FakeCurl::answer(200, '{"a":"'.DSF_CANARY.'"}')];
    dsfRun($draft, $source, $endpoint);
    Queue::fake();

    $this->deleteJson(BD_URL."/{$draft}", [], BD_HEADERS)->assertOk();
    expect(splRows()[0])->toMatchArray(['body' => '', 'size_bytes' => 0]);
    $this->getJson(splUrl($draft), BD_HEADERS)->assertNotFound();

    (new PurgeDiscardedDraftsJob($workspace))->handle(app(PurgeDiscardedDrafts::class));
    expect(splRows())->toBe([]);
});

it('throttles the fetch route', function () {
    [, , $source, $endpoint, $draft] = dsfWorld();
    Queue::fake();
    config(['dashflow.sample_fetch.membership_limit.value' => '1000']);

    foreach (range(1, 30) as $i) {
        dsfFetch($draft, $source, $endpoint)->assertStatus(202);
    }

    dsfFetch($draft, $source, $endpoint)->assertStatus(429);
});

it('gives the rate-limit slot back when the Operation could not be queued', function () {
    [, , $source, $endpoint, $draft] = dsfWorld();
    config(['dashflow.sample_fetch.membership_limit.value' => '1', 'dashflow.sample_fetch.window.value' => '60']);
    $pdo = Cluster::superuser();
    $pdo->exec('ALTER TABLE operations ADD CONSTRAINT dsf_block CHECK (false) NOT VALID');

    try {
        dsfFetch($draft, $source, $endpoint)->assertStatus(500);
    } finally {
        $pdo->exec('ALTER TABLE operations DROP CONSTRAINT dsf_block');
    }

    // Had the failed attempt kept its slot, this would be a 429.
    $this->curl->queue = [FakeCurl::answer(200, '{"a":1}')];
    dsfFetch($draft, $source, $endpoint)->assertStatus(202);
});

it('ends as failed with draft_gone when the Draft is discarded after the fetch was queued', function () {
    Queue::fake();
    [, , $source, $endpoint, $draft] = dsfWorld();
    $this->curl->queue = [FakeCurl::answer(200, '{"a":1}')];

    $id = dsfRun($draft, $source, $endpoint);
    $this->deleteJson(BD_URL."/{$draft}", [], BD_HEADERS)->assertOk();
    app(WorkspaceTransaction::class)->runJob(Queue::pushed(RunOperation::class)->first(), fn ($job) => $job->handle(app(Operations::class)));

    expect(sfPoll($id)->json('data.status'))->toBe('failed')
        ->and(sfPoll($id)->json('data.result'))->toMatchArray(['ok' => false, 'reason' => 'draft_gone'])
        ->and(splRows()[0]['body'] ?? '')->toBe('');
});

it('maps a body the stricter lossless check refuses to its user code and stores nothing', function (string $setting, string $value, string $body, string $code) {
    [, , $source, $endpoint, $draft] = dsfWorld();
    config([$setting => $value]);
    $this->curl->queue = [FakeCurl::answer(200, $body)];

    $id = dsfRun($draft, $source, $endpoint);

    expect(sfPoll($id)->json('data.status'))->toBe('failed')
        ->and(sfPoll($id)->json('data.result.ok'))->toBeFalse()
        ->and(sfPoll($id)->json('data.result.code'))->toBeIn([$code, 'not-json', 'response-too-large'])
        ->and(splRows())->toBe([]);
})->with([
    'too deep' => ['dashflow.tunables.guards.depth_limit.value', '2', '[[[1]]]', 'not-json'],
    'too large' => ['dashflow.tunables.guards.max_bytes.value', '10', '{"a":"xxxxxxxxxxxxxxxx"}', 'response-too-large'],
]);

it('ends as failed with store_failed, not a crash, when the sample cannot be written', function () {
    [, , $source, $endpoint, $draft] = dsfWorld();
    $this->curl->queue = [FakeCurl::answer(200, '{"a":1}')];
    $pdo = Cluster::superuser();
    $pdo->exec('ALTER TABLE draft_samples ADD CONSTRAINT dsf_block CHECK (false) NOT VALID');

    try {
        $id = dsfRun($draft, $source, $endpoint);
    } finally {
        $pdo->exec('ALTER TABLE draft_samples DROP CONSTRAINT dsf_block');
    }

    expect(sfPoll($id)->json('data.status'))->toBe('failed')
        ->and(sfPoll($id)->json('data.result'))->toMatchArray(['ok' => false, 'reason' => 'store_failed'])
        ->and(splRows())->toBe([]);
});

it('refuses resolved dates from a client, and a range that ends before it starts', function (array $overrides, string $field) {
    Queue::fake();
    [, , $source, $endpoint, $draft] = dsfWorld();

    dsfFetch($draft, $source, $endpoint, $overrides)->assertStatus(422)->assertJsonValidationErrors([$field]);
    expect(sfCount('operations'))->toBe(0);
})->with([
    'client range' => [['range' => ['from' => '2026-02-01', 'to' => '2026-02-28']], 'range'],
    'client period' => [['period' => ['start' => '2026-02-01', 'end' => '2026-02-28']], 'period'],
    'custom range backwards' => [['default_date_range' => ['preset' => 'custom', 'from' => '2026-02-01', 'to' => '2026-01-01']], 'default_date_range.to'],
]);

it('accepts a read-only POST Endpoint and sends it once', function () {
    [, , $source, $endpoint, $draft] = dsfWorld(['method' => 'POST', 'path' => '/search', 'read_only_query' => true, 'confirm_read_only' => true, 'body_template' => null]);
    $this->curl->queue = [FakeCurl::answer(200, '{"a":1}')];

    dsfRun($draft, $source, $endpoint);

    expect($this->curl->calls)->toHaveCount(1)->and($this->curl->calls[0][CURLOPT_CUSTOMREQUEST])->toBe('POST');
});
