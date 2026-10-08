<?php

use App\Models\User;
use App\Modules\Connector\Contracts\EgressTransportFailed;
use App\Modules\Connector\Contracts\HostResolver;
use App\Modules\Connector\Contracts\SecretContext;
use App\Modules\Connector\Contracts\SecretVault;
use App\Modules\Connector\Infrastructure\CurlClient;
use App\Platform\Tenancy\TenantKey;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;
use Tests\Unit\Support\FakeCurl;
use Tests\Unit\Support\FakeResolver;

// Story 2.7 against the real PostgreSQL: an Admin sets OAuth2 client credentials (token URL, client ID, a write-only
// client secret, an optional scope); Test connection gets a token through the guard, caches it encrypted under the
// secret's version, refreshes it once on a 401 and reports a second 401 as authentication failed. The curl handler and
// the resolver are fakes (nothing touches the network); the queue is `sync`, so the job runs inside the request.
const OA_URL = '/api/v1/admin/data-sources';
const OA_HEADERS = ['Referer' => 'http://localhost:8000'];
const OA_PASSWORD = 'admin-password-1';
const OA_TOKEN_URL = 'https://auth.example.com/oauth/token';
const OA_SECRET = 'CANARY-client-secret-77c1';
const OA_TOKEN = 'CANARY-access-token-3b9e';

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();

    $pair = sodium_crypto_box_keypair();
    $this->privateKeyFile = tempnam(sys_get_temp_dir(), 'dashflow-key');
    $this->tokenKeyFile = tempnam(sys_get_temp_dir(), 'dashflow-token-key');
    file_put_contents($this->privateKeyFile, base64_encode(sodium_crypto_box_secretkey($pair)));
    file_put_contents($this->tokenKeyFile, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    config([
        'dashflow.secrets.cred_public_key.value' => base64_encode(sodium_crypto_box_publickey($pair)),
        'dashflow.secrets.cred_key_version.value' => '3',
        'dashflow.secrets.cred_key_path.value' => $this->privateKeyFile,
        'dashflow.secrets.token_key_path.value' => $this->tokenKeyFile,
    ]);

    $this->logFile = tempnam(sys_get_temp_dir(), 'dashflow-log');
    config(['logging.default' => 'single', 'logging.channels.single.path' => $this->logFile]);

    $this->resolver = new FakeResolver([
        'api.example.com' => ['93.184.216.34'], 'auth.example.com' => ['93.184.216.50'], 'evil.example.com' => ['127.0.0.1'], 'other.example.com' => ['93.184.216.60'],
    ]);
    $this->curl = new FakeCurl;
    app()->instance(HostResolver::class, $this->resolver);
    app()->instance(CurlClient::class, $this->curl);
});

afterEach(function () {
    @unlink($this->privateKeyFile);
    @unlink($this->tokenKeyFile);
    @unlink($this->logFile);
});

/** An Admin with `data_sources.manage`; api.example.com, auth.example.com and evil.example.com are allowlisted. */
function oaAdmin(string $workspaceId, array $hosts = ['api.example.com', 'auth.example.com', 'evil.example.com']): array
{
    $user = Cluster::user('ada@example.test');
    Cluster::superuser()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([Hash::make(OA_PASSWORD), $user]);
    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspaceId, $user, 'admin', 'active']);
    Cluster::superuser()->prepare('INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
        ->execute([(string) Str::uuid7(), $workspaceId, $membership, 'data_sources.manage']);

    foreach ($hosts as $host) {
        Cluster::seedHostEntry($workspaceId, $host);
    }

    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspaceId, 'area' => 'admin']);

    return [$user, $membership];
}

function oaBody(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Sales API', 'base_url' => 'https://api.example.com/v1', 'headers' => [], 'timeout_seconds' => null,
        'max_response_bytes' => null, 'max_pages' => null, 'live_capable' => false, 'auth_type' => 'oauth2_client_credentials',
        'oauth_token_url' => OA_TOKEN_URL, 'oauth_client_id' => 'client-1', 'oauth_scope' => 'read write',
        'secrets' => ['oauth_client_secret' => OA_SECRET], 'confirm_password' => OA_PASSWORD,
    ];
}

function oaCreate(array $overrides = [])
{
    return test()->postJson(OA_URL, oaBody($overrides), OA_HEADERS);
}

function oaUpdate(string $id, int $revision, array $overrides = [])
{
    return test()->putJson(OA_URL."/{$id}", oaBody($overrides) + ['revision' => $revision], OA_HEADERS);
}

/** A saved OAuth2 source: its ID. */
function oaSource(array $overrides = []): string
{
    return oaCreate($overrides)->assertCreated()->json('data.data_source_id');
}

function oaTest(string $id, array $overrides = [])
{
    // A test never carries the password; the stored secret is used for the slot the form leaves out.
    $body = oaBody($overrides + ['data_source_id' => $id, 'secrets' => []]);
    unset($body['confirm_password']);

    return test()->postJson(OA_URL.'/test-connection', $body, OA_HEADERS);
}

function oaResult(string $operationId): array
{
    return test()->getJson('/api/v1/operations/'.$operationId, OA_HEADERS)->assertOk()->json('data.result');
}

function oaTokenAnswer(string $token = OA_TOKEN, ?string $expires = '3600')
{
    return FakeCurl::answer(200, '{"access_token":"'.$token.'","token_type":"Bearer"'.($expires === null ? '' : ',"expires_in":'.$expires).'}');
}

/**
 * The sync_runs rows (of one kind, or all), oldest first by when they were written: a connection test's own row starts
 * before its token request but is written after it.
 *
 * @return list<array<string, mixed>>
 */
function oaRuns(?string $kind = null): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from sync_runs where (?::text is null or kind = ?) order by created_at, started_at desc, kind desc', [$kind, $kind]);
}

function oaSecretRows(): array
{
    return Cluster::rows(Cluster::superuser(), "select id, data_source_id, slot, version, key_version, encode(ciphertext, 'base64') as sealed from secrets order by slot");
}

function oaAudits(?string $action = null): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from audit_events where (?::text is null or action = ?) order by occurred_at, id', [$action, $action]);
}

function oaEverything(): string
{
    return json_encode([
        Cluster::rows(Cluster::superuser(), 'select * from operations'),
        Cluster::rows(Cluster::superuser(), 'select * from sync_runs'),
        Cluster::rows(Cluster::superuser(), 'select * from audit_events'),
        Cluster::rows(Cluster::superuser(), 'select * from outbox_events'),
        Cluster::rows(Cluster::superuser(), 'select id, name, auth_type, default_headers, api_key_name, oauth_token_url, oauth_client_id, oauth_scope from data_sources'),
        (string) file_get_contents(test()->logFile),
    ], JSON_THROW_ON_ERROR);
}

/** The sealed value the token cache holds for a source and secret version, as the cache store keeps it. */
function oaCached(string $workspace, string $id, int $version): mixed
{
    return Cache::get(TenantKey::cache($workspace, "oauth:{$id}:{$version}"));
}

// ---- Data model, validation and write-only secret ---------------------------------------------------------------

it('saves OAuth2 client credentials: plain token URL, client ID and scope, a sealed client secret at version 1, only {configured, updated_at} returned', function () {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);

    $response = oaCreate()->assertCreated();

    expect($response->json('data.auth_type'))->toBe('oauth2_client_credentials')
        ->and($response->json('data.oauth_token_url'))->toBe(OA_TOKEN_URL)
        ->and($response->json('data.oauth_client_id'))->toBe('client-1')
        ->and($response->json('data.oauth_scope'))->toBe('read write')
        ->and(array_keys($response->json('data.secrets')))->toBe(['oauth_client_secret'])
        ->and(array_keys($response->json('data.secrets.oauth_client_secret')))->toBe(['configured', 'updated_at'])
        ->and($response->json('data.secrets.oauth_client_secret.configured'))->toBeTrue()
        ->and($response->getContent())->not->toContain(OA_SECRET);

    $rows = oaSecretRows();
    $id = $response->json('data.data_source_id');
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['data_source_id' => $id, 'slot' => 'oauth_client_secret', 'version' => 1, 'key_version' => 3])
        ->and(base64_decode($rows[0]['sealed'], true))->not->toContain(OA_SECRET)
        ->and(app(SecretVault::class)->open(new SecretContext($workspace, $id, 'oauth_client_secret'), base64_decode($rows[0]['sealed'], true)))->toBe(OA_SECRET);

    $changed = oaAudits('connector.data_source.secret_changed');
    expect($changed)->toHaveCount(1)
        ->and(json_decode($changed[0]['after_state'], true))->toMatchArray(['slot' => 'oauth_client_secret', 'purpose' => 'cred', 'key_version' => 3, 'action' => 'set'])
        ->and(oaEverything())->not->toContain(OA_SECRET);

    // The read returns the same fields, and a list leaves the secrets out.
    $one = test()->getJson(OA_URL.'/'.$id, OA_HEADERS)->assertOk();
    expect($one->json('data.oauth_client_id'))->toBe('client-1')->and($one->getContent())->not->toContain(OA_SECRET);
});

it('needs the password to set the client secret, and a scope and client ID of the right shape', function () {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);

    oaCreate(['confirm_password' => null])->assertStatus(422)->assertJsonValidationErrors('confirm_password');
    oaCreate(['confirm_password' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('confirm_password');
    oaCreate(['oauth_client_id' => ''])->assertStatus(422)->assertJsonPath('reasons.oauth_client_id', 'oauth-client-id-required');
    oaCreate(['oauth_client_id' => "a b\n"])->assertStatus(422)->assertJsonPath('reasons.oauth_client_id', 'oauth-client-id-invalid');
    oaCreate(['oauth_scope' => 'read  write'])->assertStatus(422)->assertJsonPath('reasons.oauth_scope', 'oauth-scope-invalid');
    oaCreate(['oauth_scope' => 'read"write'])->assertStatus(422)->assertJsonPath('reasons.oauth_scope', 'oauth-scope-invalid');
    expect(oaCreate(['secrets' => []])->assertStatus(422)->json('reasons')['secrets.oauth_client_secret'])->toBe('secret-required');
    oaCreate(['secrets' => ['oauth_client_secret' => '']])->assertStatus(422);

    expect(Cluster::rows(Cluster::superuser(), 'select count(*) as n from data_sources')[0]['n'])->toBe(0)
        ->and(oaSecretRows())->toBe([]);

    // The scope is optional.
    oaCreate(['oauth_scope' => null])->assertCreated()->assertJsonPath('data.oauth_scope', null);
});

it('validates the token URL as a Base URL on save: scheme, userinfo, query, fragment, the allowlist and require_https', function (string $url, string $reason) {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    config(['dashflow.tunables.guards.require_https.value' => true]);

    oaCreate(['oauth_token_url' => $url])->assertStatus(422)->assertJsonPath('reasons.oauth_token_url', $reason);

    expect(Cluster::rows(Cluster::superuser(), 'select count(*) as n from data_sources')[0]['n'])->toBe(0);
})->with([
    'ftp' => ['ftp://auth.example.com/token', 'scheme'],
    'userinfo' => ['https://user:pw@auth.example.com/token', 'userinfo'],
    'query' => ['https://auth.example.com/token?a=b', 'query'],
    'fragment' => ['https://auth.example.com/token#x', 'fragment'],
    'not allowlisted' => ['https://unknown.example.com/token', 'host-not-allowlisted'],
    'plain http while https is required' => ['http://auth.example.com/token', 'https-required'],
    'empty' => ['', 'empty'],
]);

it('checks the token URL on blur through the check endpoint, not refusing its name as a secret', function () {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);

    test()->postJson(OA_URL.'/check-url', ['oauth_token_url' => OA_TOKEN_URL], OA_HEADERS)->assertOk()->assertJsonPath('data.allowed', true);
    test()->postJson(OA_URL.'/check-url', ['oauth_token_url' => 'https://unknown.example.com/token'], OA_HEADERS)
        ->assertStatus(422)->assertJsonPath('reasons.oauth_token_url', 'host-not-allowlisted')->assertJsonMissingPath('reasons.base_url');
    test()->postJson(OA_URL.'/check-url', ['oauth_token_url' => 'nonsense'], OA_HEADERS)
        ->assertStatus(422)->assertJsonPath('reasons.oauth_token_url', 'malformed');
    // The Base URL check is unchanged, and a secret value is still refused.
    test()->postJson(OA_URL.'/check-url', ['base_url' => 'https://unknown.example.com'], OA_HEADERS)->assertStatus(422)->assertJsonPath('reasons.base_url', 'host-not-allowlisted');
    test()->postJson(OA_URL.'/check-url', ['oauth_token_url' => OA_TOKEN_URL, 'secrets' => ['oauth_client_secret' => OA_SECRET]], OA_HEADERS)
        ->assertStatus(422)->assertJsonPath('error.code', 'connector.secret_values_refused');
});

it('raises secret_version on every Replace and audits it as replaced', function () {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    $id = oaSource();

    oaUpdate($id, 1, ['secrets' => ['oauth_client_secret' => 'CANARY-second-secret']])->assertOk()->assertJsonPath('data.revision', 2);
    oaUpdate($id, 2, ['secrets' => ['oauth_client_secret' => 'CANARY-third-secret']])->assertOk();

    expect(oaSecretRows()[0]['version'])->toBe(3)
        ->and(array_map(fn (array $row): string => json_decode($row['after_state'], true)['action'], oaAudits('connector.data_source.secret_changed')))->toBe(['set', 'replaced', 'replaced'])
        ->and(oaEverything())->not->toContain('CANARY-second-secret')->not->toContain('CANARY-third-secret');

    // An edit that leaves the secret alone keeps its version.
    oaUpdate($id, 3, ['secrets' => [], 'confirm_password' => null, 'name' => 'Renamed'])->assertOk();
    expect(oaSecretRows()[0]['version'])->toBe(3);
});

it('needs the password to change the token URL or client ID of a source that holds a client secret, but not the scope or the name', function () {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    Cluster::seedHostEntry($workspace, 'other.example.com');
    $id = oaSource();
    $quiet = ['secrets' => [], 'confirm_password' => null];

    oaUpdate($id, 1, $quiet + ['oauth_token_url' => 'https://other.example.com/token'])->assertStatus(422)->assertJsonValidationErrors('confirm_password');
    oaUpdate($id, 1, $quiet + ['oauth_client_id' => 'client-2'])->assertStatus(422)->assertJsonValidationErrors('confirm_password');
    expect(Cluster::rows(Cluster::superuser(), 'select oauth_token_url, oauth_client_id, revision from data_sources')[0])->toMatchArray(['oauth_token_url' => OA_TOKEN_URL, 'oauth_client_id' => 'client-1', 'revision' => 1]);

    oaUpdate($id, 1, $quiet + ['oauth_scope' => 'read', 'name' => 'Renamed'])->assertOk()->assertJsonPath('data.oauth_scope', 'read');
    oaUpdate($id, 2, ['secrets' => [], 'oauth_token_url' => 'https://other.example.com/token', 'oauth_client_id' => 'client-2'])->assertOk()
        ->assertJsonPath('data.oauth_token_url', 'https://other.example.com/token')->assertJsonPath('data.oauth_client_id', 'client-2');
});

it('removes the client secret and clears the OAuth fields when the auth type changes', function () {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    $id = oaSource();

    oaUpdate($id, 1, ['auth_type' => 'none', 'secrets' => []])->assertOk()
        ->assertJsonPath('data.oauth_token_url', null)->assertJsonPath('data.oauth_client_id', null)->assertJsonPath('data.oauth_scope', null);

    expect(oaSecretRows())->toBe([])
        ->and(array_map(fn (array $row): string => json_decode($row['after_state'], true)['action'], oaAudits('connector.data_source.secret_changed')))->toBe(['set', 'removed']);
});

it('keeps the OAuth fields consistent with the auth type in the database itself, and accepts the client secret slot only as written', function () {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    $id = oaSource();
    $super = Cluster::superuser();

    $try = fn (string $sql, array $bindings = []): bool => (function () use ($super, $sql, $bindings): bool {
        try {
            $super->prepare($sql)->execute($bindings);

            return true;
        } catch (PDOException) {
            return false;
        }
    })();

    // OAuth needs its URL and client ID; another type may carry none of the three; a version is at least 1.
    expect($try('UPDATE data_sources SET oauth_token_url = NULL WHERE id = ?', [$id]))->toBeFalse()
        ->and($try('UPDATE data_sources SET oauth_client_id = NULL WHERE id = ?', [$id]))->toBeFalse()
        ->and($try("UPDATE data_sources SET auth_type = 'none' WHERE id = ?", [$id]))->toBeFalse()
        ->and($try('UPDATE data_sources SET oauth_scope = NULL WHERE id = ?', [$id]))->toBeTrue()
        ->and($try('UPDATE secrets SET version = 0 WHERE data_source_id = ?', [$id]))->toBeFalse()
        ->and($try("UPDATE secrets SET slot = 'oauth_client_secrets' WHERE data_source_id = ?", [$id]))->toBeFalse();
});

// ---- Test connection --------------------------------------------------------------------------------------------

it('gets a token through the guard, caches it encrypted per secret version and calls the API with the Bearer', function () {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    $id = oaSource();
    $this->curl->queue = [oaTokenAnswer(), FakeCurl::answer(200, '{"ok":true}')];

    $result = oaResult(oaTest($id)->assertStatus(202)->json('data.operation_id'));

    expect($result)->toMatchArray(['ok' => true, 'status' => 200, 'code' => null, 'host' => 'api.example.com'])
        ->and($this->curl->calls)->toHaveCount(2);

    // The token request: a POST to the token URL, pinned, form-encoded, no redirect followed.
    $token = $this->curl->calls[0];
    parse_str($token[CURLOPT_POSTFIELDS], $form);
    expect($token[CURLOPT_URL])->toBe(OA_TOKEN_URL)
        ->and($token[CURLOPT_CUSTOMREQUEST])->toBe('POST')
        ->and($token[CURLOPT_RESOLVE])->toBe(['auth.example.com:443:93.184.216.50'])
        ->and($token[CURLOPT_FOLLOWLOCATION])->toBeFalse()
        ->and($token[CURLOPT_HTTPHEADER])->toContain('Content-Type: application/x-www-form-urlencoded')
        ->and($form)->toBe(['grant_type' => 'client_credentials', 'client_id' => 'client-1', 'client_secret' => OA_SECRET, 'scope' => 'read write']);

    // The API call carries the Bearer.
    expect($this->curl->calls[1][CURLOPT_URL])->toBe('https://api.example.com/v1')
        ->and($this->curl->calls[1][CURLOPT_HTTPHEADER])->toContain('Authorization: Bearer '.OA_TOKEN);

    // The cache holds the token encrypted under oauth:{ds}:{version}: never in the clear.
    $cached = oaCached($workspace, $id, 1);
    expect($cached['workspace_id'])->toBe($workspace)
        ->and(json_encode($cached))->not->toContain(OA_TOKEN);

    // Each attempt is its own sync_runs row: the token request and the connection test.
    $runs = oaRuns();
    expect(array_column($runs, 'kind'))->toBe(['oauth_token', 'connection_test'])
        ->and($runs[0])->toMatchArray(['url_template' => OA_TOKEN_URL, 'status' => 'succeeded', 'http_status' => 200, 'error_code' => null, 'data_source_id' => $id])
        ->and($runs[1])->toMatchArray(['status' => 'succeeded', 'http_status' => 200]);
});

it('uses the cached token on the next test without a token request', function () {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    $id = oaSource();
    $this->curl->queue = [oaTokenAnswer(), FakeCurl::answer(200), FakeCurl::answer(200)];

    oaTest($id)->assertStatus(202);
    oaTest($id)->assertStatus(202);

    expect($this->curl->calls)->toHaveCount(3)
        ->and($this->curl->calls[2][CURLOPT_URL])->toBe('https://api.example.com/v1')
        ->and($this->curl->calls[2][CURLOPT_HTTPHEADER])->toContain('Authorization: Bearer '.OA_TOKEN)
        ->and(array_column(oaRuns(), 'kind'))->toBe(['oauth_token', 'connection_test', 'connection_test']);
});

it('does not use a cached token after a Replace: the next test asks for a new one', function () {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    $id = oaSource();
    $this->curl->queue = [oaTokenAnswer('first-token'), FakeCurl::answer(200), oaTokenAnswer('second-token'), FakeCurl::answer(200)];

    oaTest($id)->assertStatus(202);
    oaUpdate($id, 1, ['secrets' => ['oauth_client_secret' => 'CANARY-replacement']])->assertOk();
    oaTest($id)->assertStatus(202);

    expect($this->curl->calls)->toHaveCount(4)
        ->and($this->curl->calls[2][CURLOPT_URL])->toBe(OA_TOKEN_URL)
        ->and($this->curl->calls[3][CURLOPT_HTTPHEADER])->toContain('Authorization: Bearer second-token')
        ->and(oaCached($workspace, $id, 1))->not->toBeNull()
        ->and(oaCached($workspace, $id, 2))->not->toBeNull();
    parse_str($this->curl->calls[2][CURLOPT_POSTFIELDS], $form);
    expect($form['client_secret'])->toBe('CANARY-replacement');
});

it('refreshes the token once on a 401 and retries the call once', function () {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    $id = oaSource();
    $this->curl->queue = [oaTokenAnswer('stale-token'), FakeCurl::answer(401), oaTokenAnswer('fresh-token'), FakeCurl::answer(200)];

    $result = oaResult(oaTest($id)->assertStatus(202)->json('data.operation_id'));

    expect($result)->toMatchArray(['ok' => true, 'status' => 200, 'code' => null])
        ->and($this->curl->calls)->toHaveCount(4)
        ->and($this->curl->calls[1][CURLOPT_HTTPHEADER])->toContain('Authorization: Bearer stale-token')
        ->and($this->curl->calls[3][CURLOPT_HTTPHEADER])->toContain('Authorization: Bearer fresh-token')
        ->and(array_column(oaRuns(), 'kind'))->toBe(['oauth_token', 'oauth_token', 'connection_test']);
});

it('reports a second 401 as authentication failed and never retries again', function () {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    $id = oaSource();
    $this->curl->queue = [oaTokenAnswer('a'), FakeCurl::answer(401), oaTokenAnswer('b'), FakeCurl::answer(401), oaTokenAnswer('c'), FakeCurl::answer(200)];

    $result = oaResult(oaTest($id)->assertStatus(202)->json('data.operation_id'));

    expect($result)->toMatchArray(['ok' => false, 'code' => 'auth-failed'])
        ->and($result['reason'])->toBe('connector.auth_failed:api_401')
        ->and($this->curl->calls)->toHaveCount(4)
        ->and(array_column(oaRuns(), 'error_code'))->toBe([null, null, 'auth-failed']);
});

it('reports a token endpoint answer of 400 or 401 as authentication failed', function (int $status) {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    $id = oaSource();
    $this->curl->queue = [FakeCurl::answer($status, '{"error":"invalid_client","error_description":"'.OA_SECRET.'"}')];

    $result = oaResult(oaTest($id)->assertStatus(202)->json('data.operation_id'));

    expect($result)->toMatchArray(['ok' => false, 'code' => 'auth-failed'])
        ->and($this->curl->calls)->toHaveCount(1)
        ->and(oaRuns()[0])->toMatchArray(['kind' => 'oauth_token', 'status' => 'failed', 'http_status' => $status, 'error_code' => 'auth-failed'])
        ->and(oaEverything())->not->toContain(OA_SECRET);
})->with([400, 401]);

it('maps a bad token answer to not-json, response-too-large or fetch-failed, with no retry', function (array $answers, string $code) {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    $id = oaSource();
    config(['dashflow.tunables.guards.max_bytes.value' => 300]);
    $this->curl->queue = $answers;

    $result = oaResult(oaTest($id)->assertStatus(202)->json('data.operation_id'));

    expect($result)->toMatchArray(['ok' => false, 'code' => $code])
        ->and($this->curl->calls)->toHaveCount(1)
        ->and(oaRuns()[0]['kind'])->toBe('oauth_token')
        ->and(oaRuns()[0]['error_code'])->toBe($code);
})->with([
    'HTML' => [[FakeCurl::answer(200, '<html>login</html>', ['content-type' => ['text/html']])], 'not-json'],
    'malformed JSON' => [[FakeCurl::answer(200, '{"access_token":')], 'not-json'],
    'a redirect' => [[FakeCurl::redirect('https://auth.example.com/other')], 'fetch-failed'],
    'a redirect to another host' => [[FakeCurl::redirect('https://other.example.com/token', 307)], 'fetch-failed'],
    'a server error' => [[FakeCurl::answer(503, '{}')], 'fetch-failed'],
    'a token that is not a string' => [[FakeCurl::answer(200, '{"access_token":123,"token_type":"bearer"}')], 'fetch-failed'],
    'another token type' => [[FakeCurl::answer(200, '{"access_token":"a","token_type":"mac"}')], 'fetch-failed'],
    'a timeout' => [[new EgressTransportFailed('x', 28)], 'fetch-failed'],
]);

it('refuses a token URL that resolves to a blocked address, and audits the guard block', function () {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    $id = oaSource(['oauth_token_url' => 'https://evil.example.com/token']);

    $result = oaResult(oaTest($id, ['oauth_token_url' => 'https://evil.example.com/token'])->assertStatus(202)->json('data.operation_id'));

    expect($result)->toMatchArray(['ok' => false, 'code' => 'blocked-address'])
        ->and($this->curl->calls)->toBe([])
        ->and(oaAudits('connector.egress.blocked'))->toHaveCount(1)
        ->and(oaRuns()[0])->toMatchArray(['kind' => 'oauth_token', 'error_code' => 'blocked-address'])
        ->and(oaEverything())->not->toContain('127.0.0.1');
});

it('refuses a token URL that is not on the allowlist at egress, whatever the form was told, and audits it', function () {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    $id = oaSource();

    // An unsaved edit of the form that points the token URL at a host nobody allowed: the guard refuses it when requested.
    $result = oaResult(oaTest($id, ['oauth_token_url' => 'https://unlisted.example.com/token', 'secrets' => ['oauth_client_secret' => 'CANARY-typed-2']])->assertStatus(202)->json('data.operation_id'));

    expect($result)->toMatchArray(['ok' => false, 'code' => 'host-not-allowlisted'])
        ->and($this->curl->calls)->toBe([])
        ->and(oaAudits('connector.egress.blocked'))->toHaveCount(1);
});

it('tests an unsaved form with a typed client secret: used for that test only, never cached', function () {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    $this->curl->queue = [oaTokenAnswer(), FakeCurl::answer(200)];

    $body = oaBody();
    unset($body['confirm_password']);
    $id = test()->postJson(OA_URL.'/test-connection', $body, OA_HEADERS)->assertStatus(202)->json('data.operation_id');

    expect(oaResult($id))->toMatchArray(['ok' => true, 'status' => 200])
        ->and($this->curl->calls[1][CURLOPT_HTTPHEADER])->toContain('Authorization: Bearer '.OA_TOKEN)
        ->and(oaSecretRows())->toBe([])
        ->and(oaRuns()[0])->toMatchArray(['kind' => 'oauth_token', 'data_source_id' => null]);

    // A typed secret for a saved source is not cached under that source's version either.
    $saved = oaSource();
    $this->curl->queue = [oaTokenAnswer('typed-token'), FakeCurl::answer(200)];
    $body = oaBody(['data_source_id' => $saved, 'secrets' => ['oauth_client_secret' => 'CANARY-typed-secret']]);
    unset($body['confirm_password']);
    test()->postJson(OA_URL.'/test-connection', $body, OA_HEADERS)->assertStatus(202);

    expect(oaCached($workspace, $saved, 1))->toBeNull();
});

it('uses a token for one call and never caches it when the token key is the dev placeholder, warning once without a value', function () {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    $id = oaSource();
    file_put_contents($this->tokenKeyFile, 'placeholder-not-a-real-key-token');
    $this->curl->queue = [oaTokenAnswer(), FakeCurl::answer(200), oaTokenAnswer(), FakeCurl::answer(200)];

    oaTest($id)->assertStatus(202);
    oaTest($id)->assertStatus(202);

    expect($this->curl->calls)->toHaveCount(4)
        ->and(oaCached($workspace, $id, 1))->toBeNull();

    $log = (string) file_get_contents($this->logFile);
    expect(substr_count($log, 'connector.oauth.token_cache_disabled'))->toBe(1)
        ->and($log)->not->toContain(OA_TOKEN)->not->toContain(OA_SECRET)->not->toContain($this->tokenKeyFile);
});

it('does not cache a token with no usable expiry, or one at or below the skew', function (?string $expires, ?string $skew) {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    $id = oaSource();
    config(['dashflow.oauth.token_skew_seconds.value' => $skew]);
    $this->curl->queue = [oaTokenAnswer(expires: $expires), FakeCurl::answer(200)];

    oaTest($id)->assertStatus(202);

    expect(oaCached($workspace, $id, 1))->toBeNull();
})->with([
    'no expires_in' => [null, null],
    'at the skew' => ['300', '300'],
    'below the skew' => ['100', '300'],
]);

it('keeps the token, the client secret and the Authorization value out of logs, traces, audit, sync_runs, responses and the cache (canary)', function () {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    $created = oaCreate()->assertCreated();
    $id = $created->json('data.data_source_id');
    $this->curl->queue = [oaTokenAnswer(), FakeCurl::answer(401), oaTokenAnswer(OA_TOKEN.'-2'), FakeCurl::answer(200), FakeCurl::answer(200), FakeCurl::answer(400, '{"error":"'.OA_SECRET.'"}')];

    $started = oaTest($id)->assertStatus(202);
    $summary = test()->getJson('/api/v1/operations/'.$started->json('data.operation_id'), OA_HEADERS);
    $listing = test()->getJson(OA_URL, OA_HEADERS);
    $detail = test()->getJson(OA_URL.'/'.$id, OA_HEADERS);

    expect(oaResult($started->json('data.operation_id')))->toMatchArray(['ok' => true]);

    $responses = $created->getContent().$started->getContent().$summary->getContent().$listing->getContent().$detail->getContent();
    $cache = json_encode(oaCached($workspace, $id, 1));

    expect($responses)->not->toContain(OA_SECRET)->not->toContain(OA_TOKEN)
        ->and($cache)->not->toContain(OA_TOKEN)->not->toContain(OA_SECRET)
        ->and(oaEverything())->not->toContain(OA_SECRET)->not->toContain(OA_TOKEN)->not->toContain('Bearer ');
});

it('rolls the OAuth migration back, dropping the client secrets, and migrates forward again', function () {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    oaSource();
    $columns = fn (string $table, string $column): int => Cluster::rows(Cluster::superuser(), 'select count(*) as n from information_schema.columns where table_name = ? and column_name = ?', [$table, $column])[0]['n'];

    try {
        // Seven steps: the newest migrations are Story 2.14's (scheduled fetch), Story 2.13's (user context), Story 2.12's (user attributes), Story 2.11's (pagination), Story 2.9's (Endpoints) and Story 2.8's (lock epoch), then this one.
        expect(Artisan::call('migrate:rollback', ['--database' => 'migrator', '--step' => 7, '--force' => true]))->toBe(0)
            ->and($columns('secrets', 'version'))->toBe(0)
            ->and($columns('data_sources', 'oauth_token_url'))->toBe(0)
            ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from secrets')[0]['n'])->toBe(0)
            ->and(Cluster::rows(Cluster::superuser(), 'select auth_type from data_sources')[0]['auth_type'])->toBe('none');
    } finally {
        Artisan::call('migrate', ['--database' => 'migrator', '--force' => true]);
    }

    expect($columns('secrets', 'version'))->toBe(1)->and($columns('data_sources', 'oauth_token_url'))->toBe(1);
});

// ---- Review fixes --------------------------------------------------------------------------------------------------

it('forgets the cached token when the client secret is removed, so setting it again at version 1 never meets the old token', function () {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    $id = oaSource();
    $this->curl->queue = [oaTokenAnswer('old-token'), FakeCurl::answer(200)];
    oaTest($id)->assertStatus(202);
    expect(oaCached($workspace, $id, 1))->not->toBeNull();

    oaUpdate($id, 1, ['auth_type' => 'none', 'secrets' => []])->assertOk();
    expect(oaCached($workspace, $id, 1))->toBeNull();

    oaUpdate($id, 2)->assertOk();
    expect(oaSecretRows()[0]['version'])->toBe(1);
    $this->curl->queue = [oaTokenAnswer('new-token'), FakeCurl::answer(200)];
    oaTest($id)->assertStatus(202);

    expect($this->curl->calls)->toHaveCount(4)
        ->and($this->curl->calls[3][CURLOPT_HTTPHEADER])->toContain('Authorization: Bearer new-token');
});

it('does not use the stored client secret for a changed token URL, client ID or scope without a typed one, and does when nothing changed', function (array $change, bool $refused) {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    Cluster::seedHostEntry($workspace, 'other.example.com');
    $id = oaSource();
    $this->curl->queue = [oaTokenAnswer(), FakeCurl::answer(200)];

    $response = oaTest($id, $change);

    if ($refused) {
        $response->assertStatus(422);
        expect($response->json('reasons')['secrets.oauth_client_secret'])->toBe('secret-required')
            ->and($this->curl->calls)->toBe([])
            ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from operations')[0]['n'])->toBe(0);
    } else {
        $response->assertStatus(202);
    }

    // A typed secret makes the changed form testable: the typed one is used, never the stored one.
    if ($refused) {
        $typed = oaBody($change + ['data_source_id' => $id, 'secrets' => ['oauth_client_secret' => 'CANARY-typed']]);
        unset($typed['confirm_password']);
        test()->postJson(OA_URL.'/test-connection', $typed, OA_HEADERS)->assertStatus(202);
        parse_str($this->curl->calls[0][CURLOPT_POSTFIELDS], $form);
        expect($form['client_secret'])->toBe('CANARY-typed');
    }
})->with([
    'token URL' => [['oauth_token_url' => 'https://other.example.com/token'], true],
    'client ID' => [['oauth_client_id' => 'client-2'], true],
    'scope' => [['oauth_scope' => 'admin'], true],
    'scope removed' => [['oauth_scope' => null], true],
    'unchanged' => [[], false],
]);

it('refuses a Test connection of an http token URL when https is required: 422 https-required, nothing queued, nothing sent', function () {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    config(['dashflow.tunables.guards.require_https.value' => true]);

    $body = oaBody(['oauth_token_url' => 'http://auth.example.com/token']);
    unset($body['confirm_password']);
    $response = test()->postJson(OA_URL.'/test-connection', $body, OA_HEADERS)->assertStatus(422);

    expect($response->json('reasons.oauth_token_url'))->toBe('https-required')
        ->and($this->curl->calls)->toBe([])
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from operations')[0]['n'])->toBe(0)
        ->and(oaSecretRows())->toBe([]);
});

it('keeps the outcome of a call when the token request cannot be written to sync_runs, and logs the failure with identifiers only', function () {
    $workspace = Cluster::workspace('Acme');
    oaAdmin($workspace);
    $id = oaSource();
    $super = Cluster::superuser();
    $super->exec("CREATE FUNCTION oa_fail_token_run() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN IF NEW.kind = 'oauth_token' THEN RAISE EXCEPTION 'blocked for test'; END IF; RETURN NEW; END \$f\$");
    $super->exec('CREATE TRIGGER oa_fail_token_run BEFORE INSERT ON sync_runs FOR EACH ROW EXECUTE FUNCTION oa_fail_token_run()');

    try {
        $this->curl->queue = [oaTokenAnswer(), FakeCurl::answer(200)];
        $result = oaResult(oaTest($id)->assertStatus(202)->json('data.operation_id'));
    } finally {
        $super->exec('DROP TRIGGER oa_fail_token_run ON sync_runs');
        $super->exec('DROP FUNCTION oa_fail_token_run()');
    }

    $log = (string) file_get_contents($this->logFile);
    expect($result)->toMatchArray(['ok' => true, 'status' => 200])
        ->and(array_column(oaRuns(), 'kind'))->toBe(['connection_test'])
        ->and($log)->toContain('connector.oauth.sync_run_failed')->toContain($workspace)
        ->and($log)->not->toContain(OA_TOKEN)->not->toContain(OA_SECRET)->not->toContain('blocked for test');
});
