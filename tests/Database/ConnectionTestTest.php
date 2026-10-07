<?php

use App\Models\User;
use App\Modules\Connector\Contracts\ConnectionTests;
use App\Modules\Connector\Contracts\DataSourceActor;
use App\Modules\Connector\Contracts\DataSourceInput;
use App\Modules\Connector\Contracts\DataSourceUrl;
use App\Modules\Connector\Contracts\EgressTransportFailed;
use App\Modules\Connector\Contracts\HostResolver;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\KeyringMismatch;
use App\Modules\Connector\Contracts\SecretContext;
use App\Modules\Connector\Contracts\SecretMissing;
use App\Modules\Connector\Contracts\SecretRef;
use App\Modules\Connector\Contracts\SecretRefused;
use App\Modules\Connector\Contracts\SecretVault;
use App\Modules\Connector\Infrastructure\CurlClient;
use App\Platform\Operations\Operations;
use App\Platform\Operations\RunOperation;
use App\Platform\Tenancy\WorkspaceTransaction;
use App\Support\Observability\MetricEmitter;
use App\Support\Observability\RequestContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;
use Tests\Unit\Support\FakeCurl;
use Tests\Unit\Support\FakeResolver;

// Story 2.5 against the real PostgreSQL: an Admin with `data_sources.manage` tests what the Data Source form holds. The
// request starts an Operation (nothing is called from the web tier); the worker's job makes one GET through the guard.
// The queue is `sync` here, so the job runs in this process right after the request commits; the curl handler and the
// resolver are fakes (nothing touches the network).
const CT_URL = '/api/v1/admin/data-sources/test-connection';
const CT_HEADERS = ['Referer' => 'http://localhost:8000'];
const CT_PASSWORD = 'admin-password-1';
const CT_CANARY = 'CANARY-ct-7f3a91';

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();

    $pair = sodium_crypto_box_keypair();
    $this->privateKeyFile = tempnam(sys_get_temp_dir(), 'dashflow-key');
    file_put_contents($this->privateKeyFile, base64_encode(sodium_crypto_box_secretkey($pair)));
    // The worker holds the private key: in this process the same file is "mounted".
    config([
        'dashflow.secrets.cred_public_key.value' => base64_encode(sodium_crypto_box_publickey($pair)),
        'dashflow.secrets.cred_key_version.value' => '3',
        'dashflow.secrets.cred_key_path.value' => $this->privateKeyFile,
    ]);

    $this->logFile = tempnam(sys_get_temp_dir(), 'dashflow-log');
    config(['logging.default' => 'single', 'logging.channels.single.path' => $this->logFile]);

    $this->resolver = new FakeResolver(['api.example.com' => ['93.184.216.34'], 'blocked.example.com' => ['127.0.0.1'], 'other.example.com' => ['93.184.216.40']]);
    $this->curl = new FakeCurl;
    app()->instance(HostResolver::class, $this->resolver);
    app()->instance(CurlClient::class, $this->curl);
});

afterEach(function () {
    @unlink($this->privateKeyFile);
    @unlink($this->logFile);
});

/** An Admin of the Workspace holding the given permissions, signed in; the hosts api.example.com and blocked.example.com are allowlisted. @return array{0: int, 1: string} */
function ctAdmin(string $workspaceId, array $permissions = ['data_sources.manage'], string $email = 'ada@example.test', string $role = 'admin'): array
{
    $user = Cluster::user($email);
    Cluster::superuser()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([Hash::make(CT_PASSWORD), $user]);
    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspaceId, $user, $role, 'active']);

    foreach ($permissions as $permission) {
        Cluster::superuser()->prepare('INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
            ->execute([(string) Str::uuid7(), $workspaceId, $membership, $permission]);
    }

    foreach (['api.example.com', 'blocked.example.com'] as $host) {
        Cluster::superuser()->prepare('INSERT INTO host_allowlist_entries (id, workspace_id, host, scheme, port, added_by_membership_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, now(), now()) ON CONFLICT DO NOTHING')
            ->execute([(string) Str::uuid7(), $workspaceId, $host, 'https', 443, $membership]);
    }

    ctSignIn($user, $workspaceId, $role === 'admin' ? 'admin' : 'user');

    return [$user, $membership];
}

function ctSignIn(int $user, string $workspaceId, string $area = 'admin'): void
{
    // A new person: the guards still hold the previous one after a request.
    auth()->forgetGuards();
    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspaceId, 'area' => $area]);
}

function ctBody(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Sales API', 'base_url' => 'https://api.example.com/v1', 'headers' => [], 'timeout_seconds' => null,
        'max_response_bytes' => null, 'max_pages' => null, 'live_capable' => false, 'auth_type' => 'none',
    ];
}

function ctTest(array $overrides = [])
{
    return test()->postJson(CT_URL, ctBody($overrides), CT_HEADERS);
}

function ctPoll(string $id)
{
    return test()->getJson('/api/v1/operations/'.$id, CT_HEADERS);
}

/** @return array<string, mixed> */
function ctOperation(string $id): array
{
    $row = Cluster::rows(Cluster::superuser(), 'select * from operations where id = ?', [$id])[0];
    $row['result'] = $row['result'] === null ? null : json_decode($row['result'], true);

    return $row;
}

/** @return list<array<string, mixed>> */
function ctRuns(): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from sync_runs order by started_at, id');
}

function ctSecrets(): array
{
    return Cluster::rows(Cluster::superuser(), 'select id, data_source_id, operation_id, ephemeral, expires_at, slot from secrets order by slot');
}

/** Everything a test connection could have leaked into, as one string. */
function ctEverything(): string
{
    return json_encode([
        Cluster::rows(Cluster::superuser(), 'select * from operations'),
        Cluster::rows(Cluster::superuser(), 'select * from sync_runs'),
        Cluster::rows(Cluster::superuser(), 'select * from audit_events'),
        Cluster::rows(Cluster::superuser(), 'select * from outbox_events'),
        Cluster::rows(Cluster::superuser(), 'select id, name, auth_type, default_headers, api_key_name, api_key_placement from data_sources'),
        (string) file_get_contents(test()->logFile),
    ], JSON_THROW_ON_ERROR);
}

/** @return list<string> the request headers of the n-th call to the source */
function ctSentHeaders(int $n = 0): array
{
    return test()->curl->calls[$n][CURLOPT_HTTPHEADER] ?? [];
}

it('starts an Operation, runs it on fetch-interactive and reads back status, latency and host for the requester', function () {
    $workspace = Cluster::workspace('Acme');
    [, $membership] = ctAdmin($workspace);
    $this->curl->queue = [FakeCurl::answer(200, str_repeat('x', 321))];

    $response = ctTest()->assertStatus(202);
    $id = $response->json('data.operation_id');

    expect(Str::isUuid($id))->toBeTrue()
        ->and(substr($id, 14, 1))->toBe('7')
        ->and($response->json('data.status'))->toBe('queued')
        ->and($response->getContent())->not->toContain('93.184.216.34');

    $summary = ctPoll($id)->assertOk()->json('data');

    expect($summary['status'])->toBe('succeeded')
        ->and($summary['kind'])->toBe('connection_test')
        ->and($summary['result'])->toMatchArray(['ok' => true, 'status' => 200, 'code' => null, 'reason' => null, 'host' => 'api.example.com'])
        ->and($summary['result']['latency_ms'])->toBeInt()
        ->and($summary['result']['request_id'])->toBeString()
        ->and(array_keys($summary['result']))->toEqualCanonicalizing(['ok', 'status', 'latency_ms', 'code', 'reason', 'host', 'request_id']);

    $operation = ctOperation($id);
    expect($operation)->toMatchArray(['kind' => 'connection_test', 'status' => 'succeeded', 'requester_membership_id' => $membership, 'subject_type' => 'data_source_draft', 'subject_id' => null, 'workspace_id' => $workspace]);

    // One GET to the Base URL, pinned to the address the guard checked.
    expect($this->curl->calls)->toHaveCount(1)
        ->and($this->curl->calls[0][CURLOPT_URL])->toBe('https://api.example.com/v1')
        ->and($this->curl->calls[0][CURLOPT_HTTPGET])->toBeTrue()
        ->and($this->curl->calls[0][CURLOPT_RESOLVE])->toBe(['api.example.com:443:93.184.216.34']);
});

it('records one sync_runs row for the attempt with a sanitised URL template, the numbers and no body', function () {
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);
    $this->curl->queue = [FakeCurl::answer(200, 'BODY-'.CT_CANARY.str_repeat('x', 100))];

    $id = ctTest()->json('data.operation_id');
    $runs = ctRuns();

    expect($runs)->toHaveCount(1)
        ->and($runs[0])->toMatchArray([
            'workspace_id' => $workspace, 'kind' => 'connection_test', 'url_template' => 'https://api.example.com/v1',
            'status' => 'succeeded', 'http_status' => 200, 'bytes' => strlen('BODY-'.CT_CANARY.str_repeat('x', 100)), 'error_code' => null, 'data_source_id' => null,
        ])
        ->and($runs[0]['latency_ms'])->not->toBeNull()
        ->and($runs[0]['request_id'])->toBe(ctPoll($id)->json('data.result.request_id'))
        ->and(ctEverything())->not->toContain(CT_CANARY);

    // The row sits in the partition of its month, and every column is a number, an enum or the sanitised template.
    $partition = Cluster::rows(Cluster::superuser(), 'select tableoid::regclass::text as t from sync_runs')[0]['t'];
    expect($partition)->toBe('sync_runs_y'.gmdate('Y').'m'.gmdate('m'));
});

it('emits platform.operation.completed with IDs and enums only, in the same transaction as the status change', function () {
    $workspace = Cluster::workspace('Acme');
    [, $membership] = ctAdmin($workspace);
    $this->curl->queue = [FakeCurl::answer(200)];

    $id = ctTest()->json('data.operation_id');
    $events = Cluster::rows(Cluster::superuser(), "select * from outbox_events where type = 'platform.operation.completed'");

    expect($events)->toHaveCount(1)
        ->and($events[0]['subject'])->toBe('operation:'.$id)
        ->and($events[0]['actor'])->toBe($membership)
        ->and(json_decode($events[0]['data'], true))->toEqualCanonicalizing(['operation_id' => $id, 'kind' => 'connection_test', 'status' => 'succeeded']);
});

it('never makes the request on the web tier: the request only queues a signed job on fetch-interactive', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);

    $id = ctTest(['auth_type' => 'bearer', 'secrets' => ['bearer_token' => CT_CANARY]])->assertStatus(202)->json('data.operation_id');

    Queue::assertPushedOn('fetch-interactive', RunOperation::class);
    Queue::assertPushed(RunOperation::class, 1);

    $job = Queue::pushed(RunOperation::class)->first();
    expect($this->curl->calls)->toBe([])
        ->and($this->resolver->lookups)->toBe([])
        ->and($job->operationId)->toBe($id)
        ->and($job->workspaceId())->toBe($workspace)
        ->and(ctOperation($id)['status'])->toBe('queued')
        ->and(serialize($job))->not->toContain(CT_CANARY)
        ->and(ctPoll($id)->json('data'))->toMatchArray(['status' => 'queued', 'result' => null]);
});

it('runs a queued job once: running it again changes nothing', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);
    $this->curl->queue = [FakeCurl::answer(200), FakeCurl::answer(200)];

    $id = ctTest()->json('data.operation_id');
    $job = Queue::pushed(RunOperation::class)->first();

    foreach ([1, 2] as $_) {
        app(WorkspaceTransaction::class)->runJob($job, fn ($j) => $j->handle(app(Operations::class)));
    }

    expect($this->curl->calls)->toHaveCount(1)
        ->and(ctOperation($id)['status'])->toBe('succeeded')
        ->and(ctRuns())->toHaveCount(1)
        ->and(Cluster::rows(Cluster::superuser(), "select count(*) as n from outbox_events where type = 'platform.operation.completed'")[0]['n'])->toBe(1);
});

it('uses typed secrets from an unsaved form through a transient row, deletes it at completion and saves nothing', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);
    $this->curl->queue = [FakeCurl::answer(200)];

    $id = ctTest(['auth_type' => 'bearer', 'secrets' => ['bearer_token' => CT_CANARY]])->assertStatus(202)->json('data.operation_id');

    $rows = ctSecrets();
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['data_source_id' => null, 'operation_id' => $id, 'ephemeral' => true, 'slot' => 'bearer_token'])
        ->and($rows[0]['expires_at'])->not->toBeNull()
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from data_sources')[0]['n'])->toBe(0);

    $job = Queue::pushed(RunOperation::class)->first();
    app(WorkspaceTransaction::class)->runJob($job, fn ($j) => $j->handle(app(Operations::class)));

    expect(ctSentHeaders())->toContain('Authorization: Bearer '.CT_CANARY)
        ->and(ctSecrets())->toBe([])
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from data_sources')[0]['n'])->toBe(0)
        ->and(ctPoll($id)->json('data.status'))->toBe('succeeded')
        ->and(ctEverything())->not->toContain(CT_CANARY);
});

it('seals a typed secret so only the worker can open it, and only for its Operation', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);

    $id = ctTest(['auth_type' => 'bearer', 'secrets' => ['bearer_token' => CT_CANARY]])->json('data.operation_id');
    $row = Cluster::rows(Cluster::superuser(), "select id, slot, encode(ciphertext, 'base64') as sealed from secrets")[0];
    $vault = app(SecretVault::class);

    expect(base64_decode($row['sealed'], true))->not->toContain(CT_CANARY)
        ->and($vault->open(new SecretContext($workspace, $id, 'bearer_token'), base64_decode($row['sealed'], true)))->toBe(CT_CANARY)
        ->and(fn () => $vault->open(new SecretContext($workspace, (string) Str::uuid7(), 'bearer_token'), base64_decode($row['sealed'], true)))
        ->toThrow(SecretRefused::class);
});

it('uses a saved source\'s stored secrets for the slots the form does not supply, and typed ones for the rest', function () {
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);
    $saved = $this->postJson('/api/v1/admin/data-sources', ctBody(['auth_type' => 'basic', 'secrets' => ['basic_username' => 'stored-user', 'basic_password' => 'stored-pass'], 'confirm_password' => CT_PASSWORD]), CT_HEADERS)->assertCreated();
    $source = $saved->json('data.data_source_id');
    $this->curl->queue = [FakeCurl::answer(204), FakeCurl::answer(200)];

    // Nothing typed: the stored username and password.
    $first = ctTest(['auth_type' => 'basic', 'data_source_id' => $source])->assertStatus(202)->json('data.operation_id');
    expect(ctSentHeaders(0))->toContain('Authorization: Basic '.base64_encode('stored-user:stored-pass'))
        ->and(ctOperation($first)['subject_type'])->toBe('data_source')
        ->and(ctOperation($first)['subject_id'])->toBe($source)
        ->and(ctOperation($first)['subject_revision'])->toBe(1);

    // The password typed, the username stored.
    ctTest(['auth_type' => 'basic', 'data_source_id' => $source, 'secrets' => ['basic_password' => 'typed-pass']])->assertStatus(202);
    expect(ctSentHeaders(1))->toContain('Authorization: Basic '.base64_encode('stored-user:typed-pass'));

    // The stored rows are untouched and the transient ones are gone.
    $rows = ctSecrets();
    expect(array_column($rows, 'slot'))->toBe(['basic_password', 'basic_username'])
        ->and(array_unique(array_column($rows, 'ephemeral')))->toBe([false])
        ->and(array_unique(array_column($rows, 'data_source_id')))->toBe([$source])
        ->and(ctRuns()[0]['data_source_id'])->toBe($source);
});

it('applies every credential scheme at egress: API key in a header or the query, a secret header, bearer, basic', function (array $form, callable $check) {
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);
    $this->curl->queue = [FakeCurl::answer(200)];

    ctTest($form)->assertStatus(202);

    expect($this->curl->calls)->toHaveCount(1);
    $check($this->curl->calls[0]);
    // Whatever the scheme, no secret reaches the audit, the outbox, the Operation, sync_runs or the log.
    expect(ctEverything())->not->toContain(CT_CANARY);
})->with([
    'api key header' => [
        ['auth_type' => 'api_key', 'api_key_name' => 'X-Api-Key', 'api_key_placement' => 'header', 'secrets' => ['api_key' => CT_CANARY]],
        fn (array $call) => expect($call[CURLOPT_HTTPHEADER])->toContain('X-Api-Key: '.CT_CANARY)->and($call[CURLOPT_URL])->toBe('https://api.example.com/v1'),
    ],
    'api key query' => [
        ['auth_type' => 'api_key', 'api_key_name' => 'key', 'api_key_placement' => 'query', 'secrets' => ['api_key' => CT_CANARY]],
        fn (array $call) => expect($call[CURLOPT_URL])->toBe('https://api.example.com/v1?key='.CT_CANARY),
    ],
    'secret default header and a plain one' => [
        ['headers' => [['name' => 'X-Token', 'secret' => true, 'value' => CT_CANARY], ['name' => 'Accept', 'value' => 'application/json']]],
        fn (array $call) => expect($call[CURLOPT_HTTPHEADER])->toContain('X-Token: '.CT_CANARY)->toContain('Accept: application/json'),
    ],
    'basic' => [
        ['auth_type' => 'basic', 'secrets' => ['basic_username' => 'ada', 'basic_password' => CT_CANARY]],
        fn (array $call) => expect($call[CURLOPT_HTTPHEADER])->toContain('Authorization: Basic '.base64_encode('ada:'.CT_CANARY)),
    ],
]);

it('refuses a form that needs a credential it does not have, before anything is enqueued', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);

    ctTest(['auth_type' => 'bearer'])->assertStatus(422)->assertJsonPath('errors', ['secrets.bearer_token' => ['Enter a value.']])->assertJsonPath('reasons', ['secrets.bearer_token' => 'secret-required']);

    Queue::assertNothingPushed();
    expect(Cluster::rows(Cluster::superuser(), 'select count(*) as n from operations')[0]['n'])->toBe(0)
        ->and(ctSecrets())->toBe([]);
});

it('refuses an unknown or foreign data_source_id with 404 and enqueues nothing', function () {
    Queue::fake();
    $a = Cluster::workspace('Acme');
    $b = Cluster::workspace('Beta');
    ctAdmin($a);
    $foreign = Cluster::seedDataSource($b);

    foreach ([(string) Str::uuid7(), $foreign] as $id) {
        ctTest(['data_source_id' => $id])->assertNotFound();
    }

    Queue::assertNothingPushed();
});

it('answers a host that is not on the allowlist with host-not-allowlisted, audits it and counts it towards the alert', function () {
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);
    config(['dashflow.egress.alert_threshold.value' => '1', 'dashflow.egress.alert_window.value' => '60']);
    $spy = new class implements MetricEmitter
    {
        public array $fired = [];

        public function increment(string $name, array $labels = [], int $by = 1): void
        {
            $this->fired[] = [$name, $labels['reason'] ?? null];
        }
    };
    app()->instance(MetricEmitter::class, $spy);

    foreach ([1, 2] as $_) {
        $id = ctTest(['base_url' => 'https://other.example.com/v1'])->assertStatus(202)->json('data.operation_id');
    }

    $summary = ctPoll($id)->json('data');
    $blocks = Cluster::rows(Cluster::superuser(), "select after_state from audit_events where action = 'connector.egress.blocked'");

    expect($summary['status'])->toBe('failed')
        ->and($summary['result'])->toMatchArray(['ok' => false, 'status' => null, 'code' => 'host-not-allowlisted', 'reason' => 'host_not_allowlisted', 'host' => 'other.example.com'])
        ->and($this->curl->calls)->toBe([])
        ->and($blocks)->toHaveCount(2)
        ->and(json_decode($blocks[0]['after_state'], true))->toMatchArray(['reason' => 'host_not_allowlisted', 'host' => 'other.example.com', 'port' => 443])
        ->and($spy->fired)->toBe([['dashflow.connector.ssrf_blocked', 'host_not_allowlisted']])
        ->and(ctRuns()[1])->toMatchArray(['status' => 'failed', 'error_code' => 'host-not-allowlisted', 'http_status' => null]);
});

it('answers a host that resolves to a blocked address with blocked-address and never shows the address', function () {
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);

    $id = ctTest(['base_url' => 'https://blocked.example.com/v1'])->assertStatus(202)->json('data.operation_id');
    $poll = ctPoll($id);

    expect($poll->json('data.status'))->toBe('failed')
        ->and($poll->json('data.result'))->toMatchArray(['ok' => false, 'code' => 'blocked-address', 'reason' => 'blocked_address', 'host' => 'blocked.example.com'])
        ->and($this->curl->calls)->toBe([])
        ->and(Cluster::rows(Cluster::superuser(), "select count(*) as n from audit_events where action = 'connector.egress.blocked'")[0]['n'])->toBe(1)
        ->and(ctEverything())->not->toContain('127.0.0.1')
        ->and($poll->getContent())->not->toContain('127.0.0.1');
});

it('collapses a timeout, a TLS failure, a transport error and 401, 403 and 5xx answers into fetch-failed with the detail in the summary', function (mixed $answer, ?int $status, string $reason) {
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);
    $this->curl->queue = [$answer instanceof Closure ? $answer() : $answer];

    $id = ctTest()->assertStatus(202)->json('data.operation_id');
    $poll = ctPoll($id);

    expect($poll->json('data.status'))->toBe('failed')
        ->and($poll->json('data.result'))->toMatchArray(['ok' => false, 'status' => $status, 'code' => 'fetch-failed', 'reason' => $reason, 'host' => 'api.example.com'])
        ->and(ctRuns()[0])->toMatchArray(['status' => 'failed', 'error_code' => 'fetch-failed', 'http_status' => $status])
        ->and(Cluster::rows(Cluster::superuser(), "select count(*) as n from audit_events where action = 'connector.egress.blocked'")[0]['n'])->toBe(0)
        ->and($poll->getContent())->not->toContain('93.184.216.34')
        ->and(ctEverything())->not->toContain('93.184.216.34')
        ->and((string) file_get_contents($this->logFile))->toContain('connector.connection_test.failed');
})->with([
    'timeout' => [fn () => new EgressTransportFailed('The request failed (curl error 28).', 28), null, 'timeout'],
    'tls' => [fn () => new EgressTransportFailed('The request failed (curl error 60).', 60), null, 'tls'],
    'refused connection' => [fn () => new EgressTransportFailed('The request failed (curl error 7).', 7), null, 'transport'],
    '401' => [FakeCurl::answer(401), 401, 'http_401'],
    '403' => [FakeCurl::answer(403), 403, 'http_403'],
    '500' => [FakeCurl::answer(500), 500, 'http_500'],
    '503' => [FakeCurl::answer(503), 503, 'http_503'],
]);

it('treats a redirect to another origin as a failed test with the generic code', function () {
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);
    $this->curl->queue = [FakeCurl::redirect('https://other.example.com/x')];

    $id = ctTest()->json('data.operation_id');

    expect(ctPoll($id)->json('data.result'))->toMatchArray(['ok' => false, 'code' => 'fetch-failed', 'reason' => 'redirect_refused']);
});

it('reports a key problem on the worker as fetch-failed with the reason only in the details', function (string $problem, string $reason) {
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);

    // Sealed with the configured public key; the worker then holds no key, or another one.
    if ($problem === 'unavailable') {
        config(['dashflow.secrets.cred_key_path.value' => '/nonexistent/key-cred']);
    } else {
        file_put_contents($this->privateKeyFile, base64_encode(sodium_crypto_box_secretkey(sodium_crypto_box_keypair())));
    }

    $id = ctTest(['auth_type' => 'bearer', 'secrets' => ['bearer_token' => CT_CANARY]])->assertStatus(202)->json('data.operation_id');
    $poll = ctPoll($id);

    expect($poll->json('data.status'))->toBe('failed')
        ->and($poll->json('data.result'))->toMatchArray(['ok' => false, 'code' => 'fetch-failed', 'reason' => $reason])
        ->and($this->curl->calls)->toBe([])
        ->and(ctSecrets())->toBe([])
        ->and(ctEverything())->not->toContain(CT_CANARY);
})->with([
    'key unset' => ['unavailable', 'keyring_unavailable'],
    'key mismatched' => ['mismatch', 'keyring_mismatch'],
]);

it('reports a key version that differs from the stored one as a mismatch', function () {
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);
    $saved = $this->postJson('/api/v1/admin/data-sources', ctBody(['auth_type' => 'bearer', 'secrets' => ['bearer_token' => CT_CANARY], 'confirm_password' => CT_PASSWORD]), CT_HEADERS)->assertCreated();

    config(['dashflow.secrets.cred_key_version.value' => '4']);
    $id = ctTest(['auth_type' => 'bearer', 'data_source_id' => $saved->json('data.data_source_id')])->json('data.operation_id');

    expect(ctPoll($id)->json('data.result'))->toMatchArray(['ok' => false, 'code' => 'fetch-failed', 'reason' => 'keyring_mismatch']);
});

it('answers 429 with retry_after and enqueues nothing when a member is over the per-membership limit', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);
    config(['dashflow.connection_test.membership_limit.value' => '2', 'dashflow.connection_test.window.value' => '60']);

    ctTest()->assertStatus(202);
    ctTest()->assertStatus(202);
    $over = ctTest()->assertStatus(429);

    expect($over->json('error.code'))->toBe('platform.too_many_requests')
        ->and($over->json('error.retry_after'))->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and($over->headers->get('Retry-After'))->toBe((string) $over->json('error.retry_after'));

    Queue::assertPushed(RunOperation::class, 2);
    expect(Cluster::rows(Cluster::superuser(), 'select count(*) as n from operations')[0]['n'])->toBe(2);
});

it('limits per Workspace across members, and another Workspace is unaffected', function () {
    Queue::fake();
    $a = Cluster::workspace('Acme');
    $b = Cluster::workspace('Beta');
    config(['dashflow.connection_test.workspace_limit.value' => '2', 'dashflow.connection_test.window.value' => '60']);

    ctAdmin($a, email: 'ada@example.test');
    ctTest()->assertStatus(202);
    ctAdmin($a, email: 'bob@example.test');
    ctTest()->assertStatus(202);
    ctTest()->assertStatus(429)->assertJsonPath('error.code', 'platform.too_many_requests');

    ctAdmin($b, email: 'eve@example.test');
    ctTest()->assertStatus(202);

    Queue::assertPushed(RunOperation::class, 3);
});

it('does not limit anything while the settings are unset, or the window is', function (array $settings) {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);
    config($settings);

    foreach (range(1, 5) as $_) {
        ctTest()->assertStatus(202);
    }

    Queue::assertPushed(RunOperation::class, 5);
})->with([
    'nothing set' => [[]],
    'limits without a window' => [['dashflow.connection_test.membership_limit.value' => '1', 'dashflow.connection_test.workspace_limit.value' => '1']],
    'a window without limits' => [['dashflow.connection_test.window.value' => '60']],
]);

it('does not count a refused request towards the limit', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);
    config(['dashflow.connection_test.membership_limit.value' => '1', 'dashflow.connection_test.window.value' => '60']);

    ctTest(['auth_type' => 'bearer'])->assertStatus(422);
    ctTest()->assertStatus(202);
    ctTest()->assertStatus(429);
});

it('answers 403 to a request without data_sources.manage and records a security event, and starts nothing', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace, permissions: ['users.manage']);

    ctTest()->assertForbidden();

    Queue::assertNothingPushed();
    $denied = Cluster::rows(Cluster::superuser(), "select after_state from audit_events where action = 'access.admin.denied'");
    expect($denied)->toHaveCount(1)
        ->and(json_decode($denied[0]['after_state'], true))->toMatchArray(['route' => 'api.admin.data-sources.test-connection', 'permission' => 'data_sources.manage', 'reason' => 'permission'])
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from operations')[0]['n'])->toBe(0);
});

it('shows an Operation only to the membership that started it: anyone else gets a 404 with no body', function () {
    $a = Cluster::workspace('Acme');
    $b = Cluster::workspace('Beta');
    [, $ada] = ctAdmin($a, email: 'ada@example.test');
    $this->curl->queue = [FakeCurl::answer(200)];
    $id = ctTest()->json('data.operation_id');

    ctPoll($id)->assertOk();

    // Another member of the same Workspace, even with the same permission.
    ctAdmin($a, email: 'bob@example.test');
    $other = ctPoll($id)->assertNotFound();
    expect($other->getContent())->toBe('');

    // A member of another Workspace.
    ctAdmin($b, email: 'eve@example.test');
    expect(ctPoll($id)->assertNotFound()->getContent())->toBe('');

    // A malformed ID, an unknown ID and an unauthenticated caller.
    expect(ctPoll('not-a-uuid')->assertNotFound()->getContent())->toBe('')
        ->and(ctPoll((string) Str::uuid7())->assertNotFound()->getContent())->toBe('');
    $this->flushSession();
    auth()->forgetGuards();
    $this->getJson('/api/v1/operations/'.$id, CT_HEADERS)->assertUnauthorized();

    // The requester still reads it.
    ctSignIn(Cluster::rows(Cluster::superuser(), 'select user_id from workspace_memberships where id = ?', [$ada])[0]['user_id'], $a);
    ctPoll($id)->assertOk()->assertJsonPath('data.id', $id);
});

it('lets a User without any Admin permission read their own Operation: the status route needs only the requester', function () {
    $workspace = Cluster::workspace('Acme');
    [$user, $membership] = ctAdmin($workspace, permissions: [], role: 'user');
    $id = Cluster::seedOperation($workspace, $membership, 'succeeded');
    ctSignIn($user, $workspace, 'user');

    ctPoll($id)->assertOk()->assertJsonPath('data.status', 'succeeded');
});

it('reads an Operation past its lifetime as expired without its result', function () {
    $workspace = Cluster::workspace('Acme');
    [, $membership] = ctAdmin($workspace);
    $queued = Cluster::seedOperation($workspace, $membership, 'queued', expires: '-1 minute');
    $done = Cluster::seedOperation($workspace, $membership, 'succeeded', expires: '-1 minute');
    Cluster::superuser()->exec("update operations set result = '{\"ok\": true}'::jsonb where id = '{$done}'");

    expect(ctPoll($queued)->json('data'))->toMatchArray(['status' => 'expired', 'result' => null])
        ->and(ctPoll($done)->json('data'))->toMatchArray(['status' => 'expired', 'result' => null]);
});

it('lets the Admin save a Data Source without testing it', function () {
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);

    $this->postJson('/api/v1/admin/data-sources', ctBody(), CT_HEADERS)->assertCreated();

    expect(Cluster::rows(Cluster::superuser(), 'select count(*) as n from operations')[0]['n'])->toBe(0)
        ->and($this->curl->calls)->toBe([]);
});

it('rolls the Operation back with a request that ends in an error, and dispatches nothing', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);
    // No platform key: sealing the typed secret fails after the Operation row was written.
    config(['dashflow.secrets.cred_public_key.value' => null]);

    ctTest(['auth_type' => 'bearer', 'secrets' => ['bearer_token' => CT_CANARY]])->assertStatus(503)->assertJsonPath('error.code', 'connector.secrets_not_configured');

    Queue::assertNothingPushed();
    expect(Cluster::rows(Cluster::superuser(), 'select count(*) as n from operations')[0]['n'])->toBe(0)
        ->and(ctSecrets())->toBe([]);
});

it('refuses an http Base URL when https is required, without enqueuing', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);
    config(['dashflow.tunables.guards.require_https.value' => true]);

    ctTest(['base_url' => 'http://api.example.com/v1'])->assertStatus(422)->assertJsonPath('reasons.base_url', 'https-required');

    Queue::assertNothingPushed();
});

it('ignores an expired transient secret and purges it, for every Workspace', function () {
    $a = Cluster::workspace('Acme');
    $b = Cluster::workspace('Beta');
    foreach ([$a, $b] as $workspace) {
        $operation = (string) Str::uuid7();
        Cluster::superuser()->prepare("insert into secrets (id, workspace_id, data_source_id, operation_id, ephemeral, expires_at, slot, purpose, key_version, key_ref, ciphertext, created_at, updated_at) values (?, ?, null, ?, true, now() - interval '1 minute', 'bearer_token', 'cred', 3, 'x', decode('00ff', 'hex'), now(), now())")
            ->execute([(string) Str::uuid7(), $workspace, $operation]);
        Cluster::superuser()->prepare("insert into secrets (id, workspace_id, data_source_id, operation_id, ephemeral, expires_at, slot, purpose, key_version, key_ref, ciphertext, created_at, updated_at) values (?, ?, null, ?, true, now() + interval '1 hour', 'api_key', 'cred', 3, 'x', decode('00ff', 'hex'), now(), now())")
            ->execute([(string) Str::uuid7(), $workspace, $operation]);
    }

    // Expiry is enforced on read: the expired row is "missing", the live one is a real (here: damaged) row.
    $ids = Cluster::rows(Cluster::superuser(), 'select id, slot from secrets where workspace_id = ? order by slot', [$a]);
    $vault = app(SecretVault::class);
    app(WorkspaceTransaction::class)->run($a, function () use ($vault, $ids, $a) {
        $context = fn (string $slot) => new SecretContext($a, (string) Str::uuid7(), $slot);
        expect(fn () => $vault->resolve(new SecretRef($ids[1]['id'], 'bearer_token'), $context('bearer_token')))->toThrow(SecretMissing::class)
            ->and(fn () => $vault->resolve(new SecretRef($ids[0]['id'], 'api_key'), $context('api_key')))->toThrow(KeyringMismatch::class);
    });

    $this->artisan('dashflow:secrets:purge-expired')->expectsOutputToContain('Removed 2 expired transient secret(s).')->assertSuccessful();

    expect(array_column(Cluster::rows(Cluster::superuser(), 'select slot from secrets order by workspace_id, slot'), 'slot'))->toBe(['api_key', 'api_key']);

    $this->artisan('dashflow:secrets:purge-expired')->expectsOutputToContain('Removed 0 expired transient secret(s).')->assertSuccessful();
});

it('keeps the removal of an Operation\'s transient secrets inside the Workspace of the context', function () {
    $a = Cluster::workspace('Acme');
    $b = Cluster::workspace('Beta');
    $operation = (string) Str::uuid7();

    // The unique key is (operation, slot): another Workspace's row for the same ID can only hold another slot.
    foreach ([[$a, 'bearer_token'], [$b, 'api_key']] as [$workspace, $slot]) {
        Cluster::superuser()->prepare("insert into secrets (id, workspace_id, data_source_id, operation_id, ephemeral, expires_at, slot, purpose, key_version, key_ref, ciphertext, created_at, updated_at) values (?, ?, null, ?, true, now() + interval '1 hour', ?, 'cred', 3, 'x', decode('00ff', 'hex'), now(), now())")
            ->execute([(string) Str::uuid7(), $workspace, $operation, $slot]);
    }

    $removed = Cluster::inWorkspace(Cluster::pooledApp(), $a, fn ($pdo) => Cluster::rows($pdo, 'select connector_remove_operation_secrets(?::uuid) as n', [$operation])[0]['n']);

    expect($removed)->toBe(1)
        ->and(Cluster::rows(Cluster::superuser(), 'select workspace_id from secrets')[0]['workspace_id'])->toBe($b)
        ->and(fn () => Cluster::rows(Cluster::pooledApp(), 'select connector_remove_operation_secrets(?::uuid)', [$operation]))->toThrow(PDOException::class, 'Workspace context');
});

it('refuses at the database a transient row that breaks the rules: no owner, no expiry, or a Data Source', function (string $columns, array $values) {
    $workspace = Cluster::workspace('Acme');
    $source = Cluster::seedDataSource($workspace);
    $statement = Cluster::superuser()->prepare("insert into secrets (id, workspace_id, slot, purpose, key_version, key_ref, ciphertext, created_at, updated_at, {$columns}) values (?, ?, 'bearer_token', 'cred', 1, 'x', decode('00ff', 'hex'), now(), now(), ".implode(', ', array_fill(0, count($values), '?')).')');

    expect(fn () => $statement->execute([(string) Str::uuid7(), $workspace, ...array_map(fn ($v) => $v === '{source}' ? $source : ($v === '{op}' ? (string) Str::uuid7() : $v), $values)]))
        ->toThrow(PDOException::class, 'secrets_ephemeral_check');
})->with([
    'ephemeral without an owner' => ['ephemeral, expires_at, data_source_id', ['t', '2030-01-01', null]],
    'ephemeral without an expiry' => ['ephemeral, operation_id, data_source_id', ['t', '{op}', null]],
    'ephemeral with a Data Source' => ['ephemeral, operation_id, expires_at, data_source_id', ['t', '{op}', '2030-01-01', '{source}']],
    'stored without a Data Source' => ['ephemeral, data_source_id', ['f', null]],
    'stored with an owner' => ['ephemeral, operation_id, data_source_id', ['f', '{op}', '{source}']],
]);

it('allows one transient row per Operation and slot, and refuses a second', function () {
    $workspace = Cluster::workspace('Acme');
    $operation = (string) Str::uuid7();
    $insert = fn () => Cluster::superuser()->prepare("insert into secrets (id, workspace_id, data_source_id, operation_id, ephemeral, expires_at, slot, purpose, key_version, key_ref, ciphertext, created_at, updated_at) values (?, ?, null, ?, true, now() + interval '1 hour', 'bearer_token', 'cred', 3, 'x', decode('00ff', 'hex'), now(), now())")
        ->execute([(string) Str::uuid7(), $workspace, $operation]);

    $insert();

    expect($insert)->toThrow(PDOException::class, 'secrets_operation_slot_unique');
});

it('refuses the request after N tests in one window: the N+1th is a 429 and nothing more is enqueued', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);
    config(['dashflow.connection_test.membership_limit.value' => '3', 'dashflow.connection_test.window.value' => '60']);

    foreach (range(1, 3) as $_) {
        ctTest()->assertStatus(202);
    }

    ctTest()->assertStatus(429)->assertJsonPath('error.code', 'platform.too_many_requests');
    ctTest()->assertStatus(429);

    Queue::assertPushed(RunOperation::class, 3);
});

it('keeps the real result when the sync_runs write fails, logs the failure and still cleans up', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);
    $this->curl->queue = [FakeCurl::answer(200)];

    $id = ctTest(['auth_type' => 'bearer', 'secrets' => ['bearer_token' => CT_CANARY]])->json('data.operation_id');
    Cluster::superuser()->exec('alter table sync_runs add constraint ct_force_fail check (false)');

    try {
        $job = Queue::pushed(RunOperation::class)->first();
        app(WorkspaceTransaction::class)->runJob($job, fn ($j) => $j->handle(app(Operations::class)));
    } finally {
        Cluster::superuser()->exec('alter table sync_runs drop constraint ct_force_fail');
    }

    $operation = ctOperation($id);
    expect($operation['status'])->toBe('succeeded')
        ->and($operation['result'])->toMatchArray(['ok' => true, 'status' => 200])
        ->and($operation['result']['latency_ms'])->toBeInt()
        ->and(ctRuns())->toBe([])
        ->and(ctSecrets())->toBe([])
        ->and((string) file_get_contents($this->logFile))->toContain('connector.connection_test.sync_run_failed');
});

it('reports the request ID stored on the Operation, not the one of the worker\'s context', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);
    $this->curl->queue = [FakeCurl::answer(200)];

    $id = ctTest()->json('data.operation_id');
    $stored = ctOperation($id)['request_id'];
    app(RequestContext::class)->begin('worker-context-0001');

    $job = Queue::pushed(RunOperation::class)->first();
    app(WorkspaceTransaction::class)->runJob($job, fn ($j) => $j->handle(app(Operations::class)));

    expect($stored)->not->toBe('worker-context-0001')
        ->and(ctOperation($id)['result']['request_id'])->toBe($stored)
        ->and(ctRuns()[0]['request_id'])->toBe($stored);
});

it('refuses an empty typed secret as secret-required, without enqueuing', function () {
    Queue::fake();
    $workspace = Cluster::workspace('Acme');
    [, $membership] = ctAdmin($workspace);
    $input = new DataSourceInput(
        'x', DataSourceUrl::parse('https://api.example.com/v1'), [], null, null, null, false, 'bearer', null, null, ['bearer_token' => ''],
    );

    expect(fn () => app(ConnectionTests::class)->start(new DataSourceActor($membership, $workspace), $input))
        ->toThrow(InvalidDataSource::class);

    Queue::assertNothingPushed();
    expect(ctSecrets())->toBe([]);
});

it('sends a default header with secret: false as a plain header, like the transport does', function () {
    $workspace = Cluster::workspace('Acme');
    ctAdmin($workspace);
    $this->curl->queue = [FakeCurl::answer(200)];

    ctTest(['headers' => [['name' => 'X-Plain', 'value' => 'v1', 'secret' => false]]])->assertStatus(202);

    expect(ctSentHeaders())->toContain('X-Plain: v1');
});

it('rolls the transient-secrets migration back even when transient rows exist', function () {
    $workspace = Cluster::workspace('Acme');
    Cluster::superuser()->prepare("insert into secrets (id, workspace_id, data_source_id, operation_id, ephemeral, expires_at, slot, purpose, key_version, key_ref, ciphertext, created_at, updated_at) values (?, ?, null, ?, true, now() + interval '1 hour', 'bearer_token', 'cred', 3, 'x', decode('00ff', 'hex'), now(), now())")
        ->execute([(string) Str::uuid7(), $workspace, (string) Str::uuid7()]);

    try {
        $code = Artisan::call('migrate:rollback', ['--database' => 'migrator', '--step' => 1, '--force' => true]);
        expect($code)->toBe(0)
            ->and(Cluster::rows(Cluster::superuser(), "select count(*) as n from information_schema.columns where table_name = 'secrets' and column_name = 'ephemeral'")[0]['n'])->toBe(0)
            ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from secrets')[0]['n'])->toBe(0);
    } finally {
        Artisan::call('migrate', ['--database' => 'migrator', '--force' => true]);
    }

    expect(Cluster::rows(Cluster::superuser(), "select count(*) as n from information_schema.columns where table_name = 'secrets' and column_name = 'ephemeral'")[0]['n'])->toBe(1);
});
