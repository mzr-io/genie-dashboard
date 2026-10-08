<?php

use App\Models\User;
use App\Modules\Connector\Contracts\HostResolver;
use App\Modules\Connector\Infrastructure\CurlClient;
use App\Platform\Operations\Operations;
use App\Platform\Operations\RunOperation;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;
use Tests\Unit\Support\FakeCurl;
use Tests\Unit\Support\FakeResolver;

// Story 2.13 against the real PostgreSQL: user-context bindings on Endpoint parameters and headers, the derived
// `requires_user_context`, and Fetch as user, which resolves a member's ID, email, group and attributes on the server, sends the
// request through the same guard and hands the response only to the requester as the sealed blob.
const FU_HEADERS = ['Referer' => 'http://localhost:8000'];
const FU_CANARY = 'CANARY-fu-91c3a7';
const FU_BODY = '{"rows":[{"n":1.10}]}';

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();

    $this->keyFile = tempnam(sys_get_temp_dir(), 'dashflow-data-key');
    $this->digestFile = tempnam(sys_get_temp_dir(), 'dashflow-digest-key');
    file_put_contents($this->keyFile, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    file_put_contents($this->digestFile, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    config(['dashflow.secrets.data_key_path.value' => $this->keyFile, 'dashflow.secrets.digest_key_path.value' => $this->digestFile]);

    $this->logFile = tempnam(sys_get_temp_dir(), 'dashflow-log');
    config(['logging.default' => 'single', 'logging.channels.single.path' => $this->logFile]);

    $this->curl = new FakeCurl;
    app()->instance(HostResolver::class, new FakeResolver(['api.example.com' => ['93.184.216.34']]));
    app()->instance(CurlClient::class, $this->curl);
});

afterEach(function () {
    @unlink($this->keyFile);
    @unlink($this->digestFile);
    @unlink($this->logFile);
});

const FU_ALL = ['data_sources.manage', 'data.preview_as_user', 'users.manage', 'settings.manage'];

/** @return array{0: int, 1: string} user ID and membership ID */
function fuMember(string $workspaceId, string $email, string $role = 'user', array $permissions = [], string $status = 'active'): array
{
    $user = Cluster::user($email);
    Cluster::superuser()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([Hash::make('password-1234'), $user]);
    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspaceId, $user, $role, $status]);

    foreach ($permissions as $permission) {
        Cluster::superuser()->prepare('INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
            ->execute([(string) Str::uuid7(), $workspaceId, $membership, $permission]);
    }

    return [$user, $membership];
}

function fuSignIn(int $user, string $workspaceId): void
{
    auth()->forgetGuards();
    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspaceId, 'area' => 'admin']);
}

/** An Admin signed in, with api.example.com allowlisted; returns [user, membership]. */
function fuAdmin(string $workspaceId, array $permissions = FU_ALL): array
{
    [$user, $membership] = fuMember($workspaceId, 'ada@example.test', 'admin', $permissions);
    Cluster::superuser()->prepare('INSERT INTO host_allowlist_entries (id, workspace_id, host, scheme, port, added_by_membership_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, now(), now())')
        ->execute([(string) Str::uuid7(), $workspaceId, 'api.example.com', 'https', 443, $membership]);
    fuSignIn($user, $workspaceId);

    return [$user, $membership];
}

function fuGroup(string $workspaceId, string $membership, string $name): void
{
    $group = Cluster::seedGroup($workspaceId, $name);
    Cluster::superuser()->prepare('INSERT INTO group_members (id, workspace_id, group_id, membership_id, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
        ->execute([(string) Str::uuid7(), $workspaceId, $group, $membership]);
}

function fuAttribute(string $membership, array $values): void
{
    test()->putJson("/api/v1/admin/members/{$membership}/attributes", ['values' => $values], FU_HEADERS)->assertOk();
}

function fuBody(array $overrides = []): array
{
    return $overrides + [
        'method' => 'GET', 'path' => '/reports/{id}',
        'params' => [
            ['name' => 'id', 'binding' => 'fixed', 'value' => 'r-1'],
            ['name' => 'uid', 'binding' => 'user_id'],
            ['name' => 'region', 'binding' => 'user_attribute', 'value' => 'region'],
        ],
        'headers' => [['name' => 'X-Mail', 'binding' => 'user_email'], ['name' => 'X-Group', 'binding' => 'user_group']],
        'body_template' => null, 'read_only_query' => false, 'confirm_read_only' => false,
    ];
}

function fuEndpoint(string $source, array $overrides = []): string
{
    return test()->postJson("/api/v1/admin/data-sources/{$source}/endpoints", fuBody($overrides), FU_HEADERS)->assertCreated()->json('data.endpoint_id');
}

function fuUrl(string $source, string $endpoint, string $tail): string
{
    return "/api/v1/admin/data-sources/{$source}/endpoints/{$endpoint}/{$tail}";
}

function fuFetch(string $source, string $endpoint, string $membership, array $values = [])
{
    return test()->postJson(fuUrl($source, $endpoint, 'fetch-as-user'), ['membership' => $membership, 'values' => $values], FU_HEADERS);
}

function fuOperation(string $id): array
{
    return test()->getJson('/api/v1/operations/'.$id, FU_HEADERS)->assertOk()->json('data');
}

function fuEverything(): string
{
    return json_encode([
        Cluster::rows(Cluster::superuser(), 'select * from operations'),
        Cluster::rows(Cluster::superuser(), 'select * from sync_runs'),
        Cluster::rows(Cluster::superuser(), 'select * from audit_events'),
        Cluster::rows(Cluster::superuser(), 'select * from outbox_events'),
        Cluster::rows(Cluster::superuser(), 'select * from endpoint_revisions'),
        (string) file_get_contents(test()->logFile),
    ], JSON_THROW_ON_ERROR);
}

function fuCount(string $table): int
{
    return Cluster::rows(Cluster::superuser(), "select count(*) as n from {$table}")[0]['n'];
}

/** A Workspace with an Admin, a target member holding the canary attribute, one group, and an Endpoint bound to all four. */
function fuWorld(): array
{
    $workspace = Cluster::workspace('Acme');
    [, $admin] = fuAdmin($workspace);
    Cluster::seedAttributeKey($workspace, 'region');
    [, $target] = fuMember($workspace, 'grace@example.test');
    fuGroup($workspace, $target, 'Finance');
    fuAttribute($target, ['region' => FU_CANARY]);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = fuEndpoint($source);

    return [$workspace, $admin, $target, $source, $endpoint];
}

it('stores user bindings with requires_user_context derived true, a new revision and an audit with counts and the flags', function () {
    $workspace = Cluster::workspace('Acme');
    fuAdmin($workspace);
    Cluster::seedAttributeKey($workspace, 'region');
    $source = Cluster::seedDataSource($workspace, 'Sales API');

    $created = test()->postJson("/api/v1/admin/data-sources/{$source}/endpoints", fuBody(), FU_HEADERS)->assertCreated();
    $endpoint = $created->json('data.endpoint_id');

    expect($created->json('data.requires_user_context'))->toBeTrue()
        ->and($created->json('data.scope_by_caller'))->toBeFalse()
        ->and(array_column($created->json('data.params'), 'value', 'name'))->toMatchArray(['uid' => null, 'region' => 'region'])
        ->and(array_column($created->json('data.headers'), 'binding', 'name'))->toBe(['X-Mail' => 'user_email', 'X-Group' => 'user_group']);

    $revised = test()->putJson("/api/v1/admin/data-sources/{$source}/endpoints/{$endpoint}", fuBody(['revision' => 1, 'scope_by_caller' => true, 'requires_user_context' => false]), FU_HEADERS)->assertOk();

    expect($revised->json('data.revision'))->toBe(2)
        ->and($revised->json('data.requires_user_context'))->toBeTrue()
        ->and($revised->json('data.scope_by_caller'))->toBeTrue();

    $rows = Cluster::rows(Cluster::superuser(), 'select revision, requires_user_context, scope_by_caller from endpoint_revisions where endpoint_id = ? order by revision', [$endpoint]);
    expect($rows)->toHaveCount(2)->and($rows[1])->toMatchArray(['revision' => 2, 'requires_user_context' => true, 'scope_by_caller' => true]);

    $audit = Cluster::rows(Cluster::superuser(), "select after_state from audit_events where action = 'connector.endpoint.revised'");
    $state = json_decode($audit[0]['after_state'], true);
    expect($audit)->toHaveCount(1)
        ->and($state)->toMatchArray(['requires_user_context' => 'true', 'scope_by_caller' => 'true', 'user_binding_count' => 4, 'param_count' => 3, 'header_count' => 2]);
});

it('shows no user context for an Endpoint without a user binding', function () {
    $workspace = Cluster::workspace('Acme');
    fuAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Sales API');

    $created = test()->postJson("/api/v1/admin/data-sources/{$source}/endpoints", fuBody(['path' => '/reports', 'params' => [['name' => 'a', 'binding' => 'fixed', 'value' => 'x']], 'headers' => []]), FU_HEADERS)->assertCreated();

    expect($created->json('data.requires_user_context'))->toBeFalse()->and($created->json('data.scope_by_caller'))->toBeFalse();
});

it('refuses an attribute key that is not defined and a scope flag without a user binding, and stores nothing', function () {
    $workspace = Cluster::workspace('Acme');
    fuAdmin($workspace);
    Cluster::seedAttributeKey($workspace, 'region');
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $url = "/api/v1/admin/data-sources/{$source}/endpoints";

    test()->postJson($url, fuBody(['path' => '/reports', 'params' => [['name' => 'a', 'binding' => 'user_attribute', 'value' => 'nope']]]), FU_HEADERS)
        ->assertStatus(422)->assertJsonPath('reasons', ['params.0.binding' => 'binding-attribute-unknown']);
    test()->postJson($url, fuBody(['path' => '/reports', 'params' => [], 'headers' => [['name' => 'X-A', 'binding' => 'user_attribute']]]), FU_HEADERS)
        ->assertStatus(422)->assertJsonPath('reasons', ['headers.0.binding' => 'binding-attribute-unknown']);
    test()->postJson($url, fuBody(['path' => '/reports', 'scope_by_caller' => true, 'params' => [['name' => 'a', 'binding' => 'fixed', 'value' => 'x']], 'headers' => []]), FU_HEADERS)
        ->assertStatus(422)->assertJsonPath('reasons', ['scope_by_caller' => 'scope-requires-user-context']);

    expect(fuCount('endpoints'))->toBe(0);
});

it('refuses scope_by_caller without requires_user_context in the database too', function () {
    $workspace = Cluster::workspace('Acme');
    $revision = Cluster::seedEndpointRevision($workspace);

    expect(fn () => Cluster::superuser()->prepare('INSERT INTO endpoint_revisions (id, workspace_id, endpoint_id, revision, method, path_template, path_ast, scope_by_caller, requires_user_context, created_at, created_by_membership_id) SELECT ?, workspace_id, endpoint_id, 9, method, path_template, path_ast, true, false, now(), created_by_membership_id FROM endpoint_revisions WHERE id = ?')
        ->execute([(string) Str::uuid7(), $revision]))->toThrow(PDOException::class);
});

it('lists the four user bindings and each defined attribute key and label in the binding options, and no value', function () {
    [$workspace, , $target, $source] = fuWorld();

    $options = test()->getJson("/api/v1/admin/data-sources/{$source}/binding-options", FU_HEADERS)->assertOk();

    expect($options->json('data.bindings'))->toBe(['user_id', 'user_email', 'user_group', 'user_attribute'])
        ->and($options->json('data.attributes.0'))->toMatchArray(['key_id' => 'region'])
        ->and($options->json('data.may_preview'))->toBeTrue()
        ->and(array_column($options->json('data.members'), 'membership_id'))->toContain($target)
        ->and($options->getContent())->not->toContain(FU_CANARY);
});

it('refuses a client value for a user-bound name on a test and on a fetch as user, and queues nothing', function (string $name) {
    Queue::fake();
    [, , $target, $source, $endpoint] = fuWorld();

    $test = test()->postJson(fuUrl($source, $endpoint, 'test'), ['values' => [$name => 'forged-'.FU_CANARY]], FU_HEADERS)->assertStatus(422);
    $fetch = fuFetch($source, $endpoint, $target, [$name => 'forged-'.FU_CANARY])->assertStatus(422);

    // The reason is keyed by the field, `values.{name}`, whose name may itself hold a dot.
    expect($test->json('reasons')["values.{$name}"])->toBe('value-not-accepted')
        ->and($fetch->json('reasons')["values.{$name}"])->toBe('value-not-accepted')
        ->and($fetch->getContent())->not->toContain(FU_CANARY);

    expect(fuCount('operations'))->toBe(0)->and($this->curl->calls)->toBe([])
        ->and(Cluster::rows(Cluster::superuser(), "select count(*) as n from outbox_events where type = 'connector.fetch_as_user.performed'")[0]['n'])->toBe(0)
        ->and(Cluster::rows(Cluster::superuser(), "select count(*) as n from audit_events where action = 'connector.fetch_as_user.performed'")[0]['n'])->toBe(0);
})->with(['uid', 'region', 'header:X-Mail', 'header:X-Group']);

it('refuses the plain test of an Endpoint that requires user context and points to Fetch as user, queuing nothing', function () {
    Queue::fake();
    [, , , $source, $endpoint] = fuWorld();

    $refused = test()->postJson(fuUrl($source, $endpoint, 'test'), ['values' => []], FU_HEADERS)->assertStatus(422);

    expect($refused->json('reasons'))->toBe(['endpoint' => 'requires-user-context'])
        ->and($refused->json('errors.endpoint.0'))->toContain('Fetch as user')
        ->and(fuCount('operations'))->toBe(0)
        ->and($this->curl->calls)->toBe([]);
});

it('fetches as a member: resolves the ID, email, group and attribute on the server, sends one guarded request and gives the sealed response to the initiator only', function () {
    [$workspace, $admin, $target, $source, $endpoint] = fuWorld();
    $this->curl->queue = [FakeCurl::answer(200, FU_BODY)];

    $started = fuFetch($source, $endpoint, $target)->assertStatus(202)->assertHeader('Cache-Control', 'no-store, private');
    $id = $started->json('data.operation_id');

    $operation = Cluster::rows(Cluster::superuser(), 'select * from operations where id = ?', [$id])[0];
    expect($operation)->toMatchArray(['kind' => 'fetch_as_user', 'status' => 'succeeded', 'subject_type' => 'endpoint', 'subject_id' => $endpoint, 'subject_revision' => 1, 'requester_membership_id' => $admin]);

    expect($this->curl->calls)->toHaveCount(1)
        ->and($this->curl->calls[0][CURLOPT_URL])->toBe("https://api.example.com/reports/r-1?uid={$target}&region=".FU_CANARY)
        ->and($this->curl->calls[0][CURLOPT_HTTPHEADER])->toContain('X-Mail: grace@example.test')->toContain('X-Group: Finance');

    $summary = fuOperation($id);
    expect($summary['result'])->toMatchArray(['ok' => true, 'status' => 200, 'code' => null, 'missing' => null])
        ->and(json_encode($summary))->not->toContain(FU_CANARY)->not->toContain('grace@');

    $read = fn () => test()->getJson(fuUrl($source, $endpoint, 'samples/'.$id), FU_HEADERS);
    expect($read()->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data.body'))->toBe(FU_BODY);

    // Nobody else reads it: not the target, not another Admin.
    [$otherUser] = fuMember($workspace, 'omar@example.test', 'admin', FU_ALL);
    fuSignIn($otherUser, $workspace);
    $read()->assertNotFound();
    [$targetUser] = [Cluster::rows(Cluster::superuser(), 'select user_id from workspace_memberships where id = ?', [$target])[0]['user_id']];
    fuSignIn($targetUser, $workspace);
    expect($read()->status())->toBeIn([403, 404]);

    // Nothing else holds the response, and the audit and the outbox hold IDs only.
    expect(fuEverything())->not->toContain(FU_CANARY)->not->toContain('grace@example.test')->not->toContain('12345')
        ->and(fuEverything())->not->toContain('Finance');

    $audit = Cluster::rows(Cluster::superuser(), "select * from audit_events where action = 'connector.fetch_as_user.performed'");
    $state = json_decode($audit[0]['after_state'], true);
    expect($audit)->toHaveCount(1)
        ->and($audit[0]['actor'])->toBe($admin)
        ->and($state)->toMatchArray(['endpoint_id' => $endpoint, 'target_membership_id' => $target, 'endpoint_revision' => 1])
        ->and(array_keys($state))->toEqualCanonicalizing(['endpoint_id', 'data_source_id', 'target_membership_id', 'endpoint_revision']);

    $events = Cluster::rows(Cluster::superuser(), "select * from outbox_events where type = 'connector.fetch_as_user.performed'");
    expect($events)->toHaveCount(1)
        ->and($events[0]['subject'])->toBe('membership:'.$target)
        ->and(json_decode($events[0]['data'], true))->toEqualCanonicalizing(['endpoint_id' => $endpoint, 'data_source_id' => $source, 'target_membership_id' => $target, 'endpoint_revision' => 1]);

    $run = Cluster::rows(Cluster::superuser(), "select * from sync_runs where kind = 'fetch_as_user'");
    expect($run)->toHaveCount(1)->and($run[0]['url_template'])->toBe('https://api.example.com/reports/{id}');
});

it('answers 403 with a security event to an Admin without data.preview_as_user, and queues nothing', function () {
    Queue::fake();
    [$workspace, , $target, $source, $endpoint] = fuWorld();
    [$user] = fuMember($workspace, 'noperm@example.test', 'admin', ['data_sources.manage']);
    fuSignIn($user, $workspace);

    fuFetch($source, $endpoint, $target)->assertForbidden()->assertJsonPath('error.code', 'access.not_authorized');

    $events = Cluster::rows(Cluster::superuser(), "select after_state from audit_events where action = 'access.admin.denied'");
    expect($events)->toHaveCount(1)
        ->and(json_decode($events[0]['after_state'], true))->toMatchArray(['permission' => 'data.preview_as_user', 'reason' => 'permission'])
        ->and(fuCount('operations'))->toBe(0);

    $options = test()->getJson("/api/v1/admin/data-sources/{$source}/binding-options", FU_HEADERS)->assertOk();
    expect($options->json('data.may_preview'))->toBeFalse()->and($options->json('data.members'))->toBe([]);
});

it('fails closed with access.context_missing, naming the key ids only, and sends no request, when an attribute has no value', function () {
    [$workspace, , , $source, $endpoint] = fuWorld();
    [, $bare] = fuMember($workspace, 'bare@example.test');
    fuGroup($workspace, $bare, 'Ops');

    $id = fuFetch($source, $endpoint, $bare)->assertStatus(202)->json('data.operation_id');
    $summary = fuOperation($id);

    expect($summary['status'])->toBe('failed')
        ->and($summary['result'])->toMatchArray(['ok' => false, 'code' => 'access.context_missing', 'reason' => 'context_missing', 'missing' => 'region'])
        ->and($this->curl->calls)->toBe([]);
    test()->getJson(fuUrl($source, $endpoint, 'samples/'.$id), FU_HEADERS)->assertNotFound();
    expect(fuEverything())->not->toContain(FU_CANARY);
});

it('fails closed naming user_group when the member is in no group or in several', function (array $groups) {
    [$workspace, , , $source, $endpoint] = fuWorld();
    [, $member] = fuMember($workspace, 'multi@example.test');
    fuAttribute($member, ['region' => 'emea']);

    foreach ($groups as $name) {
        fuGroup($workspace, $member, $name);
    }

    $summary = fuOperation(fuFetch($source, $endpoint, $member)->assertStatus(202)->json('data.operation_id'));

    expect($summary['result'])->toMatchArray(['ok' => false, 'code' => 'access.context_missing', 'missing' => 'user_group'])
        ->and($this->curl->calls)->toBe([]);
})->with(['no group' => [[]], 'two groups' => [['A', 'B']]]);

it('fails closed with a distinct reason when a resolved header value cannot be sent, and sends nothing', function (string $group) {
    [$workspace, , , $source, $endpoint] = fuWorld();
    [, $member] = fuMember($workspace, 'odd@example.test');
    fuAttribute($member, ['region' => 'emea']);
    fuGroup($workspace, $member, $group);

    $summary = fuOperation(fuFetch($source, $endpoint, $member)->assertStatus(202)->json('data.operation_id'));

    expect($summary['result'])->toMatchArray(['ok' => false, 'code' => 'access.context_missing', 'reason' => 'context_value_invalid', 'missing' => null])
        ->and($this->curl->calls)->toBe([])
        ->and(fuEverything())->not->toContain('quipe');
})->with(['non-ASCII' => ["\u{c9}quipe"], 'a name with an emoji' => ["Team \u{1F680}"]]);

it('fails closed when a resolved path value is not one segment', function () {
    $workspace = Cluster::workspace('Acme');
    fuAdmin($workspace);
    Cluster::seedAttributeKey($workspace, 'region');
    [, $member] = fuMember($workspace, 'path@example.test');
    fuAttribute($member, ['region' => 'a/b']);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = fuEndpoint($source, ['path' => '/reports/{region}', 'params' => [['name' => 'region', 'binding' => 'user_attribute', 'value' => 'region']], 'headers' => []]);

    $summary = fuOperation(fuFetch($source, $endpoint, $member)->assertStatus(202)->json('data.operation_id'));

    expect($summary['result'])->toMatchArray(['ok' => false, 'reason' => 'context_value_invalid'])->and($this->curl->calls)->toBe([]);
});

it('answers 404 for a target in another Workspace, a deactivated member or an unknown id, and queues nothing', function () {
    Queue::fake();
    [$workspace, , , $source, $endpoint] = fuWorld();
    $other = Cluster::workspace('Globex');
    [, $stranger] = fuMember($other, 'stranger@example.test');
    [, $gone] = fuMember($workspace, 'gone@example.test', 'user', [], 'deactivated');

    foreach ([$stranger, $gone, (string) Str::uuid7()] as $target) {
        fuFetch($source, $endpoint, $target)->assertNotFound();
    }

    test()->postJson(fuUrl($source, $endpoint, 'fetch-as-user'), ['membership' => 'not-a-uuid'], FU_HEADERS)->assertStatus(422);
    expect(fuCount('operations'))->toBe(0);
});

it('shares the rate limits with Test endpoint: over the limit it answers 429 and enqueues nothing more', function () {
    Queue::fake();
    [$workspace, , $target, $source, $endpoint] = fuWorld();
    config(['dashflow.sample_fetch.membership_limit.value' => '1', 'dashflow.sample_fetch.window.value' => '60']);

    fuFetch($source, $endpoint, $target)->assertStatus(202);
    $over = fuFetch($source, $endpoint, $target)->assertStatus(429);

    expect($over->json('error.retry_after'))->toBeInt()->and(fuCount('operations'))->toBe(1);
});

it('needs only the data key to resolve a member (no digest key), and fails closed with attributes_unavailable when the data key is unusable', function () {
    [, , $target, $source, $endpoint] = fuWorld();
    $this->curl->queue = [FakeCurl::answer(200, FU_BODY)];
    config(['dashflow.secrets.digest_key_path.value' => '/nonexistent/key-digest']);

    $ok = fuOperation(fuFetch($source, $endpoint, $target)->assertStatus(202)->json('data.operation_id'));
    expect($ok['status'])->toBe('succeeded')->and($this->curl->calls)->toHaveCount(1);

    config(['dashflow.secrets.data_key_path.value' => '/nonexistent/key-data']);
    $failed = fuOperation(fuFetch($source, $endpoint, $target)->assertStatus(202)->json('data.operation_id'));

    expect($failed['status'])->toBe('failed')
        ->and($failed['result'])->toMatchArray(['ok' => false, 'code' => 'fetch-failed', 'reason' => 'attributes_unavailable'])
        ->and($this->curl->calls)->toHaveCount(1);
});

it('fails with member_unavailable and sends nothing when the target is deactivated between the start and the run', function () {
    Queue::fake();
    [$workspace, , $target, $source, $endpoint] = fuWorld();

    $id = fuFetch($source, $endpoint, $target)->assertStatus(202)->json('data.operation_id');
    Cluster::superuser()->prepare("UPDATE workspace_memberships SET status = 'deactivated' WHERE id = ?")->execute([$target]);

    $job = Queue::pushed(RunOperation::class)->first();
    app(WorkspaceTransaction::class)->runJob($job, fn ($j) => $j->handle(app(Operations::class)));

    $summary = fuOperation($id);
    expect($summary['status'])->toBe('failed')
        ->and($summary['result'])->toMatchArray(['ok' => false, 'code' => 'access.context_missing', 'reason' => 'member_unavailable', 'missing' => null])
        ->and($this->curl->calls)->toBe([]);
});

it('refuses to show a Fetch as user response to an initiator who has lost data.preview_as_user (403)', function () {
    [, $admin, $target, $source, $endpoint] = fuWorld();
    $this->curl->queue = [FakeCurl::answer(200, FU_BODY)];
    $id = fuFetch($source, $endpoint, $target)->assertStatus(202)->json('data.operation_id');
    $read = fn () => test()->getJson(fuUrl($source, $endpoint, 'samples/'.$id), FU_HEADERS);

    $read()->assertOk();
    Cluster::superuser()->prepare("DELETE FROM membership_permissions WHERE membership_id = ? AND permission = 'data.preview_as_user'")->execute([$admin]);

    $read()->assertForbidden();
});
