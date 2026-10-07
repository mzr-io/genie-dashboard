<?php

use App\Models\User;
use App\Modules\Connector\Contracts\ErrorCode;
use App\Modules\Connector\Contracts\HostResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 2.3 against the real PostgreSQL: an Admin with `data_sources.manage` registers and edits Data Sources; rows live
// under row-level security with a rising `revision`; the Base URL must be on the Workspace allowlist (and https when
// `require_https` is on); audit lands in the same transaction; a stale revision is a 409 with the current state; and the
// gate follows Story 1.19.
beforeEach(fn () => $this->withoutVite());

const DS_HEADERS = ['Referer' => 'http://localhost:8000'];
const DS_URL = '/api/v1/admin/data-sources';

/** A member of the Workspace; returns [user ID, membership ID]. */
function dsMember(string $workspaceId, string $email, string $role = 'user', array $permissions = []): array
{
    $user = Cluster::user($email);
    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspaceId, $user, $role, 'active']);

    foreach ($permissions as $permission) {
        Cluster::superuser()->prepare('INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
            ->execute([(string) Str::uuid7(), $workspaceId, $membership, $permission]);
    }

    return [$user, $membership];
}

/** The Admin holding `data_sources.manage`, signed in, with the allowlist entry `api.example.com` (https, 443); returns [user ID, membership ID]. */
function dsAdmin(string $workspaceId, array $permissions = ['data_sources.manage'], string $email = 'ada@example.test'): array
{
    [$user, $membership] = dsMember($workspaceId, $email, 'admin', $permissions);
    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspaceId, 'area' => 'admin']);

    return [$user, $membership];
}

function dsBody(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Sales API',
        'base_url' => 'https://api.example.com/v1',
        'headers' => [],
        'timeout_seconds' => null,
        'max_response_bytes' => null,
        'max_pages' => null,
        'live_capable' => false,
        'auth_type' => 'none',
    ];
}

function dsCreate(array $overrides = [])
{
    return test()->postJson(DS_URL, dsBody($overrides), DS_HEADERS);
}

function dsUpdate(string $id, int $revision, array $overrides = [])
{
    return test()->putJson(DS_URL."/{$id}", dsBody($overrides) + ['revision' => $revision], DS_HEADERS);
}

function dsRows(): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from data_sources order by name');
}

function dsAudits(?string $action = null): array
{
    return Cluster::rows(Cluster::superuser(), "select * from audit_events where action like 'connector.data_source.%' and (?::text is null or action = ?) order by occurred_at, id", [$action, $action]);
}

function dsAllow(string $workspace, string $host = 'api.example.com', int $port = 443, string $scheme = 'https'): void
{
    Cluster::seedHostEntry($workspace, $host, $port, $scheme);
}

function dsRequireHttps(string $workspace): void
{
    Cluster::seedSettings($workspace);
    Cluster::superuser()->prepare('UPDATE workspace_settings SET require_https = true WHERE workspace_id = ?')->execute([$workspace]);
}

it('registers a Data Source: revision 1, the URL parts derived, UUIDv7 key, audited in the clear without headers or path', function () {
    $workspace = Cluster::workspace('Acme');
    [, $editor] = dsAdmin($workspace);
    dsAllow($workspace);

    $response = dsCreate(['name' => '  Sales API  ', 'headers' => [['name' => 'X-Team', 'value' => 'finance']], 'timeout_seconds' => '30', 'max_pages' => 5, 'live_capable' => true])
        ->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
    $id = $response->json('data.data_source_id');

    expect($response->json('data'))->toMatchArray([
        'name' => 'Sales API', 'base_url' => 'https://api.example.com/v1', 'scheme' => 'https', 'host' => 'api.example.com', 'port' => 443,
        'auth_type' => 'none', 'headers' => [['name' => 'X-Team', 'value' => 'finance']], 'timeout_seconds' => 30, 'max_response_bytes' => null,
        'max_pages' => 5, 'live_capable' => true, 'revision' => 1, 'health' => 'checking', 'last_successful_call_at' => null, 'blocks_using' => 0,
    ])
        ->and(Str::isUuid($id))->toBeTrue()
        ->and(substr($id, 14, 1))->toBe('7');

    $rows = dsRows();
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['id' => $id, 'workspace_id' => $workspace, 'name' => 'Sales API', 'revision' => 1, 'created_by_membership_id' => $editor, 'auth_type' => 'none']);

    $audit = dsAudits();
    $after = json_decode($audit[0]['after_state'], true);
    expect($audit)->toHaveCount(1)
        ->and($audit[0]['action'])->toBe('connector.data_source.created')
        ->and($audit[0]['actor'])->toBe($editor)
        ->and($audit[0]['subject'])->toBe('data_source:'.$id)
        ->and($audit[0]['request_id'])->not->toBeNull()
        ->and($audit[0]['before_state'])->toBeNull()
        ->and($after)->toMatchArray(['data_source_id' => $id, 'scheme' => 'https', 'host' => 'api.example.com', 'port' => 443, 'auth_type' => 'none', 'timeout_seconds' => 30, 'max_response_bytes' => null, 'max_pages' => 5, 'live_capable' => 'true', 'header_count' => 1, 'revision' => 1])
        ->and($after['headers'])->toMatch('/^hmac-sha256:[0-9a-f]{64}$/')
        ->and($audit[0]['after_state'])->not->toContain('finance')->not->toContain('/v1')->not->toContain('Sales');
});

it('starts the live capability off and stores it as sent', function () {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);
    dsAllow($workspace);

    $first = dsCreate(['name' => 'Default'])->assertCreated();
    $body = dsBody(['name' => 'Off']);
    unset($body['live_capable']);
    $second = test()->postJson(DS_URL, $body, DS_HEADERS)->assertCreated();

    expect($first->json('data.live_capable'))->toBeFalse()->and($second->json('data.live_capable'))->toBeFalse();

    dsCreate(['name' => 'Live', 'live_capable' => true])->assertCreated();
    dsCreate(['name' => 'Bad', 'live_capable' => 'maybe'])->assertStatus(422)->assertJsonPath('reasons.live_capable', 'invalid-boolean');

    expect(array_column(dsRows(), 'live_capable', 'name'))->toBe(['Default' => false, 'Live' => true, 'Off' => false]);
});

it('refuses a host that is not on the allowlist with a 422 on base_url and writes nothing', function (string $url) {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);
    dsAllow($workspace);

    $response = dsCreate(['base_url' => $url])->assertStatus(422);

    expect($response->json('errors'))->toHaveKey('base_url')
        ->and($response->json('reasons.base_url'))->toBe('host-not-allowlisted')
        ->and(dsRows())->toBe([])
        ->and(dsAudits())->toBe([]);
})->with([
    'another host' => ['https://evil.example.net/v1'],
    'another port' => ['https://api.example.com:8443/v1'],
    'another scheme (the entry is https, port 443)' => ['http://api.example.com:443/v1'],
    'a subdomain' => ['https://sub.api.example.com'],
]);

it('answers the blur check from the allowlist alone: 200 when allowed, the same 422 when not, and never resolves a name', function () {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);
    dsAllow($workspace);
    app()->bind(HostResolver::class, fn () => throw new RuntimeException('The blur check must not resolve a name.'));

    test()->postJson(DS_URL.'/check-url', ['base_url' => 'https://api.example.com/v1'], DS_HEADERS)->assertOk()->assertJsonPath('data.allowed', true);
    test()->postJson(DS_URL.'/check-url', ['base_url' => 'https://other.example.com'], DS_HEADERS)->assertStatus(422)->assertJsonPath('reasons.base_url', 'host-not-allowlisted');
    test()->postJson(DS_URL.'/check-url', ['base_url' => 'ftp://api.example.com'], DS_HEADERS)->assertStatus(422)->assertJsonPath('reasons.base_url', 'scheme');
    test()->postJson(DS_URL.'/check-url', [], DS_HEADERS)->assertStatus(422)->assertJsonPath('reasons.base_url', 'empty');

    expect(dsRows())->toBe([])->and(dsAudits())->toBe([]);
});

it('refuses a Base URL that is not a plain http(s) address, with its reason', function (mixed $url, string $reason) {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);
    dsAllow($workspace);

    $response = dsCreate(['base_url' => $url])->assertStatus(422);

    expect($response->json('reasons.base_url'))->toBe($reason)->and(dsRows())->toBe([]);
})->with([
    'userinfo' => ['https://user:pass@api.example.com', 'userinfo'],
    'query' => ['https://api.example.com/v1?key=1', 'query'],
    'fragment' => ['https://api.example.com/v1#top', 'fragment'],
    'other scheme' => ['ftp://api.example.com', 'scheme'],
    'no scheme' => ['api.example.com/v1', 'malformed'],
    'whitespace' => [' https://api.example.com', 'whitespace'],
    'a loopback literal' => ['https://127.0.0.1', 'invalid-host'],
    'a wildcard' => ['https://*.example.com', 'invalid-host'],
]);

it('refuses bad default headers with a field error and stores nothing', function (array $headers, string $field, string $reason) {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);
    dsAllow($workspace);

    $response = dsCreate(['headers' => $headers])->assertStatus(422);

    expect($response->json('errors'))->toHaveKey($field)
        ->and($response->json('reasons')[$field] ?? null)->toBe($reason)
        ->and(dsRows())->toBe([])
        ->and(dsAudits())->toBe([]);
})->with([
    'LF in a value' => [[['name' => 'X-A', 'value' => "a\nb"]], 'headers.0.value', 'header-value-invalid'],
    'CR in a value' => [[['name' => 'X-A', 'value' => "a\rb"]], 'headers.0.value', 'header-value-invalid'],
    'a trailing CRLF (never trimmed away)' => [[['name' => 'X-A', 'value' => "ok\r\n"]], 'headers.0.value', 'header-value-invalid'],
    'a NUL' => [[['name' => 'X-A', 'value' => "a\0b"]], 'headers.0.value', 'header-value-invalid'],
    'a tab' => [[['name' => 'X-A', 'value' => "a\tb"]], 'headers.0.value', 'header-value-invalid'],
    'DEL' => [[['name' => 'X-A', 'value' => "a\x7fb"]], 'headers.0.value', 'header-value-invalid'],
    'non-ASCII' => [[['name' => 'X-A', 'value' => 'caf'."\u{e9}"]], 'headers.0.value', 'header-value-invalid'],
    'a value that is not text' => [[['name' => 'X-A', 'value' => ['x']]], 'headers.0.value', 'header-value-invalid'],
    'a space in a name' => [[['name' => 'X A', 'value' => 'v']], 'headers.0.name', 'header-name-invalid'],
    'a colon in a name' => [[['name' => 'X-A:', 'value' => 'v']], 'headers.0.name', 'header-name-invalid'],
    'an empty name' => [[['name' => '', 'value' => 'v']], 'headers.0.name', 'header-name-invalid'],
    'Authorization' => [[['name' => 'Authorization', 'value' => 'Bearer x']], 'headers.0.name', 'header-name-reserved'],
    'proxy-authorization' => [[['name' => 'proxy-authorization', 'value' => 'x']], 'headers.0.name', 'header-name-reserved'],
    'COOKIE' => [[['name' => 'COOKIE', 'value' => 'a=b']], 'headers.0.name', 'header-name-reserved'],
    'Host (reserved by the transport)' => [[['name' => 'Host', 'value' => 'evil']], 'headers.0.name', 'header-name-reserved'],
    'Content-Length (reserved by the transport)' => [[['name' => 'Content-Length', 'value' => '0']], 'headers.0.name', 'header-name-reserved'],
    'a duplicate name differing by case' => [[['name' => 'X-A', 'value' => '1'], ['name' => 'x-a', 'value' => '2']], 'headers.1.name', 'header-name-duplicate'],
    'the second row' => [[['name' => 'X-A', 'value' => '1'], ['name' => 'X-B', 'value' => "2\n"]], 'headers.1.value', 'header-value-invalid'],
]);

it('accepts visible ASCII header values, spaces included, exactly as sent', function () {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);
    dsAllow($workspace);

    $value = ' visible ~ ASCII !"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}  ';
    $response = dsCreate(['headers' => [['name' => 'X-Plain', 'value' => $value], ['name' => 'Accept', 'value' => 'application/json']]])->assertCreated();

    expect($response->json('data.headers'))->toBe([['name' => 'X-Plain', 'value' => $value], ['name' => 'Accept', 'value' => 'application/json']]);
});

it('refuses a name that is empty, too long, or holds control or invisible characters, and a duplicate that differs by case or spaces', function (mixed $name, string $reason) {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);
    dsAllow($workspace);
    dsCreate(['name' => 'Sales API'])->assertCreated();

    $response = dsCreate(['name' => $name])->assertStatus(422);

    expect($response->json('reasons.name'))->toBe($reason)->and(dsRows())->toHaveCount(1);
})->with([
    'missing' => [null, 'name-required'],
    'blank' => ['   ', 'name-required'],
    'too long' => [str_repeat('a', 65), 'name-too-long'],
    'a control character' => ["Sales\x01API", 'name-invalid-characters'],
    'a zero-width space' => ["Sales\u{200B}API", 'name-invalid-characters'],
    'a newline' => ["Sales\nAPI", 'name-invalid-characters'],
    'a no-break space' => ["Sales\u{A0}API", 'name-invalid-characters'],
    'a leading no-break space' => ["\u{A0}Sales", 'name-invalid-characters'],
    'an ideographic space' => ["Sales\u{3000}API", 'name-invalid-characters'],
    'only an ideographic space' => ["\u{3000}", 'name-invalid-characters'],
    'the same name' => ['Sales API', 'name-taken'],
    'differing by case' => ['SALES api', 'name-taken'],
    'differing by outer spaces' => ['  sales API ', 'name-taken'],
]);

it('allows the same name in another Workspace and 64 characters', function () {
    $acme = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    Cluster::seedDataSource($other, 'Sales API');
    dsAdmin($acme);
    dsAllow($acme);

    dsCreate(['name' => 'Sales API'])->assertCreated();
    dsCreate(['name' => str_repeat('n', 64)])->assertCreated();

    expect(dsRows())->toHaveCount(3);
});

it('saves a plain http Base URL when require_https is off, audits scheme=http and lists it as http', function () {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);
    dsAllow($workspace, 'legacy.example.com', 8080, 'http');

    $response = dsCreate(['name' => 'Legacy', 'base_url' => 'http://legacy.example.com:8080/api'])->assertCreated();

    expect($response->json('data'))->toMatchArray(['scheme' => 'http', 'port' => 8080, 'base_url' => 'http://legacy.example.com:8080/api'])
        ->and(json_decode(dsAudits('connector.data_source.created')[0]['after_state'], true))->toMatchArray(['scheme' => 'http', 'port' => 8080]);

    $listed = test()->getJson(DS_URL, DS_HEADERS)->assertOk()->json('data');
    expect($listed[0]['scheme'])->toBe('http');
    test()->getJson(DS_URL.'/'.$response->json('data.data_source_id'), DS_HEADERS)->assertOk()->assertJsonPath('data.scheme', 'http');
});

it('refuses an http Base URL when the Workspace setting or the deployment setting requires https, and nothing is written', function (string $source) {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);
    dsAllow($workspace, 'legacy.example.com', 80, 'http');
    dsAllow($workspace, 'api.example.com');

    if ($source === 'workspace') {
        dsRequireHttps($workspace);
    } else {
        config(['dashflow.tunables.guards.require_https.value' => true]);
    }

    $response = dsCreate(['base_url' => 'http://legacy.example.com'])->assertStatus(422);

    expect($response->json('reasons.base_url'))->toBe('https-required')
        ->and($response->json('errors.base_url.0'))->toContain('https')
        ->and(dsRows())->toBe([])
        ->and(dsAudits())->toBe([]);

    test()->postJson(DS_URL.'/check-url', ['base_url' => 'http://legacy.example.com'], DS_HEADERS)->assertStatus(422)->assertJsonPath('reasons.base_url', 'https-required');
    dsCreate(['base_url' => 'https://api.example.com'])->assertCreated();
})->with(['workspace', 'deployment']);

it('turns require_https on and off without code: an http source saved earlier cannot be saved again while it is on', function () {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);
    dsAllow($workspace, 'legacy.example.com', 80, 'http');
    $id = dsCreate(['name' => 'Legacy', 'base_url' => 'http://legacy.example.com'])->assertCreated()->json('data.data_source_id');
    dsRequireHttps($workspace);

    dsUpdate($id, 1, ['name' => 'Legacy', 'base_url' => 'http://legacy.example.com'])->assertStatus(422)->assertJsonPath('reasons.base_url', 'https-required');
    Cluster::superuser()->prepare('UPDATE workspace_settings SET require_https = false WHERE workspace_id = ?')->execute([$workspace]);
    dsUpdate($id, 1, ['name' => 'Legacy', 'base_url' => 'http://legacy.example.com'])->assertOk()->assertJsonPath('data.revision', 2);
});

it('edits a Data Source: revision plus one, updated audited with an allowlisted before and after, headers never in the clear', function () {
    $workspace = Cluster::workspace('Acme');
    [, $editor] = dsAdmin($workspace);
    dsAllow($workspace);
    dsAllow($workspace, 'other.example.com', 8443);
    $id = dsCreate(['headers' => [['name' => 'X-Team', 'value' => 'finance']], 'timeout_seconds' => 10])->assertCreated()->json('data.data_source_id');

    $response = dsUpdate($id, 1, ['name' => 'Sales API v2', 'base_url' => 'https://other.example.com:8443/secret/path', 'headers' => [['name' => 'X-Team', 'value' => 'ops'], ['name' => 'Accept', 'value' => 'text/csv']], 'timeout_seconds' => 20, 'live_capable' => true])->assertOk();

    expect($response->json('data'))->toMatchArray(['name' => 'Sales API v2', 'host' => 'other.example.com', 'port' => 8443, 'revision' => 2, 'timeout_seconds' => 20, 'live_capable' => true])
        ->and(dsRows()[0])->toMatchArray(['revision' => 2, 'created_by_membership_id' => $editor]);

    $audit = dsAudits('connector.data_source.updated');
    $before = json_decode($audit[0]['before_state'], true);
    $after = json_decode($audit[0]['after_state'], true);

    expect($audit)->toHaveCount(1)
        ->and($audit[0]['actor'])->toBe($editor)
        ->and($audit[0]['subject'])->toBe('data_source:'.$id)
        ->and($before)->toMatchArray(['host' => 'api.example.com', 'port' => 443, 'timeout_seconds' => 10, 'live_capable' => 'false', 'header_count' => 1, 'revision' => 1])
        ->and($after)->toMatchArray(['host' => 'other.example.com', 'port' => 8443, 'timeout_seconds' => 20, 'live_capable' => 'true', 'header_count' => 2, 'revision' => 2])
        ->and($before['headers'])->not->toBe($after['headers'])
        ->and($audit[0]['before_state'].$audit[0]['after_state'])->not->toContain('finance')->not->toContain('ops')->not->toContain('secret')->not->toContain('text/csv');

    dsUpdate($id, 2, ['name' => 'Sales API v2', 'base_url' => 'https://other.example.com:8443/x', 'headers' => [], 'live_capable' => true])->assertOk()->assertJsonPath('data.revision', 3);
});

it('answers 409 with the current state for a stale edit and changes nothing', function () {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);
    dsAllow($workspace);
    $id = dsCreate()->assertCreated()->json('data.data_source_id');
    dsUpdate($id, 1, ['name' => 'Renamed'])->assertOk();

    $response = dsUpdate($id, 1, ['name' => 'Stale rename'])->assertStatus(409);

    expect($response->json('error.code'))->toBe(ErrorCode::RevisionConflict->value)
        ->and($response->json('current.data'))->toMatchArray(['data_source_id' => $id, 'name' => 'Renamed', 'revision' => 2])
        ->and(dsRows()[0])->toMatchArray(['name' => 'Renamed', 'revision' => 2])
        ->and(dsAudits('connector.data_source.updated'))->toHaveCount(1);
});

it('answers a stale revision with a rule problem as 409, and a rule problem on the current revision as 422', function () {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);
    dsAllow($workspace);
    $id = dsCreate()->assertCreated()->json('data.data_source_id');
    dsUpdate($id, 1)->assertOk();

    dsUpdate($id, 1, ['base_url' => 'https://nowhere.example.net'])->assertStatus(409);
    dsUpdate($id, 2, ['base_url' => 'https://nowhere.example.net'])->assertStatus(422)->assertJsonPath('reasons.base_url', 'host-not-allowlisted');

    expect(dsRows()[0])->toMatchArray(['revision' => 2, 'base_url' => 'https://api.example.com/v1']);
});

it('requires a revision on an edit and refuses a revision that is not a whole number', function (array $extra) {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);
    dsAllow($workspace);
    $id = dsCreate()->assertCreated()->json('data.data_source_id');

    test()->putJson(DS_URL."/{$id}", dsBody() + $extra, DS_HEADERS)->assertStatus(422)->assertJsonStructure(['errors' => ['revision']]);
})->with([
    'missing' => [[]],
    'zero' => [['revision' => 0]],
    'text' => [['revision' => 'abc']],
]);

it('refuses a duplicate name on an edit, but keeps the Data Source\'s own name', function () {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);
    dsAllow($workspace);
    $first = dsCreate(['name' => 'First'])->assertCreated()->json('data.data_source_id');
    dsCreate(['name' => 'Second'])->assertCreated();

    dsUpdate($first, 1, ['name' => 'SECOND'])->assertStatus(422)->assertJsonPath('reasons.name', 'name-taken');
    dsUpdate($first, 1, ['name' => 'first'])->assertOk()->assertJsonPath('data.name', 'first');
});

it('validates the limits: zero, negative, fractional, text and above a set platform ceiling are 422; blank means the platform setting', function (mixed $value, ?string $reason) {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);
    dsAllow($workspace);
    config([
        'dashflow.tunables.guards.platform_timeout_ceiling.value' => '60',
        'dashflow.tunables.guards.max_bytes.value' => '1000000',
        'dashflow.tunables.guards.max_pages.value' => '20',
    ]);

    foreach (['timeout_seconds' => 60, 'max_response_bytes' => 1000000, 'max_pages' => 20] as $field => $ceiling) {
        $given = $value === 'ceiling+1' ? $ceiling + 1 : ($value === 'ceiling' ? $ceiling : $value);
        $response = dsCreate(['name' => "Limit {$field}", $field => $given]);

        if ($reason === null) {
            $response->assertCreated();
            expect($response->json("data.{$field}"))->toBe($given === null || $given === '' ? null : (int) $given);

            continue;
        }

        $response->assertStatus(422);
        expect($response->json("reasons.{$field}"))->toBe($reason, $field)->and($response->json("errors.{$field}.0"))->not->toBeEmpty();
    }

    expect(count(dsRows()))->toBe($reason === null ? 3 : 0);
})->with([
    'zero' => [0, 'not-positive-integer'],
    'negative' => [-5, 'not-positive-integer'],
    'zero as text' => ['0', 'not-positive-integer'],
    'fractional' => [1.5, 'not-positive-integer'],
    'fractional text' => ['1.5', 'not-positive-integer'],
    'text' => ['abc', 'not-positive-integer'],
    'a boolean' => [true, 'not-positive-integer'],
    'above the ceiling' => ['ceiling+1', 'above-ceiling'],
    'at the ceiling' => ['ceiling', null],
    'blank' => ['', null],
    'null' => [null, null],
]);

it('checks no ceiling that is not set: nothing is invented', function () {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);
    dsAllow($workspace);

    dsCreate(['timeout_seconds' => 3600, 'max_response_bytes' => 999999999999, 'max_pages' => 100000])->assertCreated()
        ->assertJsonPath('data.max_response_bytes', 999999999999);
});

it('refuses an auth type that is not offered: OAuth2 client credentials wait for Story 2.7, anything else is not a type', function (string $auth, string $reason) {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);
    dsAllow($workspace);

    dsCreate(['auth_type' => $auth])->assertStatus(422)->assertJsonPath('reasons.auth_type', $reason);

    expect(dsRows())->toBe([]);
})->with([
    'oauth2' => ['oauth2_client_credentials', 'auth-type-unavailable'],
    'anything' => ['anything', 'auth-type-invalid'],
]);

it('lists the Workspace\'s Data Sources with search, whitelisted sort, counts and the placeholder fields, and an empty Workspace has none', function () {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);
    dsAllow($workspace);
    dsAllow($workspace, 'zeta.example.com');

    test()->getJson(DS_URL, DS_HEADERS)->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0)->assertJsonPath('meta.matched', 0);

    dsCreate(['name' => 'beta', 'base_url' => 'https://zeta.example.com'])->assertCreated();
    dsCreate(['name' => 'Alpha'])->assertCreated();
    dsCreate(['name' => 'Gamma_100%', 'base_url' => 'https://api.example.com/x'])->assertCreated();

    $names = fn ($response) => array_column($response->json('data'), 'name');

    $default = test()->getJson(DS_URL, DS_HEADERS)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect($names($default))->toBe(['Alpha', 'beta', 'Gamma_100%'])
        ->and($default->json('meta'))->toMatchArray(['total' => 3, 'matched' => 3, 'sort' => 'name', 'direction' => 'asc'])
        ->and($default->json('data.0'))->toMatchArray(['health' => 'checking', 'last_successful_call_at' => null, 'blocks_using' => 0]);

    expect($names(test()->getJson(DS_URL.'?sort=name&direction=desc', DS_HEADERS)))->toBe(['Gamma_100%', 'beta', 'Alpha'])
        ->and($names(test()->getJson(DS_URL.'?sort=host', DS_HEADERS)))->toBe(['Alpha', 'Gamma_100%', 'beta'])
        ->and($names(test()->getJson(DS_URL.'?sort=bogus', DS_HEADERS)))->toBe(['Alpha', 'beta', 'Gamma_100%'])
        ->and($names(test()->getJson(DS_URL.'?q=ZETA', DS_HEADERS)))->toBe(['beta'])
        ->and($names(test()->getJson(DS_URL.'?q=alp', DS_HEADERS)))->toBe(['Alpha'])
        ->and($names(test()->getJson(DS_URL.'?q=_100%25', DS_HEADERS)))->toBe(['Gamma_100%'])
        ->and($names(test()->getJson(DS_URL.'?q=a_a', DS_HEADERS)))->toBe([])
        ->and(test()->getJson(DS_URL.'?q=zzz', DS_HEADERS)->json('meta'))->toMatchArray(['total' => 3, 'matched' => 0]);

    test()->getJson(DS_URL.'?direction=sideways', DS_HEADERS)->assertStatus(422);
});

it('never shows another Workspace\'s Data Sources: zero rows in the list, 404 on an id', function () {
    $acme = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    $theirs = Cluster::seedDataSource($other, 'Theirs');
    dsAdmin($acme);
    dsAllow($acme);

    test()->getJson(DS_URL, DS_HEADERS)->assertOk()->assertJsonPath('data', []);
    test()->getJson(DS_URL.'/'.$theirs, DS_HEADERS)->assertNotFound();
    dsUpdate($theirs, 1)->assertNotFound();
    test()->getJson(DS_URL.'/not-a-uuid', DS_HEADERS)->assertNotFound();
    dsUpdate((string) Str::uuid7(), 1)->assertNotFound();

    expect(array_column(dsRows(), 'name'))->toBe(['Theirs'])->and(dsAudits())->toBe([]);
});

it('denies an Admin without data_sources.manage on every Data source route with access.not_authorized, audits it and returns no data', function () {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace, ['settings.manage', 'users.manage']);
    $id = Cluster::seedDataSource($workspace, 'Secret source', 'secret.example.com');
    $calls = [
        fn () => test()->getJson(DS_URL, DS_HEADERS),
        fn () => dsCreate(),
        fn () => test()->getJson(DS_URL."/{$id}", DS_HEADERS),
        fn () => dsUpdate($id, 1),
        fn () => test()->postJson(DS_URL.'/check-url', ['base_url' => 'https://api.example.com'], DS_HEADERS),
        fn () => test()->get(route('admin.data-sources.index')),
        fn () => test()->get(route('admin.data-sources.create')),
        fn () => test()->get(route('admin.data-sources.edit', $id)),
    ];

    foreach ($calls as $n => $call) {
        Cache::flush();
        $count = fn (): int => (int) Cluster::rows(Cluster::superuser(), "select count(*) as n from audit_events where action = 'access.admin.denied'")[0]['n'];
        $before = $count();
        $response = $call();

        $response->assertForbidden();
        expect($count())->toBe($before + 1, "call {$n} audited")
            ->and($response->getContent())->not->toContain('Secret source')->not->toContain('secret.example.com');
    }

    expect(array_column(dsRows(), 'name'))->toBe(['Secret source'])->and(dsAudits())->toBe([]);
});

it('denies the User area and a demoted Admin on the Data source pages and API', function () {
    $workspace = Cluster::workspace('Acme');
    [, $membership] = dsAdmin($workspace);
    Cluster::seedDataSource($workspace, 'Hidden');

    test()->withSession(['workspace_id' => $workspace, 'area' => 'user']);
    test()->getJson(DS_URL, DS_HEADERS)->assertForbidden();
    test()->get(route('admin.data-sources.index'))->assertForbidden();
    test()->get(route('admin.data-sources.create'))->assertForbidden();

    test()->withSession(['workspace_id' => $workspace, 'area' => 'admin']);
    test()->get(route('admin.data-sources.index'))->assertOk()->assertInertia(fn ($page) => $page->component('admin/DataSources'));
    test()->get(route('admin.data-sources.create'))->assertOk()->assertInertia(fn ($page) => $page->component('admin/DataSourceForm')->missing('dataSourceId'));
    $id = Cluster::seedDataSource($workspace, 'Shown');
    test()->get(route('admin.data-sources.edit', $id))->assertOk()->assertInertia(fn ($page) => $page->component('admin/DataSourceForm')->where('dataSourceId', $id));

    Cluster::superuser()->prepare("UPDATE workspace_memberships SET role = 'user' WHERE id = ?")->execute([$membership]);
    test()->getJson(DS_URL, DS_HEADERS)->assertForbidden();
});

it('lists Data sources in the Admin navigation only, never in the User area', function () {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);

    test()->withSession(['workspace_id' => $workspace, 'area' => 'user']);

    test()->get(route('overview'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('shell.area', 'user')
        ->where('shell.items', fn ($items) => ! in_array('data-sources', array_column($items->toArray(), 'key'), true)));
});

it('rolls the Data Source back when the audit cannot be written (audit shares the change\'s transaction)', function () {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);
    dsAllow($workspace);
    Cluster::superuser()->exec("ALTER TABLE audit_events ADD CONSTRAINT ds_block CHECK (action <> 'connector.data_source.created')");

    try {
        test()->withoutExceptionHandling();
        expect(fn () => dsCreate())->toThrow(QueryException::class);
    } finally {
        Cluster::superuser()->exec('ALTER TABLE audit_events DROP CONSTRAINT ds_block');
    }

    expect(dsRows())->toBe([])->and(dsAudits())->toBe([]);
});

it('rolls an edit back when the audit cannot be written', function () {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);
    dsAllow($workspace);
    $id = dsCreate()->assertCreated()->json('data.data_source_id');
    Cluster::superuser()->exec("ALTER TABLE audit_events ADD CONSTRAINT ds_block CHECK (action <> 'connector.data_source.updated')");

    try {
        test()->withoutExceptionHandling();
        expect(fn () => dsUpdate($id, 1, ['name' => 'Changed']))->toThrow(QueryException::class);
    } finally {
        Cluster::superuser()->exec('ALTER TABLE audit_events DROP CONSTRAINT ds_block');
    }

    expect(dsRows()[0])->toMatchArray(['name' => 'Sales API', 'revision' => 1])->and(dsAudits('connector.data_source.updated'))->toBe([]);
});

it('keeps the table forced under row-level security, owned by migrator, with SELECT, INSERT and UPDATE for app only', function () {
    Cluster::migrateOnce();

    $flags = Cluster::rows(Cluster::superuser(), "select relrowsecurity, relforcerowsecurity, pg_get_userbyid(relowner) as owner from pg_class where oid = 'data_sources'::regclass")[0];
    expect($flags)->toMatchArray(['relrowsecurity' => true, 'relforcerowsecurity' => true, 'owner' => 'migrator']);

    $privileges = Cluster::rows(Cluster::superuser(), "select privilege_type from information_schema.role_table_grants where table_name = 'data_sources' and grantee = 'app' order by privilege_type");
    expect(array_column($privileges, 'privilege_type'))->toBe(['INSERT', 'SELECT', 'UPDATE']);

    $workspace = Cluster::workspace('Acme');
    Cluster::seedDataSource($workspace, 'Mine');
    expect(fn () => Cluster::inWorkspace(Cluster::directApp(), $workspace, fn ($pdo) => $pdo->exec('delete from data_sources')))->toThrow(PDOException::class, 'permission denied');
});

it('returns zero rows with no context and for another Workspace', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    Cluster::seedDataSource($a, 'Mine');

    expect(Cluster::rows(Cluster::directApp(), 'select * from data_sources'))->toBe([])
        ->and(Cluster::inWorkspace(Cluster::directApp(), $b, fn ($pdo) => Cluster::rows($pdo, 'select * from data_sources')))->toBe([]);
});

it('refuses at the database a bad scheme, port, auth type, limit, header shape, name and duplicate name; another Workspace may repeat a name', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    Cluster::seedDataSource($a, 'Dup');

    expect(fn () => Cluster::seedDataSource($a, 'dup'))->toThrow(PDOException::class, 'data_sources_workspace_name_unique')
        ->and(fn () => Cluster::seedDataSource($a, 'Other', 'x.example.com', 443, 'ftp'))->toThrow(PDOException::class, 'data_sources_scheme_check')
        ->and(fn () => Cluster::seedDataSource($a, 'Port', 'x.example.com', 0))->toThrow(PDOException::class, 'data_sources_port_check')
        ->and(fn () => Cluster::seedDataSource($a, 'Host', 'UPPER.example.com'))->toThrow(PDOException::class, 'data_sources_host_check')
        ->and(fn () => Cluster::seedDataSource($a, ' padded '))->toThrow(PDOException::class, 'data_sources_name_check')
        ->and(fn () => Cluster::superuser()->exec("UPDATE data_sources SET auth_type = 'magic'"))->toThrow(PDOException::class, 'data_sources_auth_type_check')
        ->and(fn () => Cluster::superuser()->exec('UPDATE data_sources SET max_pages = 0'))->toThrow(PDOException::class, 'data_sources_limits_check')
        ->and(fn () => Cluster::superuser()->exec("UPDATE data_sources SET default_headers = '{}'::jsonb"))->toThrow(PDOException::class, 'data_sources_default_headers_check')
        ->and(fn () => Cluster::superuser()->exec('UPDATE data_sources SET revision = 0'))->toThrow(PDOException::class, 'data_sources_revision_check');

    Cluster::seedDataSource($b, 'Dup');
});

it('creates the Workspace setting require_https off by default', function () {
    $workspace = Cluster::workspace('Acme');
    Cluster::seedSettings($workspace);

    expect(Cluster::rows(Cluster::superuser(), 'select require_https from workspace_settings where workspace_id = ?', [$workspace]))->toBe([['require_https' => false]]);
});

it('returns header names only in the list, and the values on a single read', function () {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);
    dsAllow($workspace);
    $id = dsCreate(['headers' => [['name' => 'X-Team', 'value' => 'finance-value']]])->assertCreated()->json('data.data_source_id');

    $list = test()->getJson(DS_URL, DS_HEADERS)->assertOk();

    expect($list->json('data.0.headers'))->toBe([['name' => 'X-Team']])
        ->and($list->getContent())->not->toContain('finance-value');
    test()->getJson(DS_URL.'/'.$id, DS_HEADERS)->assertOk()->assertJsonPath('data.headers.0.value', 'finance-value');
});

it('throttles the blur check in its own bucket, so exhausting it does not throttle a save', function () {
    $workspace = Cluster::workspace('Acme');
    dsAdmin($workspace);
    dsAllow($workspace);

    $last = null;
    for ($i = 0; $i < 61; $i++) {
        $last = test()->postJson(DS_URL.'/check-url', ['base_url' => 'https://api.example.com'], DS_HEADERS)->status();
    }

    expect($last)->toBe(429);
    dsCreate()->assertCreated();
});
