<?php

use App\Models\User;
use App\Modules\Connector\Application\RunSampleFetch;
use App\Modules\Connector\Contracts\DataSourceActor;
use App\Modules\Connector\Contracts\EgressTransportFailed;
use App\Modules\Connector\Contracts\Endpoint;
use App\Modules\Connector\Contracts\EndpointInput;
use App\Modules\Connector\Contracts\EndpointPage;
use App\Modules\Connector\Contracts\Endpoints;
use App\Modules\Connector\Contracts\HostResolver;
use App\Modules\Connector\Contracts\ResponseLimitExceeded;
use App\Modules\Connector\Infrastructure\CurlClient;
use App\Modules\Connector\Infrastructure\CurlResult;
use App\Modules\Connector\Infrastructure\SampleBlobStore;
use App\Platform\Operations\Operation;
use App\Platform\Operations\OperationKinds;
use App\Platform\Operations\Operations;
use App\Platform\Operations\OperationStatus;
use App\Platform\Operations\RunOperation;
use App\Platform\Tenancy\TenantKey;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;
use Tests\Unit\Support\FakeCurl;
use Tests\Unit\Support\FakeResolver;

// Story 2.10 against the real PostgreSQL: an Admin with `data_sources.manage` runs an Endpoint with test values. The request
// starts a `sample_fetch` Operation (nothing is called from the web tier); `worker-connector`'s job renders the Endpoint's
// current revision, sends it through the guard and hands the body to the requester as an encrypted, short-lived cache entry
// that is never written to PostgreSQL. The queue is `sync` (the job runs right after the request commits); the curl handler
// and the resolver are fakes.
const SF_HEADERS = ['Referer' => 'http://localhost:8000'];
const SF_CANARY = 'CANARY-sf-4d1e07';
const SF_BODY = '{"total":12345678901234567890.12,"rate":1.10,"note":"CANARY-sf-body-4d1e07"}';

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();

    $this->keyFile = tempnam(sys_get_temp_dir(), 'dashflow-data-key');
    file_put_contents($this->keyFile, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    config(['dashflow.secrets.data_key_path.value' => $this->keyFile]);

    $pair = sodium_crypto_box_keypair();
    $this->credFile = tempnam(sys_get_temp_dir(), 'dashflow-key');
    file_put_contents($this->credFile, base64_encode(sodium_crypto_box_secretkey($pair)));
    config([
        'dashflow.secrets.cred_public_key.value' => base64_encode(sodium_crypto_box_publickey($pair)),
        'dashflow.secrets.cred_key_version.value' => '3',
        'dashflow.secrets.cred_key_path.value' => $this->credFile,
    ]);

    $this->logFile = tempnam(sys_get_temp_dir(), 'dashflow-log');
    config(['logging.default' => 'single', 'logging.channels.single.path' => $this->logFile]);

    $this->resolver = new FakeResolver(['api.example.com' => ['93.184.216.34'], 'blocked.example.com' => ['127.0.0.1'], 'other.example.com' => ['93.184.216.40'], 'auth.example.com' => ['93.184.216.50']]);
    $this->curl = new FakeCurl;
    app()->instance(HostResolver::class, $this->resolver);
    app()->instance(CurlClient::class, $this->curl);
});

afterEach(function () {
    @unlink($this->keyFile);
    @unlink($this->credFile);
    @unlink($this->logFile);
});

/** @return array{0: int, 1: string} the signed-in Admin's user ID and membership ID; api.example.com and blocked.example.com are allowlisted */
function sfAdmin(string $workspaceId, array $permissions = ['data_sources.manage'], string $email = 'ada@example.test'): array
{
    $user = Cluster::user($email);
    Cluster::superuser()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([Hash::make('admin-password-1'), $user]);
    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspaceId, $user, 'admin', 'active']);

    foreach ($permissions as $permission) {
        Cluster::superuser()->prepare('INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
            ->execute([(string) Str::uuid7(), $workspaceId, $membership, $permission]);
    }

    foreach (['api.example.com', 'blocked.example.com', 'auth.example.com'] as $host) {
        Cluster::superuser()->prepare('INSERT INTO host_allowlist_entries (id, workspace_id, host, scheme, port, added_by_membership_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, now(), now()) ON CONFLICT DO NOTHING')
            ->execute([(string) Str::uuid7(), $workspaceId, $host, 'https', 443, $membership]);
    }

    sfSignIn($user, $workspaceId);

    return [$user, $membership];
}

function sfSignIn(int $user, string $workspaceId): void
{
    auth()->forgetGuards();
    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspaceId, 'area' => 'admin']);
}

function sfEndpointBody(array $overrides = []): array
{
    return $overrides + [
        'method' => 'GET', 'path' => '/customers/{id}/revenue',
        'params' => [
            ['name' => 'id', 'binding' => 'fixed', 'value' => 'c-42'],
            ['name' => 'from', 'binding' => 'date_range_from'],
            ['name' => 'limit', 'binding' => 'fixed', 'value' => '10'],
        ],
        'headers' => [['name' => 'X-Team', 'binding' => 'fixed', 'value' => 'finance'], ['name' => 'X-Day', 'binding' => 'period_start']],
        'body_template' => null, 'read_only_query' => false, 'confirm_read_only' => false,
    ];
}

function sfEndpoint(string $source, array $overrides = []): string
{
    return test()->postJson("/api/v1/admin/data-sources/{$source}/endpoints", sfEndpointBody($overrides), SF_HEADERS)->assertCreated()->json('data.endpoint_id');
}

function sfValues(array $overrides = []): array
{
    return $overrides + ['from' => '2026-01-01', 'header:X-Day' => '2026-02-01'];
}

function sfUrl(string $source, string $endpoint, string $tail = 'test'): string
{
    return "/api/v1/admin/data-sources/{$source}/endpoints/{$endpoint}/{$tail}";
}

function sfTest(string $source, string $endpoint, array $values)
{
    return test()->postJson(sfUrl($source, $endpoint), ['values' => $values], SF_HEADERS);
}

function sfPoll(string $id)
{
    return test()->getJson('/api/v1/operations/'.$id, SF_HEADERS);
}

function sfSample(string $source, string $endpoint, string $operation)
{
    return test()->getJson(sfUrl($source, $endpoint, 'samples/'.$operation), SF_HEADERS);
}

/** @return list<array<string, mixed>> */
function sfRuns(): array
{
    return Cluster::rows(Cluster::superuser(), "select * from sync_runs where kind = 'sample_fetch' order by started_at, id");
}

function sfCount(string $table): int
{
    return Cluster::rows(Cluster::superuser(), "select count(*) as n from {$table}")[0]['n'];
}

/** Everything a test of an Endpoint could have leaked into, as one string. */
function sfEverything(): string
{
    return json_encode([
        Cluster::rows(Cluster::superuser(), 'select * from operations'),
        Cluster::rows(Cluster::superuser(), 'select * from sync_runs'),
        Cluster::rows(Cluster::superuser(), 'select * from audit_events'),
        Cluster::rows(Cluster::superuser(), 'select * from outbox_events'),
        Cluster::rows(Cluster::superuser(), 'select * from endpoint_revisions'),
        Cluster::rows(Cluster::superuser(), 'select id, name, default_headers from data_sources'),
        (string) file_get_contents(test()->logFile),
    ], JSON_THROW_ON_ERROR);
}

/** Revises the Endpoint behind the app's back, as another Admin's save would: revision 2 written with the pointer in one transaction. */
function sfMoveEndpoint(string $endpoint, ?string $method = null): void
{
    $pdo = Cluster::superuser();
    $revision = (string) Str::uuid7();
    $pdo->beginTransaction();

    try {
        $pdo->prepare("INSERT INTO endpoint_revisions (id, workspace_id, endpoint_id, revision, method, path_template, path_ast, params, headers, body_template, read_only_query, created_at, created_by_membership_id) SELECT ?, workspace_id, endpoint_id, 2, COALESCE(?::text, method), path_template || '-v2', path_ast, params, headers, body_template, read_only_query, now(), created_by_membership_id FROM endpoint_revisions WHERE endpoint_id = ? AND revision = 1")
            ->execute([$revision, $method, $endpoint]);
        $pdo->prepare('UPDATE endpoints SET revision = 2, current_revision_id = ?, updated_at = now() WHERE id = ?')->execute([$revision, $endpoint]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();

        throw $e;
    }
}

/** Starts a test and returns the Operation ID. */
function sfRun(string $source, string $endpoint, array $values, int $status = 202): string
{
    return (string) sfTest($source, $endpoint, $values)->assertStatus($status)->json('data.operation_id');
}

it('starts a sample_fetch Operation for the Endpoint revision, runs it through the guard and shows the requester the exact body', function () {
    $workspace = Cluster::workspace('Acme');
    [, $membership] = sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = sfEndpoint($source);
    $this->curl->queue = [FakeCurl::answer(200, SF_BODY)];

    sfTest($source, $endpoint, sfValues(['id' => 'c 7/CANARY']))->assertStatus(422);
    expect($this->curl->calls)->toBe([]);

    $started = sfTest($source, $endpoint, sfValues(['id' => 'c-7', 'limit' => '25']))->assertStatus(202)->assertHeader('Cache-Control', 'no-store, private');
    $id = $started->json('data.operation_id');

    expect(Str::isUuid($id))->toBeTrue()
        ->and($started->json('data.endpoint_revision'))->toBe(1)
        ->and($started->json('data.status'))->toBe('queued');

    $operation = Cluster::rows(Cluster::superuser(), 'select * from operations where id = ?', [$id])[0];
    expect($operation)->toMatchArray(['kind' => 'sample_fetch', 'status' => 'succeeded', 'subject_type' => 'endpoint', 'subject_id' => $endpoint, 'subject_revision' => 1, 'requester_membership_id' => $membership]);

    // One GET through the guard: the path rendered from the AST, the query from the one builder, the Endpoint headers after the defaults.
    expect($this->curl->calls)->toHaveCount(1)
        ->and($this->curl->calls[0][CURLOPT_URL])->toBe('https://api.example.com/customers/c-7/revenue?from=2026-01-01&limit=25')
        ->and($this->curl->calls[0][CURLOPT_HTTPGET])->toBeTrue()
        ->and($this->curl->calls[0][CURLOPT_RESOLVE])->toBe(['api.example.com:443:93.184.216.34'])
        ->and($this->curl->calls[0][CURLOPT_HTTPHEADER])->toContain('X-Team: finance')->toContain('X-Day: 2026-02-01')->toContain('Accept: application/json');

    $summary = sfPoll($id)->assertOk()->json('data');
    expect($summary['status'])->toBe('succeeded')
        ->and($summary['kind'])->toBe('sample_fetch')
        ->and($summary['result'])->toMatchArray(['ok' => true, 'status' => 200, 'code' => null, 'reason' => null, 'host' => 'api.example.com', 'endpoint_revision' => 1])
        ->and(array_keys($summary['result']))->toEqualCanonicalizing(['ok', 'status', 'latency_ms', 'code', 'reason', 'size_bytes', 'limit_bytes', 'host', 'request_id', 'endpoint_revision', 'page', 'pages'])
        ->and($summary['result']['page'])->toBeNull()
        ->and(json_encode($summary))->not->toContain('CANARY');

    // The requester reads the body exactly as received, every number with its lexeme.
    $sample = sfSample($source, $endpoint, $id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect($sample->json('data.body'))->toBe(SF_BODY)
        ->and($sample->json('data.status'))->toBe(200)
        ->and($sample->json('data.latency_ms'))->toBeInt()
        ->and($sample->json('data.expires_at'))->toBe($summary['expires_at'])
        ->and($sample->getContent())->toContain('12345678901234567890.12')->toContain('1.10');
});

it('records one sync_runs row of kind sample_fetch with the template and numbers only, and keeps every value and the body out of Postgres, the log, the audit and the summaries', function () {
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = sfEndpoint($source);
    $this->curl->queue = [FakeCurl::answer(200, SF_BODY)];

    $id = sfRun($source, $endpoint, sfValues(['id' => 'cust-'.SF_CANARY, 'limit' => 'lim-'.SF_CANARY, 'from' => '2026-03-04']));

    $runs = sfRuns();
    expect($runs)->toHaveCount(1)
        ->and($runs[0])->toMatchArray([
            'kind' => 'sample_fetch', 'status' => 'succeeded', 'http_status' => 200, 'error_code' => null, 'data_source_id' => $source,
            'url_template' => 'https://api.example.com/customers/{id}/revenue', 'bytes' => strlen(SF_BODY),
        ])
        ->and($runs[0]['request_id'])->toBeString();

    expect($this->curl->calls[0][CURLOPT_URL])->toContain('cust-'.SF_CANARY)->and(sfEverything())->not->toContain(SF_CANARY);

    // A tested sample has no sync target and never enters the raw tier (Story 2.14).
    expect(sfCount('raw_bodies'))->toBe(0)->and(sfCount('raw_observations'))->toBe(0)->and(sfCount('sync_targets'))->toBe(0);

    // The body is only in the sealed cache entry, which the requester reads back.
    expect(Cache::get(TenantKey::cache($workspace, 'sample:'.$id)))->toBeArray()
        ->and(json_encode(Cache::get(TenantKey::cache($workspace, 'sample:'.$id))))->not->toContain('CANARY-sf-body')->not->toContain('12345678901234567890')
        ->and(sfSample($source, $endpoint, $id)->assertOk()->json('data.body'))->toBe(SF_BODY);
});

it('audits connector.endpoint.tested with the Endpoint id, the method and the revision tested, never a value, the path or a body', function () {
    $workspace = Cluster::workspace('Acme');
    [, $membership] = sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = sfEndpoint($source);
    $this->curl->queue = [FakeCurl::answer(200, SF_BODY)];

    sfRun($source, $endpoint, sfValues(['id' => SF_CANARY]));

    $events = Cluster::rows(Cluster::superuser(), "select * from audit_events where action = 'connector.endpoint.tested'");
    $state = json_decode($events[0]['after_state'], true);

    expect($events)->toHaveCount(1)
        ->and($events[0]['actor'])->toBe($membership)
        ->and($events[0]['subject'])->toBe('endpoint:'.$endpoint)
        ->and($state)->toMatchArray(['endpoint_id' => $endpoint, 'method' => 'get', 'endpoint_revision' => 1, 'data_source_id' => $source])
        ->and(array_keys($state))->toEqualCanonicalizing(['endpoint_id', 'method', 'endpoint_revision', 'data_source_id'])
        ->and(json_encode($events))->not->toContain(SF_CANARY)->not->toContain('/customers');
});

it('refuses a missing, malformed or unsafe value with a 422 naming the parameter, and queues, audits and sends nothing', function (array $values, string $field, string $reason) {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = sfEndpoint($source);

    $response = sfTest($source, $endpoint, $values)->assertStatus(422);

    expect($response->json('error.code'))->toBe('platform.validation_failed')
        ->and(array_keys($response->json('errors')))->toContain("values.{$field}")
        ->and($response->json('reasons')["values.{$field}"])->toBe($reason)
        ->and(json_encode($response->json()))->not->toContain('CANARY');

    Queue::assertNothingPushed();
    expect(sfCount('operations'))->toBe(0)
        ->and(Cluster::rows(Cluster::superuser(), "select count(*) as n from audit_events where action = 'connector.endpoint.tested'")[0]['n'])->toBe(0)
        ->and($this->curl->calls)->toBe([]);
})->with([
    'a date parameter without a value' => [['header:X-Day' => '2026-02-01'], 'from', 'param-value-required'],
    'an empty path value' => [['id' => ''], 'id', 'param-value-required'],
    'a slash in a path value' => [['id' => 'a/b-CANARY'], 'id', 'param-value-invalid'],
    'a dot segment' => [['id' => '..'], 'id', 'param-value-invalid'],
    'a date that is not one' => [['from' => 'CANARY'], 'from', 'param-date-invalid'],
    'a date that does not exist' => [['from' => '2026-02-30'], 'from', 'param-date-invalid'],
    'a date-bound header without a date' => [['from' => '2026-01-01'], 'header:X-Day', 'param-value-required'],
    'a control character' => [['from' => '2026-01-01', 'limit' => "1\x07CANARY"], 'limit', 'param-value-invalid'],
]);

it('refuses a header value with CR or LF', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = sfEndpoint($source, ['headers' => [['name' => 'X-Team', 'binding' => 'fixed', 'value' => 'finance']]]);

    foreach ([["a\r\nX-Evil: 1"], ["a\nb"]] as [$value]) {
        sfTest($source, $endpoint, ['from' => '2026-01-01', 'header:X-Team' => $value])->assertStatus(422)->assertJsonPath('reasons', ['values.header:X-Team' => 'header-value-invalid']);
    }

    Queue::assertNothingPushed();
});

it('sends a read-only POST with the rendered body and an Idempotency-Key equal to the Operation id, and a 401 is not repeated', function () {
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = sfEndpoint($source, [
        'method' => 'POST', 'path' => '/search', 'read_only_query' => true, 'confirm_read_only' => true,
        'params' => [['name' => 'from', 'binding' => 'date_range_from'], ['name' => 'team', 'binding' => 'fixed', 'value' => 'a"b'], ['name' => 'debug', 'binding' => 'fixed', 'value' => '1']],
        'headers' => [], 'body_template' => '{"range":{"from":{"$param":"from"}},"filters":[{"$param":"team"},12345678901234567890.12,1.10]}',
    ]);
    $this->curl->queue = [FakeCurl::answer(401, '{"error":"no"}'), FakeCurl::answer(200, SF_BODY)];

    $id = sfRun($source, $endpoint, ['from' => '2026-01-01']);

    expect($this->curl->calls)->toHaveCount(1)
        ->and($this->curl->calls[0][CURLOPT_CUSTOMREQUEST])->toBe('POST')
        ->and($this->curl->calls[0][CURLOPT_URL])->toBe('https://api.example.com/search?debug=1')
        ->and($this->curl->calls[0][CURLOPT_POSTFIELDS])->toBe('{"filters":["a\"b",12345678901234567890.12,1.10],"range":{"from":"2026-01-01"}}')
        ->and($this->curl->calls[0][CURLOPT_HTTPHEADER])->toContain('Idempotency-Key: '.$id)->toContain('Content-Type: application/json')->toContain('Accept: application/json');

    // The 401 is the end of it: no second send, and the Admin is told the call failed.
    expect(sfPoll($id)->json('data.result'))->toMatchArray(['ok' => false, 'code' => 'fetch-failed', 'reason' => 'http_401', 'status' => 401])
        ->and(sfSample($source, $endpoint, $id)->assertNotFound()->getContent())->toBe('');
});

it('can never be asked to send a POST that is not read-only: the database refuses such a revision and the transport refuses such a request', function () {
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = Cluster::seedEndpoint($workspace, $source, '/legacy');

    expect(fn () => sfMoveEndpoint($endpoint, 'POST'))->toThrow(PDOException::class, 'endpoint_revisions_post_readonly_check');

    // The Endpoint is untouched, and testing it is an ordinary GET.
    $this->curl->queue = [FakeCurl::answer(200, SF_BODY)];
    sfRun($source, $endpoint, []);

    expect($this->curl->calls)->toHaveCount(1)->and($this->curl->calls[0][CURLOPT_HTTPGET])->toBeTrue();
});

it('maps each failure to the matching user code and keeps the body, the sample and the blob out of every failure', function (string $host, array $curl, string $code, string $reason, ?int $status) {
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API', $host);
    $endpoint = sfEndpoint($source);
    $this->curl->queue = $curl;

    $id = sfRun($source, $endpoint, sfValues());
    $summary = sfPoll($id)->json('data');

    expect($summary['status'])->toBe('failed')
        ->and($summary['result'])->toMatchArray(['ok' => false, 'code' => $code, 'reason' => $reason, 'status' => $status])
        ->and(sfSample($source, $endpoint, $id)->assertNotFound()->getContent())->toBe('')
        ->and(sfRuns()[0])->toMatchArray(['status' => 'failed', 'error_code' => $code])
        ->and(sfEverything())->not->toContain('CANARY-sf-body');
})->with([
    'host not on the allowlist' => ['other.example.com', [], 'host-not-allowlisted', 'host_not_allowlisted', null],
    'a blocked address' => ['blocked.example.com', [], 'blocked-address', 'blocked_address', null],
    'not JSON' => ['api.example.com', [FakeCurl::answer(200, '<html>CANARY-sf-body</html>', ['content-type' => ['text/html']])], 'not-json', 'connector.not_json:content_type', 200],
    'JSON that does not parse' => ['api.example.com', [FakeCurl::answer(200, '{"a":CANARY-sf-body}')], 'not-json', 'connector.not_json:parse', 200],
    'a server error' => ['api.example.com', [FakeCurl::answer(500, '{"error":"CANARY-sf-body"}')], 'fetch-failed', 'http_500', 500],
]);

it('answers a response over the limit with response-too-large and keeps nothing: no sample, truncated or not', function () {
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = sfEndpoint($source);
    $this->curl->queue = [new ResponseLimitExceeded(4097, 4096, 200)];

    $id = sfRun($source, $endpoint, sfValues());

    expect(sfPoll($id)->json('data.result'))->toMatchArray(['ok' => false, 'code' => 'response-too-large', 'reason' => 'connector.limit_exceeded', 'size_bytes' => 4097, 'limit_bytes' => 4096])
        ->and(sfSample($source, $endpoint, $id)->assertNotFound()->getContent())->toBe('');
});

it('fails with fetch-failed and blob_unavailable when the data key is unusable, and stores nothing', function (string $content) {
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = sfEndpoint($source);
    file_put_contents($this->keyFile, $content);
    $this->curl->queue = [FakeCurl::answer(200, SF_BODY)];

    $id = sfRun($source, $endpoint, sfValues());

    expect(sfPoll($id)->json('data'))->toMatchArray(['status' => 'failed'])
        ->and(sfPoll($id)->json('data.result'))->toMatchArray(['ok' => false, 'code' => 'fetch-failed', 'reason' => 'blob_unavailable'])
        ->and(sfSample($source, $endpoint, $id)->assertNotFound()->getContent())->toBe('')
        ->and(sfEverything())->not->toContain('CANARY-sf-body');
    expect(Cache::get(TenantKey::cache($workspace, 'sample:'.$id)))->toBeNull();
})->with(['the dev placeholder' => ['placeholder-not-a-real-key-data'], 'an empty file' => ['']]);

it('shows the sample to the requesting membership only: another member, another Workspace and a member without the permission get a bare 404 or 403', function () {
    $a = Cluster::workspace('Acme');
    $b = Cluster::workspace('Beta');
    [, $ada] = sfAdmin($a);
    $source = Cluster::seedDataSource($a, 'Sales API');
    $endpoint = sfEndpoint($source);
    $this->curl->queue = [FakeCurl::answer(200, SF_BODY)];
    $id = sfRun($source, $endpoint, sfValues());
    sfSample($source, $endpoint, $id)->assertOk();

    // Another Admin of the same Workspace, holding the permission.
    [$bobUser] = sfAdmin($a, email: 'bob@example.test');
    expect(sfSample($source, $endpoint, $id)->assertNotFound()->getContent())->toBe('')
        ->and(sfPoll($id)->assertNotFound()->getContent())->toBe('');

    // An Admin of another Workspace, even naming the right IDs.
    sfAdmin($b, email: 'eve@example.test');
    expect(sfSample($source, $endpoint, $id)->assertNotFound()->getContent())->toBe('');

    // Without data_sources.manage: the gate answers before anything is looked up.
    sfAdmin($a, permissions: [], email: 'cy@example.test');
    sfSample($source, $endpoint, $id)->assertForbidden();
    sfTest($source, $endpoint, sfValues())->assertForbidden();

    // The requester still reads it.
    sfSignIn(Cluster::rows(Cluster::superuser(), 'select user_id from workspace_memberships where id = ?', [$ada])[0]['user_id'], $a);
    sfSample($source, $endpoint, $id)->assertOk();
});

it('answers a bare 404 for an Operation of another Endpoint or kind, an expired one and a malformed id', function () {
    $workspace = Cluster::workspace('Acme');
    [, $membership] = sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = sfEndpoint($source);
    $other = sfEndpoint($source, ['path' => '/other', 'params' => [], 'headers' => []]);
    $this->curl->queue = [FakeCurl::answer(200, SF_BODY)];
    $id = sfRun($source, $endpoint, sfValues());

    expect(sfSample($source, $other, $id)->assertNotFound()->getContent())->toBe('')
        ->and(sfSample($source, $endpoint, 'not-a-uuid')->assertNotFound()->getContent())->toBe('')
        ->and(sfSample($source, $endpoint, (string) Str::uuid7())->assertNotFound()->getContent())->toBe('');

    $connectionTest = Cluster::seedOperation($workspace, $membership, 'succeeded');
    expect(sfSample($source, $endpoint, $connectionTest)->assertNotFound()->getContent())->toBe('');

    Cluster::superuser()->prepare("UPDATE operations SET expires_at = now() - interval '1 minute' WHERE id = ?")->execute([$id]);
    expect(sfSample($source, $endpoint, $id)->assertNotFound()->getContent())->toBe('');
});

it('ends the Operation as stale when the Endpoint revision moves before the request: nothing is sent or stored, the new revision is in the summary', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = sfEndpoint($source);

    $id = sfRun($source, $endpoint, sfValues());
    $this->putJson("/api/v1/admin/data-sources/{$source}/endpoints/{$endpoint}", sfEndpointBody(['path' => '/customers/{id}/revenue-v2']) + ['revision' => 1], SF_HEADERS)->assertOk();

    $job = Queue::pushed(RunOperation::class)->first();
    app(WorkspaceTransaction::class)->runJob($job, fn ($j) => $j->handle(app(Operations::class)));

    $summary = sfPoll($id)->json('data');
    expect($summary['status'])->toBe('stale')
        ->and($summary['result'])->toMatchArray(['ok' => false, 'reason' => 'stale', 'endpoint_revision' => 2])
        ->and($this->curl->calls)->toBe([])
        ->and(sfSample($source, $endpoint, $id)->assertNotFound()->getContent())->toBe('');
});

it('ends the Operation as stale and deletes the blob when the Endpoint revision moves while the request runs', function () {
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = sfEndpoint($source);

    // The Endpoint is revised (revision 2) while the response is on its way back.
    $curl = new class(fn () => sfMoveEndpoint($endpoint)) implements CurlClient
    {
        public function __construct(private readonly Closure $during) {}

        public function execute(array $options, ?int $maxBytes = null): CurlResult
        {
            ($this->during)();

            return new CurlResult(200, ['content-type' => ['application/json']], SF_BODY);
        }
    };
    app()->instance(CurlClient::class, $curl);

    $id = sfRun($source, $endpoint, sfValues());

    expect(sfPoll($id)->json('data.status'))->toBe('stale')
        ->and(sfPoll($id)->json('data.result'))->toMatchArray(['ok' => false, 'reason' => 'stale', 'endpoint_revision' => 2])
        ->and(sfSample($source, $endpoint, $id)->assertNotFound()->getContent())->toBe('')
        ->and(Cache::get(TenantKey::cache($workspace, 'sample:'.$id)))->toBeNull()
        ->and(sfRuns()[0])->toMatchArray(['error_code' => 'stale']);
});

it('does not show a sample once the Endpoint has moved on from the revision tested', function () {
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = sfEndpoint($source);
    $this->curl->queue = [FakeCurl::answer(200, SF_BODY)];
    $id = sfRun($source, $endpoint, sfValues());
    sfSample($source, $endpoint, $id)->assertOk();

    $this->putJson("/api/v1/admin/data-sources/{$source}/endpoints/{$endpoint}", sfEndpointBody(['path' => '/customers/{id}/revenue-v2']) + ['revision' => 1], SF_HEADERS)->assertOk();

    expect(sfSample($source, $endpoint, $id)->assertNotFound()->getContent())->toBe('');
});

it('answers 429 with retry_after, a Retry-After header and enqueues nothing when over the per-membership limit', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = sfEndpoint($source);
    config(['dashflow.sample_fetch.membership_limit.value' => '2', 'dashflow.sample_fetch.window.value' => '60']);

    sfTest($source, $endpoint, sfValues())->assertStatus(202);
    sfTest($source, $endpoint, sfValues())->assertStatus(202);
    $over = sfTest($source, $endpoint, sfValues())->assertStatus(429);

    expect($over->json('error.code'))->toBe('platform.too_many_requests')
        ->and($over->json('error.retry_after'))->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and($over->headers->get('Retry-After'))->toBe((string) $over->json('error.retry_after'))
        ->and($over->json('reason'))->toBe('sample-fetch-throttled');

    Queue::assertPushed(RunOperation::class, 2);
    expect(sfCount('operations'))->toBe(2)
        ->and(Cluster::rows(Cluster::superuser(), "select count(*) as n from audit_events where action = 'connector.endpoint.tested'")[0]['n'])->toBe(2);
});

it('limits per Workspace across members, leaves another Workspace alone and does not limit while unset', function () {
    Queue::fake();
    $a = Cluster::workspace('Acme');
    $b = Cluster::workspace('Beta');
    sfAdmin($a, email: 'ada@example.test');
    $sa = Cluster::seedDataSource($a, 'Sales API');
    $ea = sfEndpoint($sa);

    foreach (range(1, 4) as $_) {
        sfTest($sa, $ea, sfValues())->assertStatus(202);
    }

    config(['dashflow.sample_fetch.workspace_limit.value' => '2', 'dashflow.sample_fetch.window.value' => '60']);
    Cache::flush();
    sfTest($sa, $ea, sfValues())->assertStatus(202);
    sfAdmin($a, email: 'bob@example.test');
    sfTest($sa, $ea, sfValues())->assertStatus(202);
    sfTest($sa, $ea, sfValues())->assertStatus(429)->assertJsonPath('error.code', 'platform.too_many_requests');

    sfAdmin($b, email: 'eve@example.test');
    $sb = Cluster::seedDataSource($b, 'Other');
    sfTest($sb, sfEndpoint($sb), sfValues())->assertStatus(202);
});

it('answers 404 for a Data Source or Endpoint of another Workspace or one that does not exist, and enqueues nothing', function () {
    Queue::fake();
    $a = Cluster::workspace('Acme');
    $b = Cluster::workspace('Beta');
    sfAdmin($a);
    $source = Cluster::seedDataSource($a, 'Sales API');
    $endpoint = sfEndpoint($source);
    $foreignSource = Cluster::seedDataSource($b, 'Theirs');
    $foreignEndpoint = Cluster::seedEndpoint($b, $foreignSource);

    sfTest($foreignSource, $foreignEndpoint, [])->assertNotFound();
    sfTest($source, $foreignEndpoint, [])->assertNotFound();
    sfTest($source, (string) Str::uuid7(), [])->assertNotFound();
    sfTest((string) Str::uuid7(), $endpoint, [])->assertNotFound();

    Queue::assertNothingPushed();
});

it('never makes the request on the web tier: the request only queues a signed job on fetch-interactive', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = sfEndpoint($source);

    $id = sfRun($source, $endpoint, sfValues());

    Queue::assertPushedOn('fetch-interactive', RunOperation::class);
    expect($this->curl->calls)->toBe([])
        ->and($this->resolver->lookups)->toBe([])
        ->and(Queue::pushed(RunOperation::class)->first()->operationId)->toBe($id)
        ->and(sfPoll($id)->json('data'))->toMatchArray(['status' => 'queued', 'result' => null]);
});

it('registers the sample_fetch kind on fetch-interactive, which only the connector supervisor consumes', function () {
    $kinds = app(OperationKinds::class);

    expect($kinds->has('sample_fetch'))->toBeTrue()
        ->and($kinds->get('sample_fetch')->queue)->toBe('fetch-interactive')
        ->and($kinds->get('sample_fetch')->ttlSeconds)->toBe(600)
        ->and($kinds->get('sample_fetch')->handler)->toBe(RunSampleFetch::class)
        ->and(config('horizon.environments.connector.supervisor-connector.queue'))->toContain('fetch-interactive');
});

/** A saved Data Source with credentials, created through the API as an Admin would; returns its ID. */
function sfCredentialed(array $overrides): string
{
    return test()->postJson('/api/v1/admin/data-sources', $overrides + [
        'name' => 'Secured API', 'base_url' => 'https://api.example.com/v1', 'headers' => [], 'timeout_seconds' => null,
        'max_response_bytes' => null, 'max_pages' => null, 'live_capable' => false, 'confirm_password' => 'admin-password-1',
    ], SF_HEADERS)->assertCreated()->json('data.data_source_id');
}

function sfToken(string $token = 'tok-1'): CurlResult
{
    return FakeCurl::answer(200, '{"access_token":"'.$token.'","token_type":"Bearer","expires_in":3600}');
}

function sfSimpleEndpoint(string $source, array $overrides = []): string
{
    return sfEndpoint($source, $overrides + ['path' => '/ping', 'params' => [], 'headers' => []]);
}

it('sends the Data Source credentials with an Endpoint test: bearer, API key in the query, API key in a header', function (array $source, Closure $check) {
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $id = sfCredentialed($source);
    $endpoint = sfSimpleEndpoint($id);
    $this->curl->queue = [FakeCurl::answer(200, SF_BODY)];

    $opId = sfRun($id, $endpoint, []);

    expect(sfPoll($opId)->json('data.status'))->toBe('succeeded');
    $check($this->curl->calls[0]);
    expect(sfEverything())->not->toContain(SF_CANARY);
})->with([
    'bearer' => [
        ['auth_type' => 'bearer', 'secrets' => ['bearer_token' => SF_CANARY]],
        fn (array $call) => expect($call[CURLOPT_HTTPHEADER])->toContain('Authorization: Bearer '.SF_CANARY)->and($call[CURLOPT_URL])->toBe('https://api.example.com/v1/ping'),
    ],
    'api key in the query' => [
        ['auth_type' => 'api_key', 'api_key_name' => 'key', 'api_key_placement' => 'query', 'secrets' => ['api_key' => SF_CANARY]],
        fn (array $call) => expect($call[CURLOPT_URL])->toBe('https://api.example.com/v1/ping?key='.SF_CANARY),
    ],
    'api key in a header' => [
        ['auth_type' => 'api_key', 'api_key_name' => 'X-Api-Key', 'api_key_placement' => 'header', 'secrets' => ['api_key' => SF_CANARY]],
        fn (array $call) => expect($call[CURLOPT_HTTPHEADER])->toContain('X-Api-Key: '.SF_CANARY),
    ],
]);

it('gets a token for an OAuth2 source and sends the call with it', function () {
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $id = sfCredentialed(['auth_type' => 'oauth2_client_credentials', 'oauth_token_url' => 'https://auth.example.com/token', 'oauth_client_id' => 'client-1', 'oauth_scope' => 'read', 'secrets' => ['oauth_client_secret' => SF_CANARY]]);
    $endpoint = sfSimpleEndpoint($id);
    $this->curl->queue = [sfToken('tok-1'), FakeCurl::answer(200, SF_BODY)];

    $opId = sfRun($id, $endpoint, []);

    expect(sfPoll($opId)->json('data.status'))->toBe('succeeded')
        ->and($this->curl->calls)->toHaveCount(2)
        ->and($this->curl->calls[0][CURLOPT_URL])->toBe('https://auth.example.com/token')
        ->and($this->curl->calls[1][CURLOPT_HTTPHEADER])->toContain('Authorization: Bearer tok-1');
});

it('answers auth-failed when an OAuth2 source refuses a fresh token, after the one allowed retry of a GET', function () {
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $id = sfCredentialed(['auth_type' => 'oauth2_client_credentials', 'oauth_token_url' => 'https://auth.example.com/token', 'oauth_client_id' => 'client-1', 'oauth_scope' => null, 'secrets' => ['oauth_client_secret' => SF_CANARY]]);
    $endpoint = sfSimpleEndpoint($id);
    $this->curl->queue = [sfToken('a'), FakeCurl::answer(401), sfToken('b'), FakeCurl::answer(401)];

    $opId = sfRun($id, $endpoint, []);

    expect(sfPoll($opId)->json('data.result'))->toMatchArray(['ok' => false, 'code' => 'auth-failed'])
        ->and($this->curl->calls)->toHaveCount(4)
        ->and(sfRuns()[0])->toMatchArray(['status' => 'failed', 'error_code' => 'auth-failed'])
        ->and(sfSample($id, $endpoint, $opId)->assertNotFound()->getContent())->toBe('');
});

it('does not repeat an OAuth2 POST after a 401', function () {
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $id = sfCredentialed(['auth_type' => 'oauth2_client_credentials', 'oauth_token_url' => 'https://auth.example.com/token', 'oauth_client_id' => 'client-1', 'oauth_scope' => null, 'secrets' => ['oauth_client_secret' => SF_CANARY]]);
    $endpoint = sfSimpleEndpoint($id, ['method' => 'POST', 'path' => '/q', 'read_only_query' => true, 'confirm_read_only' => true]);
    $this->curl->queue = [sfToken('a'), FakeCurl::answer(401), sfToken('b'), FakeCurl::answer(200, SF_BODY)];

    $opId = sfRun($id, $endpoint, []);

    expect($this->curl->calls)->toHaveCount(2)
        ->and(sfPoll($opId)->json('data.result'))->toMatchArray(['ok' => false, 'code' => 'auth-failed'])
        ->and(sfRuns()[0]['error_code'])->toBe('auth-failed');
});

it('answers fetch-failed with secret_missing when a stored credential is gone', function () {
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $id = sfCredentialed(['auth_type' => 'bearer', 'secrets' => ['bearer_token' => SF_CANARY]]);
    $endpoint = sfSimpleEndpoint($id);
    Cluster::superuser()->prepare('DELETE FROM secrets WHERE data_source_id = ?')->execute([$id]);

    $opId = sfRun($id, $endpoint, []);

    expect(sfPoll($opId)->json('data.result'))->toMatchArray(['ok' => false, 'code' => 'fetch-failed', 'reason' => 'secret_missing'])
        ->and($this->curl->calls)->toBe([])
        ->and(sfRuns()[0]['error_code'])->toBe('fetch-failed');
});

it('maps a transport failure to its reason and error code, and sends a POST exactly once however it fails', function (string $method, int $errno, string $reason) {
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $post = $method === 'POST' ? ['method' => 'POST', 'read_only_query' => true, 'confirm_read_only' => true] : [];
    $endpoint = sfSimpleEndpoint($source, $post);
    $this->curl->queue = [new EgressTransportFailed('The request failed.', $errno), FakeCurl::answer(200, SF_BODY)];

    $opId = sfRun($source, $endpoint, []);

    expect(sfPoll($opId)->json('data.result'))->toMatchArray(['ok' => false, 'code' => 'fetch-failed', 'reason' => $reason])
        ->and($this->curl->calls)->toHaveCount(1)
        ->and(sfRuns()[0])->toMatchArray(['status' => 'failed', 'error_code' => 'fetch-failed']);
})->with([
    'GET timeout' => ['GET', 28, 'timeout'],
    'POST timeout' => ['POST', 28, 'timeout'],
    'POST refused connection' => ['POST', 7, 'transport'],
    'POST TLS' => ['POST', 60, 'tls'],
]);

it('treats a failure for a superseded revision as stale, not as the current http status', function () {
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = sfSimpleEndpoint($source);
    $curl = new class(fn () => sfMoveEndpoint($endpoint)) implements CurlClient
    {
        public function __construct(private readonly Closure $during) {}

        public function execute(array $options, ?int $maxBytes = null): CurlResult
        {
            ($this->during)();

            return new CurlResult(500, ['content-type' => ['application/json']], '{"error":"x"}');
        }
    };
    app()->instance(CurlClient::class, $curl);

    $opId = sfRun($source, $endpoint, []);

    expect(sfPoll($opId)->json('data.status'))->toBe('stale')
        ->and(sfPoll($opId)->json('data.result'))->toMatchArray(['reason' => 'stale', 'endpoint_revision' => 2]);
});

it('drops the blob when the revision moves between the check and the store, and when the worker fails after storing', function () {
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = sfSimpleEndpoint($source);
    $this->curl->queue = [FakeCurl::answer(200, SF_BODY)];

    // The second read of the Endpoint (after the store) sees revision 2: wrap the Endpoints contract once.
    $real = app(Endpoints::class);
    $reads = 0;
    app()->instance(Endpoints::class, new class($real, function () use (&$reads, $endpoint) {
        if (++$reads === 4) {
            sfMoveEndpoint($endpoint);
        }
    }) implements Endpoints
    {
        public function __construct(private $real, private readonly Closure $tick) {}

        public function list(string $workspaceId, string $dataSourceId, ?string $search): EndpointPage
        {
            return $this->real->list($workspaceId, $dataSourceId, $search);
        }

        public function find(string $workspaceId, string $dataSourceId, string $id): Endpoint
        {
            ($this->tick)();

            return $this->real->find($workspaceId, $dataSourceId, $id);
        }

        public function create(DataSourceActor $actor, string $dataSourceId, EndpointInput $input): Endpoint
        {
            return $this->real->create($actor, $dataSourceId, $input);
        }

        public function revise(DataSourceActor $actor, string $dataSourceId, string $id, EndpointInput $input, int $revision): Endpoint
        {
            return $this->real->revise($actor, $dataSourceId, $id, $input, $revision);
        }
    });

    $opId = sfRun($source, $endpoint, []);

    expect(sfPoll($opId)->json('data.status'))->toBe('stale')
        ->and(Cache::get(TenantKey::cache($workspace, 'sample:'.$opId)))->toBeNull();
});

it('forgets a stored blob in cleanup unless the run kept it', function () {
    $workspace = Cluster::workspace('Acme');
    [, $membership] = sfAdmin($workspace);
    $store = app(SampleBlobStore::class);
    $op = (string) Str::uuid7();
    $operation = new Operation($op, $workspace, 'sample_fetch', $membership, 'endpoint', (string) Str::uuid7(), 1, OperationStatus::Running, '2030-01-01T00:00:00Z', null, null);

    // A worker that stored the blob and then died: the next cleanup drops it.
    $store->put($workspace, $op, $membership, $operation->subjectId, 1, SF_BODY, 600);
    app(RunSampleFetch::class)->cleanup($operation);

    expect($store->get($workspace, $op, $membership, $operation->subjectId, 1))->toBeNull();
});

it('throttles the test and sample routes per minute with 429', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = sfSimpleEndpoint($source);
    $statuses = [];

    foreach (range(1, 31) as $_) {
        $statuses[] = sfTest($source, $endpoint, [])->status();
    }

    expect(array_slice($statuses, 0, 30))->each->toBe(202)->and($statuses[30])->toBe(429);

    $statuses = [];

    foreach (range(1, 121) as $_) {
        $statuses[] = sfSample($source, $endpoint, (string) Str::uuid7())->status();
    }

    expect($statuses[119])->toBe(404)->and($statuses[120])->toBe(429);
});

it('reads nothing when the sealed entry is damaged or the key is not the one it was sealed with', function () {
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = sfSimpleEndpoint($source);
    $this->curl->queue = [FakeCurl::answer(200, SF_BODY)];
    $id = sfRun($source, $endpoint, []);
    $key = TenantKey::cache($workspace, 'sample:'.$id);
    $entry = Cache::get($key);
    sfSample($source, $endpoint, $id)->assertOk();

    Cache::put($key, ['workspace_id' => $workspace, 'value' => '!!not base64!!'], 600);
    sfSample($source, $endpoint, $id)->assertNotFound();

    Cache::put($key, $entry, 600);
    sfSample($source, $endpoint, $id)->assertOk();

    file_put_contents($this->keyFile, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    expect(sfSample($source, $endpoint, $id)->assertNotFound()->getContent())->toBe('');
});

it('limits only by what was counted: a refused value never spends a slot, and a Workspace refusal is counted for the person too', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = sfEndpoint($source);
    config(['dashflow.sample_fetch.membership_limit.value' => '1', 'dashflow.sample_fetch.window.value' => '60']);

    // Refused by validation: nothing is counted, so the first valid request is still allowed.
    sfTest($source, $endpoint, [])->assertStatus(422);
    sfTest($source, $endpoint, sfValues(['id' => 'a/b']))->assertStatus(422);
    sfTest($source, $endpoint, sfValues())->assertStatus(202);
    sfTest($source, $endpoint, sfValues())->assertStatus(429);

    // Every limit is counted before any is compared (as Test connection does), so a request over the Workspace limit also
    // spends the person's slot.
    Cache::flush();
    config(['dashflow.sample_fetch.membership_limit.value' => '2', 'dashflow.sample_fetch.workspace_limit.value' => '1']);
    sfTest($source, $endpoint, sfValues())->assertStatus(202);
    sfTest($source, $endpoint, sfValues())->assertStatus(429);
    config(['dashflow.sample_fetch.workspace_limit.value' => '5']);
    sfTest($source, $endpoint, sfValues())->assertStatus(429);
});

it('caps the number and the size of the test values with a 422', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = sfEndpoint($source);
    $many = [];

    foreach (range(1, 101) as $i) {
        $many["extra{$i}"] = 'x';
    }

    sfTest($source, $endpoint, sfValues($many))->assertStatus(422);
    sfTest($source, $endpoint, sfValues([str_repeat('k', 129) => 'x']))->assertStatus(422);
    sfTest($source, $endpoint, sfValues(['limit' => str_repeat('v', 2049)]))->assertStatus(422);
    sfTest($source, $endpoint, sfValues(['unknown' => 'ignored']))->assertStatus(202);

    Queue::assertPushed(RunOperation::class, 1);
});

// Story 2.11: a Data Source that pages its answers. The pages are followed through the real guard, merged into one document
// and handed to the requester like any other sample; a limit, a refused link or a failing page fails the whole test.

/** @param  array<string, int|string|null>  $columns  pagination columns of the Data Source row */
function sfPaginate(string $source, array $columns, ?int $maxPages = null): void
{
    $columns += ['pagination_param' => null, 'pagination_size_param' => null, 'pagination_size' => null, 'pagination_records_path' => null, 'pagination_cursor_path' => null];
    Cluster::superuser()->prepare('UPDATE data_sources SET pagination_style = :pagination_style, pagination_param = :pagination_param, pagination_size_param = :pagination_size_param, pagination_size = :pagination_size, pagination_records_path = :pagination_records_path, pagination_cursor_path = :pagination_cursor_path, max_pages = :max_pages WHERE id = :id')
        ->execute($columns + ['max_pages' => $maxPages, 'id' => $source]);
}

function sfPage(string $body, array $headers = []): CurlResult
{
    return FakeCurl::answer(200, $body, ['content-type' => ['application/json']] + $headers);
}

/** @return list<array<string, mixed>> */
function sfBlocks(): array
{
    return array_map(
        fn (array $row): array => json_decode($row['after_state'], true),
        Cluster::rows(Cluster::superuser(), "select after_state from audit_events where action = 'connector.egress.blocked' order by occurred_at, id"),
    );
}

function sfPagedWorld(array $style, array $answers, ?int $maxPages = null): array
{
    $workspace = Cluster::workspace('Acme');
    sfAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    sfPaginate($source, $style, $maxPages);
    $endpoint = sfEndpoint($source);
    test()->curl->queue = $answers;

    return [$workspace, $source, $endpoint];
}

it('follows the pages of a paged source, merges them losslessly into one sample and records one sync_runs row with the cumulative bytes', function () {
    $pages = [
        '{"meta":{"total":12345678901234567890.12},"data":[{"v":1.10}],"note":"CANARY-sf-first"}',
        '{"meta":{"total":1},"data":[{"v":2.50},{"v":3}]}',
        '{"data":[{"v":4e2}]}',
        '{"data":[]}',
    ];
    [$workspace, $source, $endpoint] = sfPagedWorld(['pagination_style' => 'page', 'pagination_param' => 'page', 'pagination_records_path' => 'data'], array_map(fn (string $b): CurlResult => sfPage($b), $pages));

    $id = sfRun($source, $endpoint, sfValues(['id' => 'c-7', 'limit' => '25']));

    expect(array_map(fn (array $c): string => $c[CURLOPT_URL], $this->curl->calls))->toBe(array_map(
        fn (int $n): string => "https://api.example.com/customers/c-7/revenue?from=2026-01-01&limit=25&page={$n}", [1, 2, 3, 4],
    ));

    $summary = sfPoll($id)->json('data.result');
    expect($summary)->toMatchArray(['ok' => true, 'status' => 200, 'code' => null, 'page' => 4, 'pages' => 4, 'size_bytes' => array_sum(array_map('strlen', $pages))])
        ->and(sfSample($source, $endpoint, $id)->assertOk()->json('data.body'))
        ->toBe('{"meta":{"total":12345678901234567890.12},"data":[{"v":1.10},{"v":2.50},{"v":3},{"v":4e2}],"note":"CANARY-sf-first"}');

    $runs = sfRuns();
    expect($runs)->toHaveCount(1)
        ->and($runs[0])->toMatchArray(['status' => 'succeeded', 'http_status' => 200, 'bytes' => array_sum(array_map('strlen', $pages)), 'error_code' => null])
        ->and(sfEverything())->not->toContain('CANARY-sf-first')->not->toContain('12345678901234567890');
});

it('sends the cursor as a plain token and never requests it as a URL, and keeps the tokens out of every record', function () {
    [, $source, $endpoint] = sfPagedWorld(
        ['pagination_style' => 'cursor', 'pagination_param' => 'after', 'pagination_cursor_path' => 'meta.next', 'pagination_records_path' => 'd', 'pagination_size_param' => 'per_page', 'pagination_size' => 20],
        [sfPage('{"d":[1],"meta":{"next":"https://evil.example/CANARY-sf-cursor"}}'), sfPage('{"d":[2],"meta":{"next":null}}')],
    );

    $id = sfRun($source, $endpoint, sfValues());

    expect($this->curl->calls)->toHaveCount(2)
        ->and($this->curl->calls[0][CURLOPT_URL])->toBe('https://api.example.com/customers/c-42/revenue?from=2026-01-01&limit=10&per_page=20')
        ->and($this->curl->calls[1][CURLOPT_URL])->toBe('https://api.example.com/customers/c-42/revenue?from=2026-01-01&limit=10&after=https%3A%2F%2Fevil.example%2FCANARY-sf-cursor&per_page=20')
        ->and($this->curl->calls[1][CURLOPT_RESOLVE])->toBe(['api.example.com:443:93.184.216.34'])
        ->and(sfPoll($id)->json('data.result'))->toMatchArray(['ok' => true, 'pages' => 2])
        ->and(sfSample($source, $endpoint, $id)->json('data.body'))->toBe('{"d":[1,2],"meta":{"next":"https://evil.example/CANARY-sf-cursor"}}')
        ->and(sfRuns())->toHaveCount(1)
        ->and(sfEverything())->not->toContain('CANARY-sf-cursor');
});

it('fails a repeated cursor as a loop and stores nothing', function () {
    [, $source, $endpoint] = sfPagedWorld(
        ['pagination_style' => 'cursor', 'pagination_param' => 'after', 'pagination_cursor_path' => 'next', 'pagination_records_path' => 'd'],
        [sfPage('{"d":[1],"next":"c1"}'), sfPage('{"d":[2],"next":"c1"}')],
    );

    $id = sfRun($source, $endpoint, sfValues());

    expect(sfPoll($id)->json('data.result'))->toMatchArray(['ok' => false, 'code' => 'fetch-failed', 'reason' => 'pagination_cursor_loop', 'page' => 2, 'pages' => 2])
        ->and(sfSample($source, $endpoint, $id)->assertNotFound()->getContent())->toBe('');
});

it('follows the Link header through the guard, resolving a relative target against the current URL', function () {
    [, $source, $endpoint] = sfPagedWorld(
        ['pagination_style' => 'link_header', 'pagination_records_path' => 'data'],
        [
            sfPage('{"data":[1]}', ['link' => ['<https://api.example.com/next?cursor=2>; rel="next"']]),
            sfPage('{"data":[2]}', ['link' => ['</next?cursor=3>; rel="next"']]),
            sfPage('{"data":[3]}'),
        ],
    );

    $id = sfRun($source, $endpoint, sfValues());

    expect(array_map(fn (array $c): string => $c[CURLOPT_URL], $this->curl->calls))->toBe([
        'https://api.example.com/customers/c-42/revenue?from=2026-01-01&limit=10', 'https://api.example.com/next?cursor=2', 'https://api.example.com/next?cursor=3',
    ])->and(sfPoll($id)->json('data.result'))->toMatchArray(['ok' => true, 'pages' => 3])
        ->and(sfSample($source, $endpoint, $id)->json('data.body'))->toBe('{"data":[1,2,3]}');
});

it('refuses a Link target on another origin, another port or an http downgrade: audited, counted, no request sent to it and nothing stored', function (string $link, string $host, int $port) {
    [, $source, $endpoint] = sfPagedWorld(
        ['pagination_style' => 'link_header', 'pagination_records_path' => 'data'],
        [sfPage('{"data":[1]}', ['link' => [$link]]), sfPage('{"data":[2]}')],
    );

    $id = sfRun($source, $endpoint, sfValues());

    expect($this->curl->calls)->toHaveCount(1)
        ->and(sfPoll($id)->json('data.result'))->toMatchArray(['ok' => false, 'code' => 'fetch-failed', 'reason' => 'pagination_refused', 'page' => 1, 'pages' => 1])
        ->and(sfBlocks())->toEqual([['reason' => 'pagination_refused', 'host' => $host, 'port' => $port]])
        ->and(sfSample($source, $endpoint, $id)->assertNotFound()->getContent())->toBe('')
        ->and(sfRuns()[0])->toMatchArray(['status' => 'failed', 'error_code' => 'fetch-failed']);
})->with([
    'another host' => ['<https://other.example.com/x>; rel="next"', 'other.example.com', 443],
    'another port' => ['<https://api.example.com:8443/x>; rel="next"', 'api.example.com', 8443],
    'a downgrade' => ['<http://api.example.com/x>; rel="next"', 'api.example.com', 80],
]);

it('refuses the first page when the guard denies it, naming page 1 and recording the block', function () {
    [, $source, $endpoint] = sfPagedWorld(
        ['pagination_style' => 'link_header', 'pagination_records_path' => 'data'],
        [sfPage('{"data":[1]}', ['link' => ['<https://api.example.com/next>; rel="next"']]), sfPage('{"data":[2]}')],
    );
    Cluster::superuser()->exec("DELETE FROM host_allowlist_entries WHERE host = 'api.example.com'");
    // The first request is refused by the guard already: no page is fetched and the block is recorded by the guard.
    $id = sfRun($source, $endpoint, sfValues());

    expect($this->curl->calls)->toBe([])
        ->and(sfPoll($id)->json('data.result'))->toMatchArray(['ok' => false, 'code' => 'host-not-allowlisted', 'page' => 1, 'pages' => 0])
        ->and(sfBlocks())->toHaveCount(1);
});

it('fails with too-many-pages when the run would need a page beyond the cap, and keeps nothing', function () {
    [, $source, $endpoint] = sfPagedWorld(
        ['pagination_style' => 'page', 'pagination_param' => 'p'],
        [sfPage('[1]'), sfPage('[2]'), sfPage('[3]')],
        maxPages: 2,
    );

    $id = sfRun($source, $endpoint, sfValues());

    // Page 3 is asked for as the terminator; it holds records, so the run overflows.
    expect($this->curl->calls)->toHaveCount(3)
        ->and(sfPoll($id)->json('data.result'))->toMatchArray(['ok' => false, 'code' => 'too-many-pages', 'reason' => 'connector.limit_exceeded', 'page' => 3, 'pages' => 2])
        ->and(sfSample($source, $endpoint, $id)->assertNotFound()->getContent())->toBe('')
        ->and(sfRuns()[0])->toMatchArray(['status' => 'failed', 'error_code' => 'too-many-pages']);
});

it('applies the smaller of the Data Source cap and the platform ceiling, and no cap when neither is set', function () {
    config(['dashflow.tunables.guards.max_pages.value' => '1']);
    [, $source, $endpoint] = sfPagedWorld(['pagination_style' => 'page', 'pagination_param' => 'p'], [sfPage('[1]'), sfPage('[2]')], maxPages: 5);

    expect(sfPoll(sfRun($source, $endpoint, sfValues()))->json('data.result'))->toMatchArray(['code' => 'too-many-pages', 'pages' => 1]);

    config(['dashflow.tunables.guards.max_pages.value' => null]);
    $this->curl->queue = array_merge(array_map(fn (int $n): CurlResult => sfPage("[{$n}]"), range(1, 12)), [sfPage('[]')]);
    sfPaginate($source, ['pagination_style' => 'page', 'pagination_param' => 'p'], null);

    expect(sfPoll(sfRun($source, $endpoint, sfValues()))->json('data.result'))->toMatchArray(['ok' => true, 'pages' => 13]);
});

it('fails with response-too-large and the cumulative size when the pages together pass the byte limit', function () {
    [, $source, $endpoint] = sfPagedWorld(
        ['pagination_style' => 'page', 'pagination_param' => 'p'],
        [sfPage('[1111]'), new ResponseLimitExceeded(30, 4090, 200)],
    );
    Cluster::superuser()->exec('UPDATE data_sources SET max_response_bytes = 4096');

    $id = sfRun($source, $endpoint, sfValues());

    expect($this->curl->limits)->toBe([4096, 4090])
        ->and(sfPoll($id)->json('data.result'))->toMatchArray(['ok' => false, 'code' => 'response-too-large', 'reason' => 'connector.limit_exceeded', 'size_bytes' => 6 + 30, 'limit_bytes' => 4096, 'page' => 2, 'pages' => 1])
        ->and(sfSample($source, $endpoint, $id)->assertNotFound()->getContent())->toBe('')
        ->and(sfRuns()[0])->toMatchArray(['status' => 'failed', 'error_code' => 'response-too-large']);
});

it('fails the whole fetch at the page that fails: nothing stored, no retry, the page named', function (array $third, array $expected) {
    [, $source, $endpoint] = sfPagedWorld(
        ['pagination_style' => 'offset', 'pagination_param' => 'offset', 'pagination_records_path' => 'data'],
        [sfPage('{"data":[1,2]}'), sfPage('{"data":[3]}'), ...$third, sfPage('{"data":[]}')],
    );

    $id = sfRun($source, $endpoint, sfValues());

    expect(sfPoll($id)->json('data.result'))->toMatchArray(['ok' => false, 'page' => 3, 'pages' => 2] + $expected)
        ->and($this->curl->calls)->toHaveCount(3)
        ->and(sfSample($source, $endpoint, $id)->assertNotFound()->getContent())->toBe('')
        ->and(sfRuns())->toHaveCount(1)
        ->and(sfEverything())->not->toContain('CANARY-sf-body');
})->with([
    'a 503' => [[FakeCurl::answer(503, '{"error":"CANARY-sf-body"}')], ['code' => 'fetch-failed', 'reason' => 'http_503', 'status' => 503]],
    'HTML' => [[FakeCurl::answer(200, '<html>CANARY-sf-body</html>', ['content-type' => ['text/html']])], ['code' => 'not-json', 'reason' => 'connector.not_json:content_type']],
    'a missing records path' => [[sfPage('{"other":"CANARY-sf-body"}')], ['code' => 'not-json', 'reason' => 'connector.not_json:records_path']],
    'a timeout' => [[new EgressTransportFailed('x', 28)], ['code' => 'fetch-failed', 'reason' => 'timeout']],
]);

it('shows the offset advancing by the records received', function () {
    [, $source, $endpoint] = sfPagedWorld(
        ['pagination_style' => 'offset', 'pagination_param' => 'offset', 'pagination_records_path' => 'data'],
        [sfPage('{"data":[1,2,3]}'), sfPage('{"data":[4]}'), sfPage('{"data":[]}')],
    );

    sfRun($source, $endpoint, sfValues());

    expect(array_map(fn (array $c): string => substr($c[CURLOPT_URL], strrpos($c[CURLOPT_URL], '&offset=') + 1), $this->curl->calls))->toBe(['offset=0', 'offset=3', 'offset=4']);
});

it('keeps one request and the body untouched for the style none', function () {
    [, $source, $endpoint] = sfPagedWorld(['pagination_style' => 'none'], [sfPage(SF_BODY), sfPage(SF_BODY)]);

    $id = sfRun($source, $endpoint, sfValues());

    expect($this->curl->calls)->toHaveCount(1)
        ->and(sfPoll($id)->json('data.result'))->toMatchArray(['ok' => true, 'page' => null, 'pages' => null])
        ->and(sfSample($source, $endpoint, $id)->json('data.body'))->toBe(SF_BODY);
});
