<?php

use App\Models\User;
use App\Modules\Connector\Contracts\DependentDataSource;
use App\Modules\Connector\Contracts\ErrorCode;
use App\Modules\Connector\Contracts\HostAllowlistDependents;
use App\Modules\Connector\Infrastructure\SqlHostAllowlistDependents;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 2.1 against the real PostgreSQL: an Admin with `settings.manage` adds and removes allowlist hosts; entries live
// under row-level security; removal goes through a SECURITY DEFINER function; audit lands in the same transaction;
// a stale list revision is a 409 with the current list; and the gate follows Story 1.19.
beforeEach(fn () => $this->withoutVite());

const HAL_HEADERS = ['Referer' => 'http://localhost:8000'];
const HAL_URL = '/api/v1/admin/host-allowlist';

/** A member of the Workspace; returns [user ID, membership ID]. */
function halMember(string $workspaceId, string $email, string $role = 'user', array $permissions = [], ?string $name = null): array
{
    $user = Cluster::user($email);

    if ($name !== null) {
        Cluster::superuser()->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $user]);
    }

    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspaceId, $user, $role, 'active']);

    foreach ($permissions as $permission) {
        Cluster::superuser()->prepare('INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
            ->execute([(string) Str::uuid7(), $workspaceId, $membership, $permission]);
    }

    return [$user, $membership];
}

/** The Admin holding `settings.manage`, signed in; returns [user ID, membership ID]. */
function halAdmin(string $workspaceId, array $permissions = ['settings.manage'], string $email = 'ada@example.test', string $name = 'Ada Admin'): array
{
    [$user, $membership] = halMember($workspaceId, $email, 'admin', $permissions, $name);
    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspaceId, 'area' => 'admin']);

    return [$user, $membership];
}

function halAdd(string $host, int $revision, ?string $scheme = null)
{
    return test()->postJson(HAL_URL, array_filter(['host' => $host, 'scheme' => $scheme, 'revision' => $revision], fn ($v) => $v !== null), HAL_HEADERS);
}

function halRemove(string $entry, int $revision)
{
    return test()->deleteJson(HAL_URL."/{$entry}?revision={$revision}", [], HAL_HEADERS);
}

function halEntries(): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from host_allowlist_entries order by host, port');
}

function halRevision(string $workspace): ?int
{
    $rows = Cluster::rows(Cluster::superuser(), 'select revision from host_allowlist_versions where workspace_id = ?', [$workspace]);

    return $rows === [] ? null : (int) $rows[0]['revision'];
}

function halAudits(?string $action = null): array
{
    return Cluster::rows(Cluster::superuser(), "select * from audit_events where action like 'connector.host_allowlist_entry.%' and (?::text is null or action = ?) order by occurred_at, id", [$action, $action]);
}

it('adds a host: the port is resolved, the entry stored under the Workspace and audited in the clear with actor and request ID', function () {
    $workspace = Cluster::workspace('Acme');
    [, $editor] = halAdmin($workspace);

    $response = halAdd('API.Example.com', 0)->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
    $id = $response->json('data.entry_id');

    expect($response->json('data'))->toMatchArray(['host' => 'api.example.com', 'scheme' => 'https', 'port' => 443, 'added_by' => 'Ada Admin'])
        ->and($response->json('meta.revision'))->toBe(1)
        ->and(halEntries())->toHaveCount(1)
        ->and(halEntries()[0])->toMatchArray(['id' => $id, 'workspace_id' => $workspace, 'host' => 'api.example.com', 'scheme' => 'https', 'port' => 443, 'added_by_membership_id' => $editor])
        ->and(halRevision($workspace))->toBe(1);

    $audit = halAudits();
    expect($audit)->toHaveCount(1)
        ->and($audit[0]['action'])->toBe('connector.host_allowlist_entry.created')
        ->and($audit[0]['actor'])->toBe($editor)
        ->and($audit[0]['subject'])->toBe('host_allowlist_entry:'.$id)
        ->and($audit[0]['request_id'])->not->toBeNull()
        ->and($audit[0]['security'])->toBeFalse()
        ->and(json_decode($audit[0]['after_state'], true))->toEqual(['entry_id' => $id, 'host' => 'api.example.com', 'scheme' => 'https', 'port' => 443])
        ->and($audit[0]['after_state'])->not->toContain('ada@example.test');
});

it('adds a host with a port and the http scheme', function () {
    $workspace = Cluster::workspace('Acme');
    halAdmin($workspace);

    $http = halAdd('api.example.com', 0, 'http')->assertCreated();
    $port = halAdd('api.example.com:8443', 1)->assertCreated();

    expect($http->json('data'))->toMatchArray(['scheme' => 'http', 'port' => 80])
        ->and($port->json('data'))->toMatchArray(['scheme' => 'https', 'port' => 8443])
        ->and(halEntries())->toHaveCount(2)
        ->and(halRevision($workspace))->toBe(2);
});

it('refuses a duplicate host and port whatever the scheme with a 422 and no second row', function (string $host, string $scheme) {
    $workspace = Cluster::workspace('Acme');
    halAdmin($workspace);
    halAdd('api.example.com', 0, 'https')->assertCreated();

    $response = halAdd($host, 1, $scheme)->assertStatus(422);

    expect($response->json('errors'))->toHaveKey('host')
        ->and($response->json('reasons.host'))->toBe('duplicate')
        ->and(halEntries())->toHaveCount(1)
        ->and(halAudits())->toHaveCount(1)
        ->and(halRevision($workspace))->toBe(1);
})->with([
    'same' => ['api.example.com', 'https'],
    'case differs' => ['API.EXAMPLE.COM', 'https'],
    'explicit default port' => ['api.example.com:443', 'https'],
    'other scheme resolves another port' => ['api.example.com:443', 'http'],
]);

it('treats the same host on another port, and the same host in another Workspace, as new entries', function () {
    $acme = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    Cluster::seedHostEntry($other, 'api.example.com', 443);
    halAdmin($acme);

    halAdd('api.example.com', 0)->assertCreated();
    halAdd('api.example.com:8443', 1)->assertCreated();

    expect(halEntries())->toHaveCount(3);
});

it('refuses an invalid value with a 422 field error and the reason, and writes nothing', function (mixed $host, string $field, string $reason) {
    $workspace = Cluster::workspace('Acme');
    halAdmin($workspace);

    $response = test()->postJson(HAL_URL, ['host' => $host, 'scheme' => 'https', 'revision' => 0], HAL_HEADERS)->assertStatus(422);

    expect($response->json('errors'))->toHaveKey($field)
        ->and($response->json("reasons.{$field}"))->toBe($reason)
        ->and(halEntries())->toBe([])
        ->and(halAudits())->toBe([])
        ->and(halRevision($workspace))->toBeNull();
})->with([
    'empty' => ['', 'host', 'empty'],
    'null' => [null, 'host', 'empty'],
    'whitespace around' => [' api.example.com ', 'host', 'whitespace'],
    'scheme' => ['https://api.example.com', 'host', 'forbidden_character'],
    'path' => ['api.example.com/v1', 'host', 'forbidden_character'],
    'bare star' => ['*', 'host', 'forbidden_character'],
    'wildcard' => ['*.example.com', 'host', 'forbidden_character'],
    'userinfo' => ['u@api.example.com', 'host', 'forbidden_character'],
    'non-ascii' => ['bücher.example', 'host', 'non_ascii'],
    'loopback' => ['127.0.0.1', 'host', 'blocked_address'],
    'metadata' => ['169.254.169.254', 'host', 'blocked_address'],
    'private IPv6' => ['[fd00::1]', 'host', 'blocked_address'],
    'numeric spelling' => ['2130706433', 'host', 'numeric_address'],
    'port out of range' => ['api.example.com:70000', 'host', 'invalid_port'],
]);

it('refuses a scheme other than http and https, a missing or non-numeric revision and a missing host', function (array $body, string $field) {
    $workspace = Cluster::workspace('Acme');
    halAdmin($workspace);

    test()->postJson(HAL_URL, $body, HAL_HEADERS)->assertStatus(422)->assertJsonStructure(['errors' => [$field]]);

    expect(halEntries())->toBe([]);
})->with([
    'scheme' => [['host' => 'a.example', 'scheme' => 'ftp', 'revision' => 0], 'scheme'],
    'no revision' => [['host' => 'a.example'], 'revision'],
    'text revision' => [['host' => 'a.example', 'revision' => 'x'], 'revision'],
    'negative revision' => [['host' => 'a.example', 'revision' => -1], 'revision'],
    'no host' => [['revision' => 0], 'host'],
]);

it('does not trim the host silently', function () {
    $workspace = Cluster::workspace('Acme');
    halAdmin($workspace);

    test()->postJson(HAL_URL, ['host' => "a.example\n", 'revision' => 0], HAL_HEADERS)->assertStatus(422)->assertJsonPath('reasons.host', 'whitespace');

    expect(halEntries())->toBe([]);
});

it('answers 409 with the current list and revision for a stale add and changes nothing', function () {
    $workspace = Cluster::workspace('Acme');
    halAdmin($workspace);
    halAdd('first.example.com', 0)->assertCreated();

    $response = halAdd('second.example.com', 0)->assertStatus(409);

    expect($response->json('error.code'))->toBe(ErrorCode::RevisionConflict->value)
        ->and($response->json('current.meta.revision'))->toBe(1)
        ->and(array_column($response->json('current.data'), 'host'))->toBe(['first.example.com'])
        ->and(halEntries())->toHaveCount(1)
        ->and(halRevision($workspace))->toBe(1)
        ->and(halAudits())->toHaveCount(1);
});

it('answers 409 for a stale removal, and removal then works with the fresh revision', function () {
    $workspace = Cluster::workspace('Acme');
    halAdmin($workspace);
    $id = halAdd('first.example.com', 0)->json('data.entry_id');
    halAdd('second.example.com', 1)->assertCreated();

    $response = halRemove($id, 1)->assertStatus(409);

    expect($response->json('error.code'))->toBe(ErrorCode::RevisionConflict->value)
        ->and($response->json('current.meta.revision'))->toBe(2)
        ->and($response->json('current.data'))->toHaveCount(2)
        ->and(halEntries())->toHaveCount(2);

    halRemove($id, 2)->assertOk();

    expect(halEntries())->toHaveCount(1);
});

it('removes an entry through the function: audited with the actor, the revision bumped', function () {
    $workspace = Cluster::workspace('Acme');
    [, $editor] = halAdmin($workspace);
    $id = halAdd('api.example.com:8443', 0, 'http')->json('data.entry_id');

    $response = halRemove($id, 1)->assertOk();

    expect($response->json('data.entry_id'))->toBe($id)
        ->and($response->json('meta.revision'))->toBe(2)
        ->and(halEntries())->toBe([])
        ->and(halRevision($workspace))->toBe(2);

    $audit = halAudits('connector.host_allowlist_entry.removed');
    expect($audit)->toHaveCount(1)
        ->and($audit[0]['actor'])->toBe($editor)
        ->and($audit[0]['subject'])->toBe('host_allowlist_entry:'.$id)
        ->and(json_decode($audit[0]['after_state'], true))->toEqual(['entry_id' => $id, 'host' => 'api.example.com', 'scheme' => 'http', 'port' => 8443]);
});

it('answers 404 for an absent entry, a malformed ID and an entry of another Workspace', function () {
    $acme = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    $theirs = Cluster::seedHostEntry($other, 'theirs.example.com');
    halAdmin($acme);

    halRemove((string) Str::uuid7(), 0)->assertNotFound();
    halRemove('not-an-id', 0)->assertNotFound();
    halRemove($theirs, 0)->assertNotFound();
    test()->getJson(HAL_URL.'/'.$theirs.'/dependents', HAL_HEADERS)->assertNotFound();
    test()->getJson(HAL_URL.'/'.Str::uuid7().'/dependents', HAL_HEADERS)->assertNotFound();

    expect(Cluster::rows(Cluster::superuser(), 'select id from host_allowlist_entries where id = ?', [$theirs]))->toHaveCount(1)
        ->and(halAudits())->toBe([]);
});

it('requires the revision on a removal', function () {
    $workspace = Cluster::workspace('Acme');
    halAdmin($workspace);
    $id = Cluster::seedHostEntry($workspace, 'a.example.com');

    test()->deleteJson(HAL_URL.'/'.$id, [], HAL_HEADERS)->assertStatus(422)->assertJsonStructure(['errors' => ['revision']]);

    expect(halEntries())->toHaveCount(1);
});

it('lists no dependents for a host no Data Source uses, and the Data Sources on that host and port (Story 2.3) otherwise', function () {
    $workspace = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    halAdmin($workspace);
    $id = Cluster::seedHostEntry($workspace, 'api.example.com', 443);
    $unused = Cluster::seedHostEntry($workspace, 'unused.example.com', 443);
    $sales = Cluster::seedDataSource($workspace, 'Sales API', 'api.example.com', 443);
    $billing = Cluster::seedDataSource($workspace, 'billing API', 'api.example.com', 443);
    Cluster::seedDataSource($workspace, 'Other port', 'api.example.com', 8443);
    Cluster::seedDataSource($other, 'Foreign', 'api.example.com', 443);

    expect(app(HostAllowlistDependents::class))->toBeInstanceOf(SqlHostAllowlistDependents::class);
    test()->getJson(HAL_URL."/{$unused}/dependents", HAL_HEADERS)->assertOk()->assertExactJson(['data' => []]);
    test()->getJson(HAL_URL."/{$id}/dependents", HAL_HEADERS)->assertOk()
        ->assertExactJson(['data' => [['id' => $billing, 'name' => 'billing API'], ['id' => $sales, 'name' => 'Sales API']]]);
});

it('lists the dependents of an entry through the port once an implementation is bound', function () {
    $workspace = Cluster::workspace('Acme');
    halAdmin($workspace);
    $id = Cluster::seedHostEntry($workspace, 'api.example.com', 443);

    app()->bind(HostAllowlistDependents::class, fn () => new class implements HostAllowlistDependents
    {
        public function dependentsOf(string $workspaceId, string $host, int $port): array
        {
            return $host === 'api.example.com' && $port === 443 ? [new DependentDataSource('11111111-1111-7111-8111-111111111111', 'Sales API')] : [];
        }
    });

    test()->getJson(HAL_URL."/{$id}/dependents", HAL_HEADERS)->assertOk()
        ->assertExactJson(['data' => [['id' => '11111111-1111-7111-8111-111111111111', 'name' => 'Sales API']]]);
});

it('lists entries with search, whitelisted sort, counts, the revision and the member name, and an empty Workspace has none', function () {
    $workspace = Cluster::workspace('Acme');
    halAdmin($workspace);

    test()->getJson(HAL_URL, HAL_HEADERS)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data', [])->assertJsonPath('meta.total', 0)->assertJsonPath('meta.revision', 0);

    halAdd('b.example.com:9000', 0)->assertCreated();
    halAdd('a.example.com', 1, 'http')->assertCreated();
    halAdd('c_wild.example.com'.'x', 2)->assertStatus(422);
    halAdd('c.example.org', 2)->assertCreated();

    $all = test()->getJson(HAL_URL, HAL_HEADERS)->assertOk();
    expect(array_column($all->json('data'), 'host'))->toBe(['a.example.com', 'b.example.com', 'c.example.org'])
        ->and($all->json('meta'))->toMatchArray(['revision' => 3, 'total' => 3, 'matched' => 3, 'sort' => 'host', 'direction' => 'asc'])
        ->and($all->json('data.0.added_by'))->toBe('Ada Admin');

    $byPort = test()->getJson(HAL_URL.'?sort=port&direction=desc', HAL_HEADERS)->json('data');
    expect(array_column($byPort, 'port'))->toBe([9000, 443, 80]);

    $search = test()->getJson(HAL_URL.'?q=EXAMPLE.org', HAL_HEADERS);
    expect(array_column($search->json('data'), 'host'))->toBe(['c.example.org'])
        ->and($search->json('meta'))->toMatchArray(['total' => 3, 'matched' => 1]);

    // A wildcard in the search is text, and an unknown sort falls back to the host.
    expect(test()->getJson(HAL_URL.'?q=%25', HAL_HEADERS)->json('data'))->toBe([])
        ->and(test()->getJson(HAL_URL.'?sort=password', HAL_HEADERS)->json('meta.sort'))->toBe('host');
});

it('never lists another Workspace\'s entries', function () {
    $acme = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    Cluster::seedHostEntry($other, 'theirs.example.com');
    Cluster::seedHostVersion($other, 7);
    halAdmin($acme);

    $response = test()->getJson(HAL_URL, HAL_HEADERS)->assertOk();

    expect($response->json('data'))->toBe([])->and($response->json('meta.revision'))->toBe(0)
        ->and($response->getContent())->not->toContain('theirs.example.com');
});

it('shows "added by" as the member\'s current name, resolved and never stored on the entry', function () {
    $workspace = Cluster::workspace('Acme');
    [$user] = halAdmin($workspace);
    halAdd('a.example.com', 0)->assertCreated();
    Cluster::superuser()->prepare('UPDATE users SET name = ? WHERE id = ?')->execute(['Ada Renamed', $user]);

    expect(test()->getJson(HAL_URL, HAL_HEADERS)->json('data.0.added_by'))->toBe('Ada Renamed')
        ->and(array_keys(halEntries()[0]))->not->toContain('added_by', 'name');
});

it('denies an Admin without settings.manage on every allowlist route with access.not_authorized, audits it and returns no entries', function () {
    $workspace = Cluster::workspace('Acme');
    halAdmin($workspace, ['users.manage']);
    $id = Cluster::seedHostEntry($workspace, 'secret.example.com');
    $calls = [
        fn () => test()->getJson(HAL_URL, HAL_HEADERS),
        fn () => halAdd('new.example.com', 0),
        fn () => halRemove($id, 0),
        fn () => test()->getJson(HAL_URL."/{$id}/dependents", HAL_HEADERS),
    ];

    foreach ($calls as $n => $call) {
        Cache::flush();
        $count = fn (): int => (int) Cluster::rows(Cluster::superuser(), "select count(*) as n from audit_events where action = 'access.admin.denied'")[0]['n'];
        $before = $count();
        $response = $call();

        $response->assertForbidden()->assertJsonPath('error.code', 'access.not_authorized');
        expect($count())->toBe($before + 1, "call {$n} audited")
            ->and($response->getContent())->not->toContain('secret.example.com');
    }

    expect(array_column(halEntries(), 'host'))->toBe(['secret.example.com'])->and(halAudits())->toBe([]);
});

it('denies the User area and a demoted Admin on the allowlist page and API', function () {
    $workspace = Cluster::workspace('Acme');
    [, $membership] = halAdmin($workspace);

    test()->withSession(['workspace_id' => $workspace, 'area' => 'user']);
    test()->getJson(HAL_URL, HAL_HEADERS)->assertForbidden();
    test()->get(route('admin.settings.host-allowlist'))->assertForbidden();

    test()->withSession(['workspace_id' => $workspace, 'area' => 'admin']);
    test()->get(route('admin.settings.host-allowlist'))->assertOk()->assertInertia(fn ($page) => $page->component('admin/HostAllowlist'));
    Cluster::superuser()->prepare("UPDATE workspace_memberships SET role = 'user' WHERE id = ?")->execute([$membership]);
    test()->getJson(HAL_URL, HAL_HEADERS)->assertForbidden();
});

it('renders System settings as a page that links to the Host allowlist', function () {
    $workspace = Cluster::workspace('Acme');
    halAdmin($workspace);

    test()->get(route('admin.settings.index'))->assertOk()->assertInertia(fn ($page) => $page->component('admin/SystemSettings'));
});

it('rolls the entry back when the audit cannot be written (audit shares the change\'s transaction)', function () {
    $workspace = Cluster::workspace('Acme');
    halAdmin($workspace);
    Cluster::superuser()->exec("ALTER TABLE audit_events ADD CONSTRAINT hal_block CHECK (action <> 'connector.host_allowlist_entry.created')");

    try {
        test()->withoutExceptionHandling();
        expect(fn () => halAdd('a.example.com', 0))->toThrow(QueryException::class);
    } finally {
        Cluster::superuser()->exec('ALTER TABLE audit_events DROP CONSTRAINT hal_block');
    }

    expect(halEntries())->toBe([])->and(halAudits())->toBe([])->and(halRevision($workspace))->toBeNull();
});

it('rolls the removal back when the audit cannot be written', function () {
    $workspace = Cluster::workspace('Acme');
    halAdmin($workspace);
    $id = halAdd('a.example.com', 0)->json('data.entry_id');
    Cluster::superuser()->exec("ALTER TABLE audit_events ADD CONSTRAINT hal_block CHECK (action <> 'connector.host_allowlist_entry.removed')");

    try {
        test()->withoutExceptionHandling();
        expect(fn () => halRemove($id, 1))->toThrow(QueryException::class);
    } finally {
        Cluster::superuser()->exec('ALTER TABLE audit_events DROP CONSTRAINT hal_block');
    }

    expect(halEntries())->toHaveCount(1)->and(halRevision($workspace))->toBe(1);
});

it('keeps both tables forced under row-level security, owned by migrator, with SELECT, INSERT and UPDATE for app only', function () {
    Cluster::migrateOnce();

    foreach (['host_allowlist_entries', 'host_allowlist_versions'] as $table) {
        $flags = Cluster::rows(Cluster::superuser(), 'select relrowsecurity, relforcerowsecurity, pg_get_userbyid(relowner) as owner from pg_class where oid = ?::regclass', [$table])[0];
        expect($flags)->toMatchArray(['relrowsecurity' => true, 'relforcerowsecurity' => true, 'owner' => 'migrator']);

        $privileges = Cluster::rows(Cluster::superuser(), "select privilege_type from information_schema.role_table_grants where table_name = ? and grantee = 'app' order by privilege_type", [$table]);
        expect(array_column($privileges, 'privilege_type'))->toBe(['INSERT', 'SELECT', 'UPDATE']);
    }

    $function = Cluster::rows(Cluster::superuser(), "select pg_get_userbyid(proowner) as owner, prosecdef, has_function_privilege('app', oid, 'EXECUTE') as app_can, has_function_privilege('public', oid, 'EXECUTE') as public_can, pronargs from pg_proc where proname = 'connector_remove_host_allowlist_entry'");
    expect($function)->toBe([['owner' => 'migrator', 'prosecdef' => true, 'app_can' => true, 'public_can' => false, 'pronargs' => 1]]);
});

it('refuses at the database a bad scheme, port, host, a duplicate host and port, and a cross-Workspace duplicate is fine', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    Cluster::seedHostEntry($a, 'dup.example.com', 443);

    expect(fn () => Cluster::seedHostEntry($a, 'dup.example.com', 443, 'http'))->toThrow(PDOException::class, 'host_allowlist_entries_workspace_id_host_port_unique')
        ->and(fn () => Cluster::seedHostEntry($a, 'x.example.com', 443, 'ftp'))->toThrow(PDOException::class, 'host_allowlist_entries_scheme_check')
        ->and(fn () => Cluster::seedHostEntry($a, 'x.example.com', 0))->toThrow(PDOException::class, 'host_allowlist_entries_port_check')
        ->and(fn () => Cluster::seedHostEntry($a, 'x.example.com', 65536))->toThrow(PDOException::class, 'host_allowlist_entries_port_check')
        ->and(fn () => Cluster::seedHostEntry($a, 'UPPER.example.com'))->toThrow(PDOException::class, 'host_allowlist_entries_host_check')
        ->and(fn () => Cluster::seedHostEntry($a, 'a.example.com/path'))->toThrow(PDOException::class, 'host_allowlist_entries_host_check');

    Cluster::seedHostEntry($b, 'dup.example.com', 443);
});

it('lets the removal function act for app only inside the transaction Workspace, and refuse an unset context', function () {
    $acme = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    $mine = Cluster::seedHostEntry($acme, 'mine.example.com');
    $theirs = Cluster::seedHostEntry($other, 'theirs.example.com');
    $app = Cluster::directApp();

    // Role app has no DELETE on the table itself.
    expect(fn () => Cluster::inWorkspace($app, $acme, fn ($pdo) => $pdo->exec('delete from host_allowlist_entries')))->toThrow(PDOException::class, 'permission denied')
        ->and(fn () => Cluster::inWorkspace($app, $acme, fn ($pdo) => $pdo->exec('delete from host_allowlist_versions')))->toThrow(PDOException::class, 'permission denied');

    // An unset context is refused.
    expect(fn () => $app->exec("select connector_remove_host_allowlist_entry('{$mine}')"))->toThrow(PDOException::class);

    // Another Workspace's ID matches nothing.
    $removed = Cluster::inWorkspace($app, $acme, fn ($pdo) => Cluster::rows($pdo, 'select connector_remove_host_allowlist_entry(?::uuid) as r', [$theirs])[0]['r']);
    expect($removed)->toBeFalse()->and(Cluster::rows(Cluster::superuser(), 'select id from host_allowlist_entries where id = ?', [$theirs]))->toHaveCount(1);

    $removed = Cluster::inWorkspace($app, $acme, fn ($pdo) => Cluster::rows($pdo, 'select connector_remove_host_allowlist_entry(?::uuid) as r', [$mine])[0]['r']);
    $again = Cluster::inWorkspace($app, $acme, fn ($pdo) => Cluster::rows($pdo, 'select connector_remove_host_allowlist_entry(?::uuid) as r', [$mine])[0]['r']);
    expect($removed)->toBeTrue()->and($again)->toBeFalse()
        ->and(Cluster::rows(Cluster::superuser(), 'select id from host_allowlist_entries where id = ?', [$mine]))->toBe([]);
});

it('returns zero rows from both tables with no context and for another Workspace', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    Cluster::seedHostEntry($a, 'a.example.com');
    Cluster::seedHostVersion($a, 3);

    foreach (['host_allowlist_entries', 'host_allowlist_versions'] as $table) {
        expect(Cluster::rows(Cluster::directApp(), "select * from {$table}"))->toBe([], "{$table}: no context")
            ->and(Cluster::inWorkspace(Cluster::directApp(), $b, fn ($pdo) => Cluster::rows($pdo, "select * from {$table}")))->toBe([], "{$table}: other Workspace");
    }
});

/** Seeds entries with set schemes and creation times (minutes after a fixed instant); returns the IDs by host. */
function halSeedOrdered(string $workspace, array $rows): void
{
    foreach ($rows as [$host, $scheme, $port, $minutes]) {
        $id = Cluster::seedHostEntry($workspace, $host, $port, $scheme);
        Cluster::superuser()->prepare("UPDATE host_allowlist_entries SET created_at = timestamp '2026-10-01 09:00:00' + (? * interval '1 minute') WHERE id = ?")->execute([$minutes, $id]);
    }
}

it('orders by each whitelisted column in both directions', function () {
    $workspace = Cluster::workspace('Acme');
    halAdmin($workspace);
    halSeedOrdered($workspace, [
        ['b.example.com', 'http', 8080, 3],
        ['c.example.com', 'https', 443, 1],
        ['a.example.com', 'https', 9000, 2],
    ]);
    $hosts = fn (string $query): array => array_column(test()->getJson(HAL_URL.$query, HAL_HEADERS)->assertOk()->json('data'), 'host');

    expect($hosts('?sort=host&direction=asc'))->toBe(['a.example.com', 'b.example.com', 'c.example.com'])
        ->and($hosts('?sort=host&direction=desc'))->toBe(['c.example.com', 'b.example.com', 'a.example.com'])
        ->and($hosts('?sort=scheme&direction=asc'))->toBe(['b.example.com', 'a.example.com', 'c.example.com'])
        ->and($hosts('?sort=scheme&direction=desc'))->toBe(['c.example.com', 'a.example.com', 'b.example.com'])
        ->and($hosts('?sort=port&direction=asc'))->toBe(['c.example.com', 'b.example.com', 'a.example.com'])
        ->and($hosts('?sort=port&direction=desc'))->toBe(['a.example.com', 'b.example.com', 'c.example.com'])
        ->and($hosts('?sort=added&direction=asc'))->toBe(['c.example.com', 'a.example.com', 'b.example.com'])
        ->and($hosts('?sort=added&direction=desc'))->toBe(['b.example.com', 'a.example.com', 'c.example.com']);
});

it('refuses a direction other than asc and desc with a 422', function (string $direction) {
    halAdmin(Cluster::workspace('Acme'));

    test()->getJson(HAL_URL.'?direction='.$direction, HAL_HEADERS)->assertStatus(422)->assertJsonPath('error.code', 'platform.validation_failed');
})->with(['DESC', 'descending', 'up']);

it('treats _ and ! in a search as plain text, not as wildcards or pattern breakers', function () {
    $workspace = Cluster::workspace('Acme');
    halAdmin($workspace);
    Cluster::seedHostEntry($workspace, 'abc.example.com');
    Cluster::seedHostEntry($workspace, 'a-c.example.com');
    $found = fn (string $q): array => array_column(test()->getJson(HAL_URL.'?q='.rawurlencode($q), HAL_HEADERS)->assertOk()->json('data'), 'host');

    expect($found('a_c'))->toBe([])
        ->and($found('a!c'))->toBe([])
        ->and($found('!'))->toBe([])
        ->and($found('a-c'))->toBe(['a-c.example.com']);
});

it('answers a stale revision with an already-allowed host as 409, not 422', function () {
    $workspace = Cluster::workspace('Acme');
    halAdmin($workspace);
    halAdd('api.example.com', 0)->assertCreated();

    halAdd('api.example.com', 0)->assertStatus(409)->assertJsonPath('error.code', ErrorCode::RevisionConflict->value);

    expect(halEntries())->toHaveCount(1);
});

it('refuses an empty scheme instead of repairing it to https', function () {
    $workspace = Cluster::workspace('Acme');
    halAdmin($workspace);

    $response = test()->postJson(HAL_URL, ['host' => 'a.example.com', 'scheme' => '', 'revision' => 0], HAL_HEADERS)->assertStatus(422);

    expect($response->json('reasons.scheme'))->toBe('invalid_scheme')
        ->and(halEntries())->toBe([]);
});
