<?php

use App\Models\User;
use App\Modules\Connector\Contracts\DataSources;
use App\Modules\Connector\Contracts\FetchRequest;
use App\Modules\Connector\Contracts\KeyringUnavailable;
use App\Modules\Connector\Contracts\SecretContext;
use App\Modules\Connector\Contracts\SecretRefused;
use App\Modules\Connector\Contracts\SecretVault;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 2.4 against the real PostgreSQL: an Admin with `data_sources.manage` sets credentials that are sealed to the
// platform key, so the web tier can store but never read them. Only `{configured, updated_at}` is ever returned; a
// change needs the password, bumps `revision` and is audited with a keyed hash only; no canary appears anywhere.
const SC_URL = '/api/v1/admin/data-sources';
const SC_HEADERS = ['Referer' => 'http://localhost:8000'];
const SC_PASSWORD = 'admin-password-1';
const SC_CANARY = 'CANARY-s3cr3t-9d41';

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();

    // The platform's key pair: the public half is configured everywhere, the private half only where a worker would mount it.
    $pair = sodium_crypto_box_keypair();
    $this->privateKeyFile = tempnam(sys_get_temp_dir(), 'dashflow-key');
    file_put_contents($this->privateKeyFile, base64_encode(sodium_crypto_box_secretkey($pair)));
    config([
        'dashflow.secrets.cred_public_key.value' => base64_encode(sodium_crypto_box_publickey($pair)),
        'dashflow.secrets.cred_key_version.value' => '3',
        'dashflow.secrets.cred_key_path.value' => '/nonexistent/key-cred',
    ]);

    $this->logFile = tempnam(sys_get_temp_dir(), 'dashflow-log');
    config(['logging.default' => 'single', 'logging.channels.single.path' => $this->logFile]);
});

afterEach(function () {
    @unlink($this->privateKeyFile);
    @unlink($this->logFile);
});

function scAdmin(string $workspaceId, array $permissions = ['data_sources.manage']): array
{
    $user = Cluster::user('ada@example.test');
    Cluster::superuser()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([Hash::make(SC_PASSWORD), $user]);
    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspaceId, $user, 'admin', 'active']);

    foreach ($permissions as $permission) {
        Cluster::superuser()->prepare('INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
            ->execute([(string) Str::uuid7(), $workspaceId, $membership, $permission]);
    }

    Cluster::seedHostEntry($workspaceId, 'api.example.com');
    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspaceId, 'area' => 'admin']);

    return [$user, $membership];
}

function scBody(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Sales API', 'base_url' => 'https://api.example.com/v1', 'headers' => [], 'timeout_seconds' => null,
        'max_response_bytes' => null, 'max_pages' => null, 'live_capable' => false, 'auth_type' => 'none',
    ];
}

function scCreate(array $overrides = [])
{
    return test()->postJson(SC_URL, scBody($overrides), SC_HEADERS);
}

function scUpdate(string $id, int $revision, array $overrides = [])
{
    return test()->putJson(SC_URL."/{$id}", scBody($overrides) + ['revision' => $revision], SC_HEADERS);
}

function scBearer(string $value = SC_CANARY, array $overrides = []): array
{
    return $overrides + ['auth_type' => 'bearer', 'secrets' => ['bearer_token' => $value], 'confirm_password' => SC_PASSWORD];
}

function scSecrets(): array
{
    return Cluster::rows(Cluster::superuser(), "select id, data_source_id, slot, purpose, key_version, key_ref, encode(ciphertext, 'base64') as sealed, updated_at from secrets order by slot");
}

function scAudits(?string $action = null): array
{
    return Cluster::rows(Cluster::superuser(), "select * from audit_events where action like 'connector.data_source.%' and (?::text is null or action = ?) order by occurred_at, id", [$action, $action]);
}

/** Opens a stored value as the worker would: the private key file is mounted there. */
function scOpen(array $row, string $workspace): string
{
    config(['dashflow.secrets.cred_key_path.value' => test()->privateKeyFile]);

    return app(SecretVault::class)->open(new SecretContext($workspace, $row['data_source_id'], $row['slot']), base64_decode($row['sealed'], true));
}

function scEverything(): string
{
    return json_encode([
        Cluster::rows(Cluster::superuser(), 'select * from audit_events'),
        Cluster::rows(Cluster::superuser(), 'select * from outbox_events'),
        Cluster::rows(Cluster::superuser(), 'select id, name, auth_type, default_headers, api_key_name, api_key_placement from data_sources'),
        (string) file_get_contents(test()->logFile),
    ], JSON_THROW_ON_ERROR);
}

it('sets a bearer token: sealed row, only {configured, updated_at} returned, revision, audit with a hash only', function () {
    $workspace = Cluster::workspace('Acme');
    [, $editor] = scAdmin($workspace);

    $response = scCreate(scBearer())->assertCreated();
    $id = $response->json('data.data_source_id');

    expect($response->json('data.secrets'))->toBe(['bearer_token' => ['configured' => true, 'updated_at' => $response->json('data.secrets.bearer_token.updated_at')]])
        ->and($response->json('data.secrets.bearer_token.updated_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($response->json('data.auth_type'))->toBe('bearer')
        ->and($response->json('data.revision'))->toBe(1)
        ->and($response->getContent())->not->toContain(SC_CANARY);

    $rows = scSecrets();
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['data_source_id' => $id, 'slot' => 'bearer_token', 'purpose' => 'cred', 'key_version' => 3])
        ->and($rows[0]['key_ref'])->toMatch('/^sha256:[0-9a-f]{32}$/')
        ->and(Str::isUuid($rows[0]['id']))->toBeTrue()
        ->and(substr($rows[0]['id'], 14, 1))->toBe('7')
        ->and(base64_decode($rows[0]['sealed'], true))->not->toContain(SC_CANARY)
        ->and(scOpen($rows[0], $workspace))->toBe(SC_CANARY);

    $changed = scAudits('connector.data_source.secret_changed');
    $after = json_decode($changed[0]['after_state'], true);
    expect($changed)->toHaveCount(1)
        ->and($changed[0]['actor'])->toBe($editor)
        ->and($changed[0]['subject'])->toBe('data_source:'.$id)
        ->and($after)->toMatchArray(['data_source_id' => $id, 'slot' => 'bearer_token', 'purpose' => 'cred', 'key_version' => 3, 'action' => 'set'])
        ->and($after['value_hash'])->toMatch('/^hmac-sha256:[0-9a-f]{64}$/')
        ->and(scEverything())->not->toContain(SC_CANARY);
});

it('reads a saved secret back as its status only, on the detail and never in the list', function () {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace);
    $id = scCreate(scBearer())->assertCreated()->json('data.data_source_id');

    $detail = test()->getJson(SC_URL."/{$id}", SC_HEADERS)->assertOk();
    expect($detail->json('data.secrets.bearer_token.configured'))->toBeTrue()
        ->and($detail->json('data.secrets.bearer_token.updated_at'))->not->toBeNull()
        ->and(array_keys($detail->json('data.secrets.bearer_token')))->toBe(['configured', 'updated_at'])
        ->and($detail->getContent())->not->toContain(SC_CANARY)->not->toContain('sealed')->not->toContain('ciphertext');

    $list = test()->getJson(SC_URL, SC_HEADERS)->assertOk();
    expect($list->json('data.0'))->not->toHaveKey('secrets')
        ->and($list->getContent())->not->toContain(SC_CANARY);

    // The edit page's props carry no value either.
    test()->get(route('admin.data-sources.edit', $id))->assertOk()->assertDontSee(SC_CANARY);
});

it('shows a slot of the chosen type that is not set yet as not configured', function () {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace);
    $id = scCreate(scBearer())->json('data.data_source_id');
    scUpdate($id, 1, ['auth_type' => 'basic', 'secrets' => ['basic_username' => 'ada', 'basic_password' => 'pw-1'], 'confirm_password' => SC_PASSWORD])->assertOk();

    $detail = test()->getJson(SC_URL."/{$id}", SC_HEADERS);
    expect(array_keys($detail->json('data.secrets')))->toBe(['basic_username', 'basic_password'])
        ->and(array_column(scSecrets(), 'slot'))->toBe(['basic_password', 'basic_username']);
});

it('replaces a secret with the password: row updated, audited replaced, revision plus one', function () {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace);
    $id = scCreate(scBearer())->json('data.data_source_id');
    $first = scSecrets()[0];

    scUpdate($id, 1, scBearer('CANARY-second-value-77'))->assertOk()->assertJsonPath('data.revision', 2);

    $rows = scSecrets();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe($first['id'])
        ->and($rows[0]['sealed'])->not->toBe($first['sealed'])
        ->and(scOpen($rows[0], $workspace))->toBe('CANARY-second-value-77');

    $changed = scAudits('connector.data_source.secret_changed');
    expect($changed)->toHaveCount(2)
        ->and(json_decode($changed[1]['after_state'], true)['action'])->toBe('replaced')
        ->and(scEverything())->not->toContain('CANARY-second-value-77')->not->toContain(SC_CANARY);
});

it('leaves a slot that is not in the request unchanged and asks for no password when nothing secret changes', function () {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace);
    $id = scCreate(scBearer())->json('data.data_source_id');
    $before = scSecrets()[0];

    scUpdate($id, 1, ['auth_type' => 'bearer', 'name' => 'Renamed'])->assertOk()->assertJsonPath('data.revision', 2)
        ->assertJsonPath('data.secrets.bearer_token.configured', true);

    expect(scSecrets()[0])->toBe($before)
        ->and(scAudits('connector.data_source.secret_changed'))->toHaveCount(1);
});

it('refuses a missing or wrong password with a 422 on confirm_password and stores nothing', function (?string $password) {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace);
    $body = scBearer();
    $password === null ? $body['confirm_password'] = '' : $body['confirm_password'] = $password;

    scCreate($body)->assertStatus(422)->assertJsonStructure(['errors' => ['confirm_password']]);

    expect(scSecrets())->toBe([])
        ->and(Cluster::rows(Cluster::superuser(), 'select id from data_sources'))->toBe([])
        ->and(scAudits())->toBe([]);

    $id = Cluster::seedDataSource($workspace, 'Existing');
    scUpdate($id, 1, ['auth_type' => 'bearer', 'secrets' => ['bearer_token' => SC_CANARY], 'confirm_password' => $body['confirm_password']])->assertStatus(422);
    expect(scSecrets())->toBe([])
        ->and(Cluster::rows(Cluster::superuser(), 'select revision, auth_type from data_sources')[0])->toMatchArray(['revision' => 1, 'auth_type' => 'none']);
})->with(['none' => [null], 'wrong' => ['not-the-password']]);

it('throttles wrong passwords like the member editor: 429 with Retry-After, and a right one is refused while throttled', function () {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace);

    foreach (range(1, 6) as $_) {
        scCreate(scBearer(SC_CANARY, ['confirm_password' => 'wrong']))->assertStatus(422);
    }

    scCreate(scBearer())->assertStatus(429)->assertHeader('Retry-After')->assertJsonStructure(['errors' => ['confirm_password']]);
    expect(scSecrets())->toBe([]);
});

it('refuses an empty value with secret-required, and CR, LF and non-visible ASCII with secret-value-invalid', function (mixed $value, string $reason) {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace);

    scCreate(scBearer($value))->assertStatus(422)->assertJson(['reasons' => ['secrets.bearer_token' => $reason]]);

    expect(scSecrets())->toBe([])->and(Cluster::rows(Cluster::superuser(), 'select id from data_sources'))->toBe([]);
})->with([
    'empty' => ['', 'secret-required'],
    'LF' => ["tok\nen", 'secret-value-invalid'],
    'CR' => ["tok\ren", 'secret-value-invalid'],
    'tab' => ["tok\ten", 'secret-value-invalid'],
    'NUL' => ["tok\0en", 'secret-value-invalid'],
    'non-ASCII' => ['tök', 'secret-value-invalid'],
    'too long' => [str_repeat('a', 2049), 'secret-value-too-long'],
]);

it('requires a value for every slot of the chosen type that holds none', function () {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace);

    scCreate(['auth_type' => 'bearer', 'confirm_password' => SC_PASSWORD])->assertStatus(422)->assertJson(['reasons' => ['secrets.bearer_token' => 'secret-required']]);
    scCreate(['auth_type' => 'basic', 'secrets' => ['basic_username' => 'ada'], 'confirm_password' => SC_PASSWORD])->assertStatus(422)->assertJson(['reasons' => ['secrets.basic_password' => 'secret-required']]);
    scCreate(['auth_type' => 'bearer', 'secrets' => ['basic_password' => 'x'], 'confirm_password' => SC_PASSWORD])->assertStatus(422)->assertJson(['reasons' => ['secrets.basic_password' => 'secret-slot-unused']]);

    expect(scSecrets())->toBe([]);
});

it('switches the auth type: the slots no longer used are removed through the function, audited removed, password required', function () {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace);
    $id = scCreate(scBearer())->json('data.data_source_id');

    // Without the password the switch is refused and nothing changes.
    scUpdate($id, 1, ['auth_type' => 'none'])->assertStatus(422)->assertJsonStructure(['errors' => ['confirm_password']]);
    expect(scSecrets())->toHaveCount(1)->and(Cluster::rows(Cluster::superuser(), 'select auth_type, revision from data_sources')[0])->toMatchArray(['auth_type' => 'bearer', 'revision' => 1]);

    scUpdate($id, 1, ['auth_type' => 'none', 'confirm_password' => SC_PASSWORD])->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.secrets', []);

    $removed = array_values(array_filter(scAudits('connector.data_source.secret_changed'), fn ($row) => json_decode($row['after_state'], true)['action'] === 'removed'));
    expect(scSecrets())->toBe([])
        ->and($removed)->toHaveCount(1)
        ->and(json_decode($removed[0]['after_state'], true))->toMatchArray(['slot' => 'bearer_token', 'purpose' => 'cred', 'key_version' => 3])
        ->and(json_decode($removed[0]['after_state'], true))->not->toHaveKey('value_hash');
});

it('stores an API key in a header or in the query string; a query placement is saved as such', function () {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace);

    $header = scCreate(['name' => 'H', 'auth_type' => 'api_key', 'api_key_name' => 'X-Api-Key', 'api_key_placement' => 'header', 'secrets' => ['api_key' => SC_CANARY], 'confirm_password' => SC_PASSWORD])->assertCreated();
    $query = scCreate(['name' => 'Q', 'auth_type' => 'api_key', 'api_key_name' => 'key', 'api_key_placement' => 'query', 'secrets' => ['api_key' => SC_CANARY], 'confirm_password' => SC_PASSWORD])->assertCreated();

    expect($header->json('data'))->toMatchArray(['auth_type' => 'api_key', 'api_key_name' => 'X-Api-Key', 'api_key_placement' => 'header'])
        ->and($query->json('data'))->toMatchArray(['api_key_name' => 'key', 'api_key_placement' => 'query'])
        ->and(array_column(Cluster::rows(Cluster::superuser(), 'select name, api_key_placement from data_sources order by name'), 'api_key_placement', 'name'))->toBe(['H' => 'header', 'Q' => 'query'])
        ->and(array_unique(array_column(scSecrets(), 'slot')))->toBe(['api_key']);
});

it('refuses an API key name that is missing, not a token or reserved, and a bad placement', function (array $extra, string $field, string $reason) {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace);

    scCreate($extra + ['auth_type' => 'api_key', 'api_key_name' => 'X-Api-Key', 'api_key_placement' => 'header', 'secrets' => ['api_key' => SC_CANARY], 'confirm_password' => SC_PASSWORD])
        ->assertStatus(422)->assertJsonPath("reasons.{$field}", $reason);

    expect(scSecrets())->toBe([])->and(Cluster::rows(Cluster::superuser(), 'select id from data_sources'))->toBe([]);
})->with([
    'missing' => [['api_key_name' => ''], 'api_key_name', 'api-key-name-required'],
    'not a token' => [['api_key_name' => 'X Api Key'], 'api_key_name', 'api-key-name-invalid'],
    'CRLF' => [['api_key_name' => "X-Key\r\nHost"], 'api_key_name', 'api-key-name-invalid'],
    'Host' => [['api_key_name' => 'Host'], 'api_key_name', 'api-key-name-reserved'],
    'Authorization' => [['api_key_name' => 'authorization'], 'api_key_name', 'api-key-name-reserved'],
    'a default header' => [['headers' => [['name' => 'x-api-key', 'value' => 'v']]], 'api_key_name', 'api-key-name-duplicate'],
    'placement' => [['api_key_placement' => 'body'], 'api_key_placement', 'api-key-placement-invalid'],
]);

it('seals a secret default header under header:{name} and keeps only its name and flag in default_headers', function () {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace);

    $response = scCreate(['headers' => [['name' => 'X-Plain', 'value' => 'open'], ['name' => 'X-Team-Key', 'secret' => true, 'value' => SC_CANARY]], 'confirm_password' => SC_PASSWORD])->assertCreated();
    $id = $response->json('data.data_source_id');

    expect($response->json('data.headers'))->toBe([['name' => 'X-Plain', 'value' => 'open'], ['name' => 'X-Team-Key', 'secret' => true]])
        ->and($response->json('data.secrets'))->toHaveKey('header:x-team-key')
        ->and($response->getContent())->not->toContain(SC_CANARY)
        ->and(json_decode(Cluster::rows(Cluster::superuser(), 'select default_headers from data_sources')[0]['default_headers'], true))
        ->toBe([['name' => 'X-Plain', 'value' => 'open'], ['name' => 'X-Team-Key', 'secret' => true]]);

    $rows = scSecrets();
    expect($rows)->toHaveCount(1)->and($rows[0]['slot'])->toBe('header:x-team-key')->and(scOpen($rows[0], $workspace))->toBe(SC_CANARY);

    $audit = scAudits('connector.data_source.secret_changed');
    expect(json_decode($audit[0]['after_state'], true))->toMatchArray(['slot' => 'header', 'action' => 'set'])
        ->and(scEverything())->not->toContain(SC_CANARY);

    // The list shows header names only; an edit without a value keeps the secret, and unflagging it removes the secret.
    test()->getJson(SC_URL, SC_HEADERS)->assertJsonPath('data.0.headers', [['name' => 'X-Plain'], ['name' => 'X-Team-Key', 'secret' => true]]);
    scUpdate($id, 1, ['headers' => [['name' => 'X-Plain', 'value' => 'open'], ['name' => 'X-Team-Key', 'secret' => true]]])->assertOk();
    expect(scSecrets())->toHaveCount(1);

    scUpdate($id, 2, ['headers' => [['name' => 'X-Plain', 'value' => 'open']], 'confirm_password' => SC_PASSWORD])->assertOk();
    expect(scSecrets())->toBe([]);
});

it('refuses a secret header value with CR or LF, an empty one, and a secret header with no value yet', function () {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace);
    $row = fn (array $header) => scCreate(['headers' => [$header], 'confirm_password' => SC_PASSWORD]);

    $row(['name' => 'X-K', 'secret' => true, 'value' => "a\r\nb"])->assertStatus(422)->assertJson(['reasons' => ['headers.0.value' => 'secret-value-invalid']]);
    $row(['name' => 'X-K', 'secret' => true, 'value' => ''])->assertStatus(422)->assertJson(['reasons' => ['headers.0.value' => 'secret-required']]);
    $row(['name' => 'X-K', 'secret' => true])->assertStatus(422)->assertJson(['reasons' => ['headers.0.value' => 'secret-required']]);

    expect(scSecrets())->toBe([])->and(Cluster::rows(Cluster::superuser(), 'select id from data_sources'))->toBe([]);
});

it('refuses to save a secret while the platform key is unset or invalid, and stores nothing', function (mixed $key, mixed $version) {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace);
    config(['dashflow.secrets.cred_public_key.value' => $key, 'dashflow.secrets.cred_key_version.value' => $version]);

    scCreate(scBearer())->assertStatus(503)->assertJsonPath('error.code', 'connector.secrets_not_configured')
        ->assertJsonPath('reason', 'secrets-not-configured');

    expect(scSecrets())->toBe([])->and(Cluster::rows(Cluster::superuser(), 'select id from data_sources'))->toBe([])->and(scAudits())->toBe([]);

    // A source that holds no secret still saves.
    scCreate(['name' => 'Open'])->assertCreated();
})->with([
    'public key unset' => [null, '1'],
    'public key not base64' => ['***', '1'],
    'public key wrong length' => [base64_encode('short'), '1'],
    'version unset' => [base64_encode(str_repeat('k', 32)), null],
    'version zero' => [base64_encode(str_repeat('k', 32)), '0'],
]);

it('answers a stale revision with 409 and changes no secret', function () {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace);
    $id = scCreate(scBearer())->json('data.data_source_id');
    $before = scSecrets()[0];

    scUpdate($id, 7, scBearer('CANARY-stale-1'))->assertStatus(409)->assertJsonPath('error.code', 'connector.revision_conflict')
        ->assertJsonPath('current.data.secrets.bearer_token.configured', true);

    expect(scSecrets()[0])->toBe($before)->and(scAudits('connector.data_source.secret_changed'))->toHaveCount(1);
});

it('denies an Admin without data_sources.manage every read and write of credentials with 403 and no disclosure', function () {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace, ['settings.manage']);
    $id = Cluster::seedDataSource($workspace, 'Hidden');
    Cluster::seedSecret($workspace, $id);

    foreach ([
        fn () => test()->getJson(SC_URL."/{$id}", SC_HEADERS),
        fn () => scCreate(scBearer()),
        fn () => scUpdate($id, 1, scBearer()),
    ] as $call) {
        $response = $call();
        $response->assertForbidden();
        expect($response->getContent())->not->toContain('Hidden')->not->toContain('configured')->not->toContain(SC_CANARY);
    }

    expect(scSecrets())->toHaveCount(1);
});

it('refuses a secret-valued field on the blur check with 422 secret-values-refused and reads none of it', function (array $payload) {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace);

    $response = test()->postJson(SC_URL.'/check-url', ['base_url' => 'https://api.example.com'] + $payload, SC_HEADERS)->assertStatus(422);
    expect($response->json('reason'))->toBe('secret-values-refused')
        ->and($response->json('error.code'))->toBe('connector.secret_values_refused')
        ->and($response->getContent())->not->toContain(SC_CANARY);

    test()->postJson(SC_URL.'/check-url', ['base_url' => 'https://api.example.com'], SC_HEADERS)->assertOk();
})->with([
    'secrets' => [['secrets' => ['bearer_token' => SC_CANARY]]],
    'password' => [['confirm_password' => SC_CANARY]],
    'a flagged header' => [['headers' => [['name' => 'X-K', 'secret' => true, 'value' => SC_CANARY]]]],
    'a named field' => [['api_key' => SC_CANARY]],
    'nested' => [['form' => ['deep' => ['token' => SC_CANARY]]]],
]);

it('leaves no canary in logs, audit rows, outbox, responses or the page props across every credential flow', function () {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace);
    $canary = fn (string $slot) => 'CANARY-'.$slot.'-'.Str::random(8);
    $values = ['bearer' => $canary('bearer'), 'user' => $canary('user'), 'pass' => $canary('pass'), 'key' => $canary('key'), 'hdr' => $canary('hdr'), 'wrong' => $canary('wrong')];
    $bodies = [];

    $created = scCreate(scBearer($values['bearer']));
    $bodies[] = $created->getContent();
    $id = $created->json('data.data_source_id');
    $bodies[] = scUpdate($id, 1, ['auth_type' => 'basic', 'secrets' => ['basic_username' => $values['user'], 'basic_password' => $values['pass']], 'confirm_password' => SC_PASSWORD])->getContent();
    $bodies[] = scUpdate($id, 2, ['auth_type' => 'api_key', 'api_key_name' => 'k', 'api_key_placement' => 'query', 'secrets' => ['api_key' => $values['key']], 'headers' => [['name' => 'X-S', 'secret' => true, 'value' => $values['hdr']]], 'confirm_password' => SC_PASSWORD])->getContent();
    $bodies[] = scUpdate($id, 3, ['auth_type' => 'api_key', 'api_key_name' => 'k', 'api_key_placement' => 'query', 'secrets' => ['api_key' => $values['wrong']], 'confirm_password' => 'bad-password'])->getContent();
    $bodies[] = scUpdate($id, 3, ['auth_type' => 'bearer', 'secrets' => ['bearer_token' => "bad\n".$values['wrong']], 'confirm_password' => SC_PASSWORD])->getContent();
    $bodies[] = test()->getJson(SC_URL."/{$id}", SC_HEADERS)->getContent();
    $bodies[] = test()->getJson(SC_URL, SC_HEADERS)->getContent();
    $bodies[] = test()->get(route('admin.data-sources.edit', $id))->getContent();
    $bodies[] = test()->get(route('admin.data-sources.index'))->getContent();

    $everything = scEverything().implode("\n", $bodies);
    foreach ($values as $name => $value) {
        expect($everything)->not->toContain($value, "canary {$name} leaked");
    }
    expect((string) file_get_contents(test()->logFile))->toContain('http.request');
});

it('opens a stored secret only where the private key is mounted: on web it fails with KeyringUnavailable', function () {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace);
    $id = scCreate(scBearer())->json('data.data_source_id');
    $row = scSecrets()[0];
    $context = new SecretContext($workspace, $id, 'bearer_token');
    $vault = app(SecretVault::class);

    // The web role has no key-cred mount: the default path does not exist.
    expect(fn () => $vault->open($context, base64_decode($row['sealed'], true)))->toThrow(KeyringUnavailable::class);

    // A file that is not key material (the dev placeholder) is no keyring either.
    file_put_contents($this->privateKeyFile, 'placeholder: not key material');
    config(['dashflow.secrets.cred_key_path.value' => $this->privateKeyFile]);
    expect(fn () => $vault->open($context, base64_decode($row['sealed'], true)))->toThrow(KeyringUnavailable::class);
});

it('refuses on the worker a value copied to another Workspace, Data Source or slot', function () {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace);
    $id = scCreate(scBearer())->json('data.data_source_id');
    $row = scSecrets()[0];
    $sealed = base64_decode($row['sealed'], true);
    config(['dashflow.secrets.cred_key_path.value' => $this->privateKeyFile]);
    $vault = app(SecretVault::class);

    expect($vault->open(new SecretContext($workspace, $id, 'bearer_token'), $sealed))->toBe(SC_CANARY);

    foreach ([
        new SecretContext((string) Str::uuid7(), $id, 'bearer_token'),
        new SecretContext($workspace, (string) Str::uuid7(), 'bearer_token'),
        new SecretContext($workspace, $id, 'basic_password'),
        new SecretContext($workspace, $id, 'bearer_token', 'token'),
    ] as $wrong) {
        expect(fn () => $vault->open($wrong, $sealed))->toThrow(SecretRefused::class);
    }
});

it('builds a FetchRequest with secret_refs and the credential scheme only, never a value or ciphertext', function () {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace);
    $id = scCreate(['auth_type' => 'api_key', 'api_key_name' => 'key', 'api_key_placement' => 'query', 'headers' => [['name' => 'X-S', 'secret' => true, 'value' => SC_CANARY.'-h']], 'secrets' => ['api_key' => SC_CANARY], 'confirm_password' => SC_PASSWORD])
        ->json('data.data_source_id');

    $source = app(WorkspaceTransaction::class)->run($workspace, fn () => app(DataSources::class)->find($workspace, $id));
    $request = FetchRequest::for($workspace, $source, (string) Str::uuid7(), 'https://api.example.com/v1/items/{id}?token='.SC_CANARY, ['id'], $source->secrets);

    $json = json_encode($request, JSON_THROW_ON_ERROR);
    $ciphertexts = array_map(fn ($row) => $row['sealed'], scSecrets());

    expect($request->toArray()['v'])->toBe(4)
        ->and($request->scheme->value)->toBe('api_key_query')
        ->and($request->secretRefs)->toHaveCount(2)
        ->and(array_column($request->toArray()['secret_refs'], 'slot'))->toBe(['api_key', 'header:x-s'])
        ->and(array_unique(array_column($request->toArray()['secret_refs'], 'purpose')))->toBe(['cred'])
        ->and($request->urlTemplate)->toBe('https://api.example.com/v1/items/{id}')
        ->and($json)->not->toContain(SC_CANARY);

    foreach ($ciphertexts as $sealed) {
        expect($json)->not->toContain($sealed);
    }
});

it('keeps the secrets table forced under row-level security, owned by migrator, with SELECT, INSERT and UPDATE for app only', function () {
    Cluster::migrateOnce();

    $flags = Cluster::rows(Cluster::superuser(), "select relrowsecurity, relforcerowsecurity, pg_get_userbyid(relowner) as owner from pg_class where oid = 'secrets'::regclass")[0];
    expect($flags)->toMatchArray(['relrowsecurity' => true, 'relforcerowsecurity' => true, 'owner' => 'migrator']);

    $privileges = Cluster::rows(Cluster::superuser(), "select privilege_type from information_schema.role_table_grants where table_name = 'secrets' and grantee = 'app' order by privilege_type");
    expect(array_column($privileges, 'privilege_type'))->toBe(['INSERT', 'SELECT', 'UPDATE']);

    $workspace = Cluster::workspace('Acme');
    Cluster::seedSecret($workspace);
    expect(fn () => Cluster::inWorkspace(Cluster::directApp(), $workspace, fn ($pdo) => $pdo->exec('delete from secrets')))->toThrow(PDOException::class, 'permission denied');
});

it('refuses at the database a second value for one slot, another purpose, a bad slot and an empty ciphertext', function () {
    $a = Cluster::workspace('A');
    $source = Cluster::seedDataSource($a);
    Cluster::seedSecret($a, $source, 'bearer_token');

    expect(fn () => Cluster::seedSecret($a, $source, 'bearer_token'))->toThrow(PDOException::class, 'secrets_data_source_id_slot_unique')
        ->and(fn () => Cluster::seedSecret($a, $source, 'password'))->toThrow(PDOException::class, 'secrets_slot_check')
        ->and(fn () => Cluster::superuser()->exec("UPDATE secrets SET purpose = 'token'"))->toThrow(PDOException::class, 'secrets_purpose_check')
        ->and(fn () => Cluster::superuser()->exec("UPDATE secrets SET ciphertext = ''::bytea"))->toThrow(PDOException::class, 'secrets_ciphertext_check');
});

it('removes secrets through the SECURITY DEFINER function for the context Workspace only', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $sourceA = Cluster::seedDataSource($a);
    $sourceB = Cluster::seedDataSource($b);
    Cluster::seedSecret($a, $sourceA, 'bearer_token');
    Cluster::seedSecret($b, $sourceB, 'bearer_token');

    $function = Cluster::rows(Cluster::superuser(), "select prosecdef, pg_get_userbyid(proowner) as owner, has_function_privilege('app', oid, 'EXECUTE') as app, has_function_privilege('public', oid, 'EXECUTE') as pub from pg_proc where proname = 'connector_remove_secrets'")[0];
    expect($function)->toMatchArray(['prosecdef' => true, 'owner' => 'migrator', 'app' => true, 'pub' => false]);

    $call = fn (string $workspace, string $source) => Cluster::inWorkspace(Cluster::directApp(), $workspace, fn ($pdo) => Cluster::rows($pdo, "select connector_remove_secrets(?::uuid, '{bearer_token}'::text[]) as n", [$source])[0]['n']);

    expect($call($a, $sourceB))->toBe(0)
        ->and((int) Cluster::rows(Cluster::superuser(), 'select count(*) as n from secrets')[0]['n'])->toBe(2)
        ->and($call($a, $sourceA))->toBe(1)
        ->and((int) Cluster::rows(Cluster::superuser(), 'select count(*) as n from secrets')[0]['n'])->toBe(1)
        ->and(fn () => Cluster::rows(Cluster::directApp(), "select connector_remove_secrets(?::uuid, '{bearer_token}'::text[])", [$sourceB]))->toThrow(PDOException::class, 'Workspace context is not set');
});

it('asks for the password when a stored credential is rerouted by a new API key name or placement, and when nothing is stored it does not', function () {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace);
    $api = ['auth_type' => 'api_key', 'api_key_name' => 'X-Api-Key', 'api_key_placement' => 'header'];
    $id = scCreate($api + ['secrets' => ['api_key' => SC_CANARY], 'confirm_password' => SC_PASSWORD])->assertCreated()->json('data.data_source_id');

    scUpdate($id, 1, ['api_key_placement' => 'query', 'api_key_name' => 'key'] + $api)->assertStatus(422)->assertJsonStructure(['errors' => ['confirm_password']]);
    scUpdate($id, 1, ['api_key_name' => 'X-Other'] + $api)->assertStatus(422)->assertJsonStructure(['errors' => ['confirm_password']]);
    expect(Cluster::rows(Cluster::superuser(), 'select api_key_name, api_key_placement, revision from data_sources')[0])->toMatchArray(['api_key_name' => 'X-Api-Key', 'api_key_placement' => 'header', 'revision' => 1]);

    scUpdate($id, 1, ['api_key_placement' => 'query', 'api_key_name' => 'key', 'confirm_password' => SC_PASSWORD] + $api)->assertOk()->assertJsonPath('data.api_key_placement', 'query');
});

it('refuses at the database a secret whose Data Source belongs to another Workspace', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $sourceB = Cluster::seedDataSource($b);

    expect(fn () => Cluster::seedSecret($a, $sourceB))->toThrow(PDOException::class, 'secrets_data_source_workspace_fk');
    Cluster::seedSecret($b, $sourceB);
});

it('refuses a query API key name that could inject parameters, a colon in a Basic user name, and a blank or null secret', function (array $overrides, string $field, string $reason) {
    $workspace = Cluster::workspace('Acme');
    scAdmin($workspace);

    scCreate($overrides + ['confirm_password' => SC_PASSWORD])->assertStatus(422)->assertJson(['reasons' => [$field => $reason]]);
    expect(scSecrets())->toBe([])->and(Cluster::rows(Cluster::superuser(), 'select id from data_sources'))->toBe([]);
})->with([
    'ampersand' => [['auth_type' => 'api_key', 'api_key_name' => 'k&admin', 'api_key_placement' => 'query', 'secrets' => ['api_key' => 'v']], 'api_key_name', 'api-key-name-invalid'],
    'equals' => [['auth_type' => 'api_key', 'api_key_name' => 'k=1', 'api_key_placement' => 'query', 'secrets' => ['api_key' => 'v']], 'api_key_name', 'api-key-name-invalid'],
    'percent' => [['auth_type' => 'api_key', 'api_key_name' => 'k%26', 'api_key_placement' => 'query', 'secrets' => ['api_key' => 'v']], 'api_key_name', 'api-key-name-invalid'],
    'colon' => [['auth_type' => 'basic', 'secrets' => ['basic_username' => 'a:b', 'basic_password' => 'p']], 'secrets.basic_username', 'secret-value-invalid'],
    'whitespace only' => [['auth_type' => 'bearer', 'secrets' => ['bearer_token' => '   ']], 'secrets.bearer_token', 'secret-required'],
    'null' => [['auth_type' => 'bearer', 'secrets' => ['bearer_token' => null]], 'secrets.bearer_token', 'secret-required'],
]);
