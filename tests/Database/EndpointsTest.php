<?php

use App\Models\User;
use App\Modules\Connector\Application\ValidateEndpointInput;
use App\Modules\Connector\Contracts\DataSourceActor;
use App\Modules\Connector\Contracts\EndpointRevisionConflict;
use App\Modules\Connector\Contracts\Endpoints;
use App\Modules\Connector\Contracts\ErrorCode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 2.9 against the real PostgreSQL: an Admin with `data_sources.manage` registers Endpoints on a Data Source. An
// Endpoint is a row with a pointer to its current revision; every save is a new immutable revision written with the pointer
// and the audit event in one transaction. Methods, paths, parameters, headers and body templates are validated by the
// server; another Workspace's Data Source or Endpoint is a 404; the gate follows Story 1.19.
beforeEach(fn () => $this->withoutVite());

const EP_HEADERS = ['Referer' => 'http://localhost:8000'];

/** The Admin holding `data_sources.manage` (or the given permissions), signed in; returns [user ID, membership ID]. */
function epAdmin(string $workspaceId, array $permissions = ['data_sources.manage'], string $email = 'ada@example.test'): array
{
    $user = Cluster::user($email);
    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspaceId, $user, 'admin', 'active']);

    foreach ($permissions as $permission) {
        Cluster::superuser()->prepare('INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
            ->execute([(string) Str::uuid7(), $workspaceId, $membership, $permission]);
    }

    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspaceId, 'area' => 'admin']);

    return [$user, $membership];
}

function epUrl(string $dataSource, ?string $endpoint = null): string
{
    return "/api/v1/admin/data-sources/{$dataSource}/endpoints".($endpoint === null ? '' : "/{$endpoint}");
}

function epBody(array $overrides = []): array
{
    return $overrides + [
        'method' => 'GET',
        'path' => '/api/v2/finance/revenue',
        'params' => [],
        'headers' => [],
        'body_template' => null,
        'read_only_query' => false,
        'confirm_read_only' => false,
    ];
}

function epPostBody(array $overrides = []): array
{
    return epBody($overrides + ['method' => 'POST', 'read_only_query' => true, 'confirm_read_only' => true]);
}

function epCreate(string $dataSource, array $overrides = [])
{
    return test()->postJson(epUrl($dataSource), epBody($overrides), EP_HEADERS);
}

function epUpdate(string $dataSource, string $endpoint, int $revision, array $overrides = [])
{
    return test()->putJson(epUrl($dataSource, $endpoint), epBody($overrides) + ['revision' => $revision], EP_HEADERS);
}

function epRows(string $table = 'endpoints'): array
{
    return Cluster::rows(Cluster::superuser(), "select * from {$table} order by ".($table === 'endpoints' ? 'created_at, id' : 'endpoint_id, revision'));
}

function epAudits(?string $action = null): array
{
    return Cluster::rows(Cluster::superuser(), "select * from audit_events where action like 'connector.endpoint.%' and (?::text is null or action = ?) order by occurred_at, id", [$action, $action]);
}

it('creates a GET Endpoint: revision 1, the pointer set, UUIDv7 keys, the path parsed into an AST, audited without the path in the clear', function () {
    $workspace = Cluster::workspace('Acme');
    [, $editor] = epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');

    $response = epCreate($source, [
        'path' => '/api/v2/customers/{id}/revenue',
        'params' => [['name' => 'id', 'binding' => 'fixed', 'value' => 'c-42'], ['name' => 'from', 'binding' => 'date_range_from']],
        'headers' => [['name' => 'X-Team', 'binding' => 'fixed', 'value' => 'finance']],
    ])->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
    $id = $response->json('data.endpoint_id');

    expect($response->json('data'))->toMatchArray([
        'data_source_id' => $source, 'method' => 'GET', 'path' => '/api/v2/customers/{id}/revenue', 'revision' => 1, 'read_only_query' => false, 'body_template' => null,
        'params' => [['name' => 'id', 'binding' => 'fixed', 'value' => 'c-42', 'kind' => 'path'], ['name' => 'from', 'binding' => 'date_range_from', 'value' => null, 'kind' => 'query']],
        'headers' => [['name' => 'X-Team', 'binding' => 'fixed', 'value' => 'finance']],
        'path_ast' => [
            ['type' => 'literal', 'value' => 'api'], ['type' => 'literal', 'value' => 'v2'], ['type' => 'literal', 'value' => 'customers'],
            ['type' => 'param', 'name' => 'id'], ['type' => 'literal', 'value' => 'revenue'],
        ],
    ])
        ->and(Str::isUuid($id))->toBeTrue()
        ->and(substr($id, 14, 1))->toBe('7');

    $endpoints = epRows();
    $revisions = epRows('endpoint_revisions');
    expect($endpoints)->toHaveCount(1)
        ->and($revisions)->toHaveCount(1)
        ->and($endpoints[0])->toMatchArray(['id' => $id, 'workspace_id' => $workspace, 'data_source_id' => $source, 'revision' => 1, 'created_by_membership_id' => $editor, 'current_revision_id' => $revisions[0]['id']])
        ->and($revisions[0])->toMatchArray(['workspace_id' => $workspace, 'endpoint_id' => $id, 'revision' => 1, 'method' => 'GET', 'path_template' => '/api/v2/customers/{id}/revenue', 'read_only_query' => false, 'body_template' => null, 'created_by_membership_id' => $editor])
        ->and(substr($revisions[0]['id'], 14, 1))->toBe('7')
        ->and(json_decode($revisions[0]['path_ast'], true))->toHaveCount(5);

    $audit = epAudits();
    $after = json_decode($audit[0]['after_state'], true);
    expect($audit)->toHaveCount(1)
        ->and($audit[0]['action'])->toBe('connector.endpoint.created')
        ->and($audit[0]['actor'])->toBe($editor)
        ->and($audit[0]['subject'])->toBe('endpoint:'.$id)
        ->and($audit[0]['before_state'])->toBeNull()
        ->and($after)->toMatchArray(['endpoint_id' => $id, 'data_source_id' => $source, 'method' => 'get', 'revision' => 1, 'param_count' => 2, 'header_count' => 1, 'read_only_query' => 'false'])
        ->and($after['path'])->toMatch('/^hmac-sha256:[0-9a-f]{64}$/')
        ->and($after['bindings'])->toMatch('/^hmac-sha256:[0-9a-f]{64}$/')
        ->and($audit[0]['after_state'])->not->toContain('customers')->not->toContain('c-42')->not->toContain('finance')->not->toContain('X-Team')
        ->and(epAudits('connector.endpoint.read_only_flag_set'))->toBe([]);
});

it('revises an Endpoint: a new immutable revision, the pointer moves, older rows unchanged, audited, the Data Source revision untouched', function () {
    $workspace = Cluster::workspace('Acme');
    [, $editor] = epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $id = epCreate($source)->assertCreated()->json('data.endpoint_id');
    $first = epRows('endpoint_revisions');
    $sourceBefore = Cluster::rows(Cluster::superuser(), 'select revision, updated_at from data_sources where id = ?', [$source])[0];

    $response = epUpdate($source, $id, 1, ['path' => '/api/v3/finance/revenue', 'params' => [['name' => 'q', 'binding' => 'fixed', 'value' => 'x']]])->assertOk();

    expect($response->json('data'))->toMatchArray(['endpoint_id' => $id, 'revision' => 2, 'path' => '/api/v3/finance/revenue']);

    $revisions = epRows('endpoint_revisions');
    $endpoint = epRows()[0];
    expect($revisions)->toHaveCount(2)
        ->and($revisions[0])->toBe($first[0])
        ->and($revisions[1])->toMatchArray(['endpoint_id' => $id, 'revision' => 2, 'path_template' => '/api/v3/finance/revenue', 'created_by_membership_id' => $editor])
        ->and($endpoint)->toMatchArray(['revision' => 2, 'current_revision_id' => $revisions[1]['id']])
        ->and(Cluster::rows(Cluster::superuser(), 'select revision, updated_at from data_sources where id = ?', [$source])[0])->toBe($sourceBefore);

    $audit = epAudits('connector.endpoint.revised');
    expect($audit)->toHaveCount(1)
        ->and($audit[0]['subject'])->toBe('endpoint:'.$id)
        ->and(json_decode($audit[0]['after_state'], true))->toMatchArray(['revision' => 2, 'param_count' => 1])
        ->and(json_decode($audit[0]['before_state'], true))->toMatchArray(['revision' => 1, 'param_count' => 0])
        ->and($audit[0]['after_state'])->not->toContain('v3');

    // Saving the same values again is still a new revision.
    epUpdate($source, $id, 2, ['path' => '/api/v3/finance/revenue', 'params' => [['name' => 'q', 'binding' => 'fixed', 'value' => 'x']]])->assertOk()->assertJsonPath('data.revision', 3);
    expect(epRows('endpoint_revisions'))->toHaveCount(3);
});

it('answers a stale revision with a 409 and the current state, and writes nothing', function () {
    $workspace = Cluster::workspace('Acme');
    epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $id = epCreate($source)->assertCreated()->json('data.endpoint_id');
    epUpdate($source, $id, 1, ['path' => '/changed'])->assertOk();

    $response = epUpdate($source, $id, 1, ['path' => '/mine'])->assertStatus(409);

    expect($response->json('error.code'))->toBe(ErrorCode::RevisionConflict->value)
        ->and($response->json('current.data'))->toMatchArray(['endpoint_id' => $id, 'revision' => 2, 'path' => '/changed'])
        ->and(epRows('endpoint_revisions'))->toHaveCount(2)
        ->and(epAudits('connector.endpoint.revised'))->toHaveCount(1);
});

it('requires the revision on an edit and treats a missing one as a 422', function () {
    $workspace = Cluster::workspace('Acme');
    epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $id = epCreate($source)->assertCreated()->json('data.endpoint_id');

    test()->putJson(epUrl($source, $id), epBody(), EP_HEADERS)->assertStatus(422)->assertJsonValidationErrors('revision');
});

it('keeps revisions immutable: any UPDATE or DELETE fails, for every role', function () {
    $workspace = Cluster::workspace('Acme');
    $revision = Cluster::seedEndpointRevision($workspace);

    expect(fn () => Cluster::superuser()->prepare("UPDATE endpoint_revisions SET path_template = '/x' WHERE id = ?")->execute([$revision]))->toThrow(PDOException::class, 'immutable')
        ->and(fn () => Cluster::superuser()->prepare('DELETE FROM endpoint_revisions WHERE id = ?')->execute([$revision]))->toThrow(PDOException::class, 'immutable')
        ->and(fn () => Cluster::inWorkspace(Cluster::directApp(), $workspace, fn ($pdo) => $pdo->exec("UPDATE endpoint_revisions SET path_template = '/x'")))->toThrow(PDOException::class, 'permission denied')
        ->and(fn () => Cluster::inWorkspace(Cluster::directApp(), $workspace, fn ($pdo) => $pdo->exec('DELETE FROM endpoint_revisions')))->toThrow(PDOException::class, 'permission denied')
        ->and(Cluster::rows(Cluster::superuser(), 'select path_template from endpoint_revisions where id = ?', [$revision])[0]['path_template'])->toBe('/api/v2/finance/revenue');
});

it('keeps both tables forced under row-level security, owned by migrator, with the agreed privileges for app', function () {
    Cluster::migrateOnce();

    foreach (['endpoints' => ['INSERT', 'SELECT', 'UPDATE'], 'endpoint_revisions' => ['INSERT', 'SELECT']] as $table => $expected) {
        $flags = Cluster::rows(Cluster::superuser(), "select relrowsecurity, relforcerowsecurity, pg_get_userbyid(relowner) as owner from pg_class where oid = '{$table}'::regclass")[0];
        $privileges = Cluster::rows(Cluster::superuser(), 'select privilege_type from information_schema.role_table_grants where table_name = ? and grantee = ? order by privilege_type', [$table, 'app']);

        expect($flags)->toMatchArray(['relrowsecurity' => true, 'relforcerowsecurity' => true, 'owner' => 'migrator'])
            ->and(array_column($privileges, 'privilege_type'))->toBe($expected);
    }

    $workspace = Cluster::workspace('Acme');
    Cluster::seedEndpoint($workspace);
    expect(fn () => Cluster::inWorkspace(Cluster::directApp(), $workspace, fn ($pdo) => $pdo->exec('delete from endpoints')))->toThrow(PDOException::class, 'permission denied');
});

it('returns zero rows with no context and for another Workspace', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    Cluster::seedEndpoint($a);

    foreach (['endpoints', 'endpoint_revisions'] as $table) {
        expect(Cluster::rows(Cluster::directApp(), "select * from {$table}"))->toBe([])
            ->and(Cluster::inWorkspace(Cluster::directApp(), $b, fn ($pdo) => Cluster::rows($pdo, "select * from {$table}")))->toBe([]);
    }
});

it('ties every row to its own Workspace at the database', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $sourceA = Cluster::seedDataSource($a, 'Mine');
    $endpointA = Cluster::seedEndpoint($a, $sourceA);

    // An Endpoint cannot sit on another Workspace's Data Source, nor a revision under another Workspace's Endpoint.
    expect(fn () => Cluster::seedEndpoint($b, $sourceA))->toThrow(PDOException::class, 'endpoints_data_source_workspace_fk')
        ->and(fn () => Cluster::superuser()->prepare("INSERT INTO endpoint_revisions (id, workspace_id, endpoint_id, revision, method, path_template, path_ast, created_at, created_by_membership_id) VALUES (?, ?, ?, 2, 'GET', '/x', '[]', now(), ?)")
            ->execute([(string) Str::uuid7(), $b, $endpointA, (string) Str::uuid7()]))->toThrow(PDOException::class, 'endpoint_revisions_endpoint_workspace_fk');
});

it('refuses at the database a bad method, a POST that is not read-only, a body on a GET, a duplicate revision number and a pointer to nowhere', function () {
    $workspace = Cluster::workspace('Acme');
    $endpoint = Cluster::seedEndpoint($workspace);
    $insert = fn (string $columns, array $values) => fn () => Cluster::superuser()->prepare("INSERT INTO endpoint_revisions (id, workspace_id, endpoint_id, created_at, created_by_membership_id, path_ast, {$columns}) VALUES (?, ?, ?, now(), ?, '[]', ".implode(', ', array_fill(0, count($values), '?')).')')
        ->execute([(string) Str::uuid7(), $workspace, $endpoint, (string) Str::uuid7(), ...$values]);

    expect($insert('revision, method, path_template', [2, 'PUT', '/x']))->toThrow(PDOException::class, 'endpoint_revisions_method_check')
        ->and($insert('revision, method, path_template', [2, 'DELETE', '/x']))->toThrow(PDOException::class, 'endpoint_revisions_method_check')
        ->and($insert('revision, method, path_template, read_only_query', [2, 'POST', '/x', 'false']))->toThrow(PDOException::class, 'endpoint_revisions_post_readonly_check')
        ->and($insert('revision, method, path_template, body_template', [2, 'GET', '/x', '{}']))->toThrow(PDOException::class, 'endpoint_revisions_get_no_body_check')
        ->and($insert('revision, method, path_template', [1, 'GET', '/x']))->toThrow(PDOException::class, 'endpoint_revisions_endpoint_id_revision_unique')
        ->and($insert('revision, method, path_template', [2, 'GET', 'https://other.host/x']))->toThrow(PDOException::class, 'endpoint_revisions_path_check')
        ->and($insert('revision, method, path_template', [2, 'GET', '//host/x']))->toThrow(PDOException::class, 'endpoint_revisions_path_check')
        ->and($insert('revision, method, path_template', [0, 'GET', '/x']))->toThrow(PDOException::class, 'endpoint_revisions_revision_check')
        ->and(fn () => Cluster::superuser()->prepare('UPDATE endpoints SET current_revision_id = ? WHERE id = ?')->execute([(string) Str::uuid7(), $endpoint]))->toThrow(PDOException::class, 'endpoints_current_revision_fk')
        ->and(epRows('endpoint_revisions'))->toHaveCount(1);
});

it('lists the Endpoints of a Data Source with a search and counts, and shows one', function () {
    $workspace = Cluster::workspace('Acme');
    epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $other = Cluster::seedDataSource($workspace, 'Other API');
    $revenue = epCreate($source, ['path' => '/api/v2/finance/revenue'])->assertCreated()->json('data.endpoint_id');
    epCreate($source, ['path' => '/api/v2/hr/people'])->assertCreated();
    test()->postJson(epUrl($source), epPostBody(['path' => '/api/v2/search']), EP_HEADERS)->assertCreated();
    epCreate($other, ['path' => '/other/revenue'])->assertCreated();

    $list = test()->getJson(epUrl($source), EP_HEADERS)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect(array_column($list->json('data'), 'path'))->toBe(['/api/v2/finance/revenue', '/api/v2/hr/people', '/api/v2/search'])
        ->and($list->json('meta'))->toBe(['total' => 3, 'matched' => 3]);

    $found = test()->getJson(epUrl($source).'?q=FINANCE', EP_HEADERS)->assertOk();
    expect(array_column($found->json('data'), 'endpoint_id'))->toBe([$revenue])->and($found->json('meta'))->toBe(['total' => 3, 'matched' => 1]);

    $byMethod = test()->getJson(epUrl($source).'?q=post', EP_HEADERS)->assertOk();
    expect(array_column($byMethod->json('data'), 'path'))->toBe(['/api/v2/search']);

    $wild = test()->getJson(epUrl($source).'?q=%25', EP_HEADERS)->assertOk();
    expect($wild->json('meta'))->toBe(['total' => 3, 'matched' => 0]);

    test()->getJson(epUrl($source).'?q=nothing-here', EP_HEADERS)->assertOk()->assertJsonPath('meta', ['total' => 3, 'matched' => 0])->assertJsonPath('data', []);

    test()->getJson(epUrl($source, $revenue), EP_HEADERS)->assertOk()->assertJsonPath('data.endpoint_id', $revenue)->assertJsonPath('data.path', '/api/v2/finance/revenue');
});

it('renders the Endpoints tab for the Data Source and the add and edit form on the same page', function () {
    $workspace = Cluster::workspace('Acme');
    epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');

    test()->get(route('admin.data-sources.endpoints', $source))->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/DataSourceEndpoints')->where('dataSourceId', $source));
    test()->get('/admin/data-sources/not-a-uuid/endpoints')->assertNotFound();
});

it('refuses every method but GET and POST with a 422 method-not-allowed and writes nothing', function (mixed $method) {
    $workspace = Cluster::workspace('Acme');
    epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');

    $response = epCreate($source, ['method' => $method, 'read_only_query' => true, 'confirm_read_only' => true])->assertStatus(422);

    expect($response->json('reasons.method'))->toBe('method-not-allowed')
        ->and($response->json('errors'))->toHaveKey('method')
        ->and(epRows())->toBe([])->and(epAudits())->toBe([]);
})->with(['PUT', 'PATCH', 'DELETE', 'HEAD', 'get', 'post', '', null]);

it('also refuses another method on an edit and leaves the Endpoint as it was', function () {
    $workspace = Cluster::workspace('Acme');
    epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $id = epCreate($source)->assertCreated()->json('data.endpoint_id');

    epUpdate($source, $id, 1, ['method' => 'DELETE'])->assertStatus(422)->assertJsonPath('reasons.method', 'method-not-allowed');

    expect(epRows()[0]['revision'])->toBe(1)->and(epRows('endpoint_revisions'))->toHaveCount(1);
});

it('accepts a POST only as a confirmed read-only query, and audits the flag when a revision first sets it', function () {
    $workspace = Cluster::workspace('Acme');
    [, $editor] = epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');

    test()->postJson(epUrl($source), epBody(['method' => 'POST']), EP_HEADERS)->assertStatus(422)->assertJsonPath('reasons.read_only_query', 'post-readonly-required');
    test()->postJson(epUrl($source), epBody(['method' => 'POST', 'read_only_query' => true]), EP_HEADERS)->assertStatus(422)->assertJsonPath('reasons.confirm_read_only', 'post-confirmation-required');
    test()->postJson(epUrl($source), epBody(['method' => 'POST', 'read_only_query' => true, 'confirm_read_only' => false]), EP_HEADERS)->assertStatus(422);
    expect(epRows())->toBe([])->and(epAudits())->toBe([]);

    $id = test()->postJson(epUrl($source), epPostBody(), EP_HEADERS)->assertCreated()->assertJsonPath('data.method', 'POST')->assertJsonPath('data.read_only_query', true)->json('data.endpoint_id');

    $flag = epAudits('connector.endpoint.read_only_flag_set');
    expect(epRows('endpoint_revisions')[0])->toMatchArray(['method' => 'POST', 'read_only_query' => true])
        ->and($flag)->toHaveCount(1)
        ->and($flag[0]['actor'])->toBe($editor)
        ->and($flag[0]['subject'])->toBe('endpoint:'.$id)
        ->and(json_decode($flag[0]['after_state'], true))->toMatchArray(['endpoint_id' => $id, 'method' => 'post', 'revision' => 1, 'read_only_query' => 'true'])
        ->and(epAudits('connector.endpoint.created'))->toHaveCount(1);

    // Revising a POST that already carried the flag does not audit it again.
    test()->putJson(epUrl($source, $id), epPostBody(['path' => '/other']) + ['revision' => 1], EP_HEADERS)->assertOk();
    expect(epAudits('connector.endpoint.read_only_flag_set'))->toHaveCount(1)->and(epAudits('connector.endpoint.revised'))->toHaveCount(1);
});

it('audits the flag in the revision that first sets it, in the same transaction as the revision', function () {
    $workspace = Cluster::workspace('Acme');
    epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $id = epCreate($source)->assertCreated()->json('data.endpoint_id');
    expect(epAudits('connector.endpoint.read_only_flag_set'))->toBe([]);

    test()->putJson(epUrl($source, $id), epPostBody() + ['revision' => 1], EP_HEADERS)->assertOk()->assertJsonPath('data.revision', 2);

    $flag = epAudits('connector.endpoint.read_only_flag_set');
    expect($flag)->toHaveCount(1)->and(json_decode($flag[0]['after_state'], true))->toMatchArray(['revision' => 2]);

    // Back to a GET stores the flag as false; a later POST sets it again.
    test()->putJson(epUrl($source, $id), epBody(['read_only_query' => true]) + ['revision' => 2], EP_HEADERS)->assertOk()->assertJsonPath('data.read_only_query', false);
    test()->putJson(epUrl($source, $id), epPostBody() + ['revision' => 3], EP_HEADERS)->assertOk();
    expect(epAudits('connector.endpoint.read_only_flag_set'))->toHaveCount(2);
});

it('refuses a path that is not under the base URL, with its reason, and writes nothing', function (string $path, string $reason) {
    $workspace = Cluster::workspace('Acme');
    epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');

    $response = epCreate($source, ['path' => $path])->assertStatus(422);

    expect($response->json('reasons.path'))->toBe($reason)->and(epRows())->toBe([])->and(epAudits())->toBe([]);
})->with([
    'absolute' => ['https://other.host/x', 'path-absolute'],
    'protocol relative' => ['//host/x', 'path-protocol-relative'],
    'no leading slash' => ['api/v2/x', 'path-leading-slash'],
    'a dot dot segment' => ['/a/../b', 'path-dot-segment'],
    'an encoded dot dot' => ['/a/%2e%2e/b', 'path-dot-segment'],
    'an encoded slash' => ['/a%2Fb', 'path-encoded-separator'],
    'a query string' => ['/a?b=1', 'path-query'],
    'a backslash' => ['/a\\b', 'path-backslash'],
]);

it('names a placeholder with no declared parameter, and a fixed value that breaks the segment rule', function () {
    $workspace = Cluster::workspace('Acme');
    epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');

    $missing = epCreate($source, ['path' => '/customers/{id}'])->assertStatus(422);
    expect($missing->json('reasons.path'))->toBe('path-param-missing')->and($missing->json('errors.path.0'))->toContain('id');

    foreach (['a/b', '.', '..'] as $value) {
        $bad = epCreate($source, ['path' => '/customers/{id}', 'params' => [['name' => 'id', 'binding' => 'fixed', 'value' => $value]]])->assertStatus(422);
        expect($bad->json('reasons'))->toBe(['params.0.value' => 'param-value-invalid'])->and($bad->json('errors')['params.0.value'][0])->toContain('id');
    }

    epCreate($source, ['path' => '/customers/{id}', 'params' => [['name' => 'id', 'binding' => 'date_range_from']]])->assertCreated();
    expect(epRows())->toHaveCount(1);
});

it('refuses a bound header value with CR, LF or non-visible ASCII, or a reserved name, and stores nothing', function (array $header, string $field, string $reason) {
    $workspace = Cluster::workspace('Acme');
    epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');

    $response = epCreate($source, ['headers' => [$header]])->assertStatus(422);

    expect($response->json('reasons'))->toBe([$field => $reason])
        ->and(epRows())->toBe([])->and(epRows('endpoint_revisions'))->toBe([])->and(epAudits())->toBe([]);
})->with([
    'a line feed' => [['name' => 'X-A', 'binding' => 'fixed', 'value' => "a\nX-Evil: 1"], 'headers.0.value', 'header-value-invalid'],
    'a carriage return' => [['name' => 'X-A', 'binding' => 'fixed', 'value' => "a\rb"], 'headers.0.value', 'header-value-invalid'],
    'a tab' => [['name' => 'X-A', 'binding' => 'fixed', 'value' => "a\tb"], 'headers.0.value', 'header-value-invalid'],
    'non ascii' => [['name' => 'X-A', 'binding' => 'fixed', 'value' => "caf\u{e9}"], 'headers.0.value', 'header-value-invalid'],
    'Host' => [['name' => 'Host', 'binding' => 'fixed', 'value' => 'x'], 'headers.0.name', 'header-name-reserved'],
    'Authorization' => [['name' => 'authorization', 'binding' => 'fixed', 'value' => 'x'], 'headers.0.name', 'header-name-reserved'],
]);

it('offers the fixed, date range and period bindings and refuses a binding that is not one (user context is Story 2.13)', function () {
    $workspace = Cluster::workspace('Acme');
    epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');

    foreach (['user_context', 'user.id', 'magic'] as $binding) {
        epCreate($source, ['params' => [['name' => 'u', 'binding' => $binding]]])->assertStatus(422)->assertJsonPath('reasons', ['params.0.binding' => 'binding-invalid']);
        epCreate($source, ['headers' => [['name' => 'X-U', 'binding' => $binding]]])->assertStatus(422)->assertJsonPath('reasons', ['headers.0.binding' => 'binding-invalid']);
    }

    $ok = epCreate($source, ['params' => [
        ['name' => 'a', 'binding' => 'fixed', 'value' => 'x'], ['name' => 'b', 'binding' => 'date_range_from'], ['name' => 'c', 'binding' => 'date_range_to'],
        ['name' => 'd', 'binding' => 'period_start'], ['name' => 'e', 'binding' => 'period_end'],
    ]])->assertCreated();

    expect(array_column($ok->json('data.params'), 'value', 'name'))->toBe(['a' => 'x', 'b' => null, 'c' => null, 'd' => null, 'e' => null]);
});

it('stores a POST body template with typed whole-value parameters and gives it back with every number as written', function () {
    $workspace = Cluster::workspace('Acme');
    epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');

    $response = test()->postJson(epUrl($source), epPostBody([
        'params' => [['name' => 'from', 'binding' => 'date_range_from'], ['name' => 'to', 'binding' => 'date_range_to'], ['name' => 'limit', 'binding' => 'fixed', 'value' => '10']],
        'body_template' => ' { "range" : {"from": {"$param": "from"}, "to": {"$param":"to"}}, "limit": {"$param":"limit"}, "rate": 1.10, "big": 12345678901234567890.12345678901234567890, "ok": true } ',
    ]), EP_HEADERS)->assertCreated();

    $canonical = '{"big":12345678901234567890.12345678901234567890,"limit":{"$param":"limit"},"ok":true,"range":{"from":{"$param":"from"},"to":{"$param":"to"}},"rate":1.10}';
    expect($response->json('data.body_template'))->toBe($canonical)
        ->and(array_column($response->json('data.params'), 'kind', 'name'))->toBe(['from' => 'body', 'to' => 'body', 'limit' => 'body']);

    $id = $response->json('data.endpoint_id');
    test()->getJson(epUrl($source, $id), EP_HEADERS)->assertOk()->assertJsonPath('data.body_template', $canonical);
});

it('refuses a body template that interpolates, uses a key, names an unknown parameter or sits on a GET', function (array $overrides, string $reason) {
    $workspace = Cluster::workspace('Acme');
    epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');

    $response = test()->postJson(epUrl($source), epPostBody($overrides + ['params' => [['name' => 'from', 'binding' => 'date_range_from']]]), EP_HEADERS)->assertStatus(422);

    expect($response->json('reasons.body_template'))->toBe($reason)->and(epRows())->toBe([]);
})->with([
    'interpolation' => [['body_template' => '{"q":"from {from}"}'], 'body-template-interpolation'],
    'a parameter as a key' => [['body_template' => '{"{from}":1}'], 'body-template-interpolation'],
    'an unknown parameter' => [['body_template' => '{"q":{"$param":"nope"}}'], 'body-template-param-unknown'],
    'not JSON' => [['body_template' => '{"q":'], 'body-template-invalid'],
    'a GET with a body' => [['method' => 'GET', 'read_only_query' => false, 'confirm_read_only' => false, 'body_template' => '{"q":1}'], 'body-template-not-allowed'],
]);

it('reports every field error at once with focusable field names', function () {
    $workspace = Cluster::workspace('Acme');
    epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');

    $response = test()->postJson(epUrl($source), ['method' => 'PUT', 'path' => 'x', 'params' => [['name' => 'a b', 'binding' => 'fixed', 'value' => 'x']], 'headers' => [['name' => 'Host', 'binding' => 'fixed', 'value' => 'x']]], EP_HEADERS)->assertStatus(422);

    expect(array_keys($response->json('errors')))->toBe(['method', 'path', 'params.0.name', 'headers.0.name']);
});

it('answers 404 for another Workspace\'s Data Source or Endpoint, and for an Endpoint asked through the wrong Data Source', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    epAdmin($a);
    $sourceB = Cluster::seedDataSource($b, 'Theirs');
    $endpointB = Cluster::seedEndpoint($b, $sourceB);
    $sourceA = Cluster::seedDataSource($a, 'Mine');
    $otherA = Cluster::seedDataSource($a, 'Also mine');
    $endpointA = Cluster::seedEndpoint($a, $sourceA);

    test()->getJson(epUrl($sourceB), EP_HEADERS)->assertNotFound();
    test()->getJson(epUrl($sourceB, $endpointB), EP_HEADERS)->assertNotFound();
    test()->postJson(epUrl($sourceB), epBody(), EP_HEADERS)->assertNotFound();
    test()->putJson(epUrl($sourceB, $endpointB), epBody() + ['revision' => 1], EP_HEADERS)->assertNotFound();
    test()->getJson(epUrl($sourceA, $endpointB), EP_HEADERS)->assertNotFound();
    test()->putJson(epUrl($sourceA, $endpointB), epBody() + ['revision' => 1], EP_HEADERS)->assertNotFound();
    test()->getJson(epUrl($otherA, $endpointA), EP_HEADERS)->assertNotFound();
    test()->putJson(epUrl($otherA, $endpointA), epBody() + ['revision' => 1], EP_HEADERS)->assertNotFound();
    test()->getJson(epUrl($sourceA, 'not-a-uuid'), EP_HEADERS)->assertNotFound();
    test()->getJson(epUrl('not-a-uuid'), EP_HEADERS)->assertNotFound();
    test()->getJson(epUrl((string) Str::uuid7()), EP_HEADERS)->assertNotFound();

    expect(Cluster::rows(Cluster::superuser(), 'select count(*) as n from endpoint_revisions')[0]['n'])->toBe(2)
        ->and(epAudits())->toBe([]);
});

it('denies every write without data_sources.manage with a 403 and a security event, and reads nothing', function () {
    $workspace = Cluster::workspace('Acme');
    epAdmin($workspace, ['users.manage']);
    $source = Cluster::seedDataSource($workspace, 'Secret source');
    $id = Cluster::seedEndpoint($workspace, $source, '/secret/path');

    $calls = [
        fn () => test()->getJson(epUrl($source), EP_HEADERS),
        fn () => test()->getJson(epUrl($source, $id), EP_HEADERS),
        fn () => epCreate($source),
        fn () => epUpdate($source, $id, 1),
        fn () => test()->get(route('admin.data-sources.endpoints', $source)),
    ];

    foreach ($calls as $n => $call) {
        Cache::flush();
        $count = fn (): int => (int) Cluster::rows(Cluster::superuser(), "select count(*) as n from audit_events where action = 'access.admin.denied'")[0]['n'];
        $before = $count();
        $response = $call();

        $response->assertForbidden();
        expect($count())->toBe($before + 1, "call {$n} audited")
            ->and($response->getContent())->not->toContain('/secret/path')->not->toContain('Secret source');
    }

    expect(epRows())->toHaveCount(1)->and(epRows('endpoint_revisions'))->toHaveCount(1)->and(epAudits())->toBe([]);
});

it('denies the User area and a demoted Admin', function () {
    $workspace = Cluster::workspace('Acme');
    [, $membership] = epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');

    test()->withSession(['workspace_id' => $workspace, 'area' => 'user']);
    test()->getJson(epUrl($source), EP_HEADERS)->assertForbidden();
    epCreate($source)->assertForbidden();

    test()->withSession(['workspace_id' => $workspace, 'area' => 'admin']);
    Cluster::superuser()->prepare("UPDATE workspace_memberships SET role = 'user' WHERE id = ?")->execute([$membership]);
    test()->getJson(epUrl($source), EP_HEADERS)->assertForbidden();
    expect(epRows())->toBe([]);
});

it('throttles writes like the Data Source API', function () {
    $workspace = Cluster::workspace('Acme');
    epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');

    foreach (range(1, 30) as $_) {
        epCreate($source, ['method' => 'PUT'])->assertStatus(422);
    }

    epCreate($source, ['method' => 'PUT'])->assertStatus(429);

    // Endpoint saves have a bucket of their own: the Data Source update is not throttled by them.
    test()->putJson('/api/v1/admin/data-sources/'.$source, [], EP_HEADERS)->assertStatus(422);
});

it('writes the Endpoint, the revision, the pointer and the audit event together: a failure leaves none of them', function () {
    $workspace = Cluster::workspace('Acme');
    epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    Cluster::superuser()->exec("CREATE OR REPLACE FUNCTION ep_fail_audit() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.action LIKE 'connector.endpoint.%' THEN RAISE EXCEPTION 'audit failed'; END IF; RETURN NEW; END \$\$");
    Cluster::superuser()->exec('CREATE TRIGGER ep_fail_audit BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION ep_fail_audit()');

    try {
        $this->withoutExceptionHandling();
        expect(fn () => epCreate($source))->toThrow(QueryException::class);
    } finally {
        Cluster::superuser()->exec('DROP TRIGGER ep_fail_audit ON audit_events');
        Cluster::superuser()->exec('DROP FUNCTION ep_fail_audit()');
    }

    expect(epRows())->toBe([])->and(epRows('endpoint_revisions'))->toBe([]);
});

it('rolls the Endpoints migration back and migrates forward again', function () {
    $workspace = Cluster::workspace('Acme');
    Cluster::seedEndpoint($workspace);
    $tables = fn (): int => (int) Cluster::rows(Cluster::superuser(), "select count(*) as n from information_schema.tables where table_name in ('endpoints', 'endpoint_revisions')")[0]['n'];

    try {
        // Five steps: the newest migrations are Story 2.14's (scheduled fetch), Story 2.13's (user context), Story 2.12's (user attributes) and Story 2.11's (pagination), then this one.
        expect(Artisan::call('migrate:rollback', ['--database' => 'migrator', '--step' => 5, '--force' => true]))->toBe(0)
            ->and($tables())->toBe(0);
    } finally {
        Artisan::call('migrate', ['--database' => 'migrator', '--force' => true]);
    }

    expect($tables())->toBe(2)
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from endpoints')[0]['n'])->toBe(0);
});

it('refuses a pointer to another Endpoint\'s revision or to a revision that is not the current number', function () {
    $workspace = Cluster::workspace('Acme');
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $a = Cluster::seedEndpoint($workspace, $source);
    $b = Cluster::seedEndpoint($workspace, $source, '/other');
    $revisionB = Cluster::rows(Cluster::superuser(), 'select id from endpoint_revisions where endpoint_id = ?', [$b])[0]['id'];
    $revisionA2 = (string) Str::uuid7();
    Cluster::superuser()->prepare("INSERT INTO endpoint_revisions (id, workspace_id, endpoint_id, revision, method, path_template, path_ast, created_at, created_by_membership_id) VALUES (?, ?, ?, 2, 'GET', '/x', '[]', now(), ?)")
        ->execute([$revisionA2, $workspace, $a, (string) Str::uuid7()]);
    $update = fn (string $sql, array $values) => fn () => Cluster::superuser()->prepare($sql)->execute($values);

    expect($update('UPDATE endpoints SET current_revision_id = ? WHERE id = ?', [$revisionB, $a]))->toThrow(PDOException::class, 'endpoints_current_revision_fk')
        ->and($update('UPDATE endpoints SET current_revision_id = ?, revision = 2 WHERE id = ?', [$revisionB, $a]))->toThrow(PDOException::class, 'endpoints_current_revision_fk')
        ->and($update('UPDATE endpoints SET current_revision_id = ? WHERE id = ?', [$revisionA2, $a]))->toThrow(PDOException::class, 'endpoints_current_revision_fk')
        ->and($update('UPDATE endpoints SET revision = 2 WHERE id = ?', [$a]))->toThrow(PDOException::class, 'endpoints_current_revision_fk')
        ->and($update('UPDATE endpoints SET revision = 2, current_revision_id = ? WHERE id = ?', [$revisionA2, $a]))->not->toThrow(PDOException::class);
});

it('treats % and _ in a search as text, and ! as itself', function () {
    $workspace = Cluster::workspace('Acme');
    epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');

    foreach (['/a_b', '/axb', '/c!d', '/cd'] as $path) {
        epCreate($source, ['path' => $path])->assertCreated();
    }

    $paths = fn (string $q): array => array_column(test()->getJson(epUrl($source).'?q='.rawurlencode($q), EP_HEADERS)->assertOk()->json('data'), 'path');

    expect($paths('a_b'))->toBe(['/a_b'])
        ->and($paths('_'))->toBe(['/a_b'])
        ->and($paths('c!d'))->toBe(['/c!d'])
        ->and($paths('!'))->toBe(['/c!d']);
});

it('leaves an Endpoint as it was when the audit write of a revision fails', function () {
    $workspace = Cluster::workspace('Acme');
    epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $id = epCreate($source)->assertCreated()->json('data.endpoint_id');
    $before = [epRows()[0], epRows('endpoint_revisions')];
    Cluster::superuser()->exec("CREATE OR REPLACE FUNCTION ep_fail_audit() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.action = 'connector.endpoint.revised' THEN RAISE EXCEPTION 'audit failed'; END IF; RETURN NEW; END \$\$");
    Cluster::superuser()->exec('CREATE TRIGGER ep_fail_audit BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION ep_fail_audit()');

    try {
        $this->withoutExceptionHandling();
        expect(fn () => epUpdate($source, $id, 1, ['path' => '/changed']))->toThrow(QueryException::class);
    } finally {
        Cluster::superuser()->exec('DROP TRIGGER ep_fail_audit ON audit_events');
        Cluster::superuser()->exec('DROP FUNCTION ep_fail_audit()');
    }

    expect([epRows()[0], epRows('endpoint_revisions')])->toBe($before);
});

it('answers a stale revision with a conflict, never a not-found, after a locked read', function () {
    $workspace = Cluster::workspace('Acme');
    [, $membership] = epAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $id = epCreate($source)->assertCreated()->json('data.endpoint_id');
    epUpdate($source, $id, 1)->assertOk();
    $actor = new DataSourceActor($membership, $workspace);
    $input = (new ValidateEndpointInput)->validate(epBody());

    expect(fn () => app(Endpoints::class)->revise($actor, $source, $id, $input, 1))->toThrow(EndpointRevisionConflict::class);
});
