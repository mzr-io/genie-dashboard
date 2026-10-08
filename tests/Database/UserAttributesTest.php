<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Modules\Access\Contracts\ErrorCode;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Audit\AuditHasher;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxRelay;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 2.12 against the real PostgreSQL: keys are immutable and renamed under a revision, values are sealed with a blind
// index, audit holds hashes only, the outbox event is written with the change, nobody edits their own, another Workspace
// is invisible, a removed membership's values are deleted by the consumer and the canary value is nowhere.
const UA_HEADERS = ['Referer' => 'http://localhost:8000'];
const UA_CANARY = 'CANARY-7f3a91-region';

beforeEach(function () {
    $this->withoutVite();
    $this->dataKey = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $this->digestKey = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $this->dataFile = tempnam(sys_get_temp_dir(), 'dashflow-data-key');
    $this->digestFile = tempnam(sys_get_temp_dir(), 'dashflow-digest-key');
    file_put_contents($this->dataFile, $this->dataKey);
    file_put_contents($this->digestFile, $this->digestKey);
    config(['dashflow.secrets.data_key_path.value' => $this->dataFile, 'dashflow.secrets.digest_key_path.value' => $this->digestFile]);
    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e): void {
        $this->logged[] = $e->message.' '.json_encode($e->context);
    });
});

afterEach(function () {
    @unlink($this->dataFile);
    @unlink($this->digestFile);
});

function uaMember(string $workspaceId, string $email, string $role = 'user', array $permissions = []): array
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

/** An Admin holding both permissions, signed in; returns [user ID, membership ID]. */
function uaAdmin(string $workspaceId, array $permissions = ['settings.manage', 'users.manage']): array
{
    [$user, $membership] = uaMember($workspaceId, 'ada@example.test', 'admin', $permissions);
    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspaceId, 'area' => 'admin']);

    return [$user, $membership];
}

function uaKey(string $keyId = 'region', string $label = 'Region', string $type = 'text')
{
    return test()->postJson('/api/v1/admin/user-attributes', ['key_id' => $keyId, 'label' => $label, 'value_type' => $type], UA_HEADERS);
}

function uaSet(string $membership, array $values)
{
    return test()->putJson("/api/v1/admin/members/{$membership}/attributes", ['values' => $values], UA_HEADERS);
}

function uaAudits(string $action): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from audit_events where action = ? order by occurred_at', [$action]);
}

function uaEvents(string $type): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from outbox_events where type = ? order by occurred_at', [$type]);
}

function uaStored(string $membership): array
{
    return Cluster::rows(Cluster::superuser(), "select k.key_id, encode(a.value_ciphertext, 'hex') as hex, a.value_blind_index, a.blind_index_version from user_attributes a join user_attribute_keys k on k.id = a.attribute_key_id where a.membership_id = ? order by k.key_id", [$membership]);
}

it('creates a key with the key id and type fixed, audits it and refuses duplicates', function () {
    $workspace = Cluster::workspace('Acme');
    [, $ada] = uaAdmin($workspace);

    $created = uaKey('employee_no', 'Employee number', 'identifier')->assertCreated();

    expect($created->json('data'))->toMatchArray(['key_id' => 'employee_no', 'label' => 'Employee number', 'value_type' => 'identifier', 'revision' => 1]);
    $audit = uaAudits('access.attribute_key.created');
    expect($audit)->toHaveCount(1)
        ->and($audit[0]['actor'])->toBe($ada)
        ->and(json_decode($audit[0]['after_state'], true))->toMatchArray(['attribute_key' => 'employee_no', 'value_type' => 'identifier']);

    uaKey('employee_no', 'Another')->assertStatus(422)->assertJsonStructure(['errors' => ['key_id']]);
    uaKey('other', 'EMPLOYEE NUMBER')->assertStatus(422)->assertJsonStructure(['errors' => ['label']]);
    uaKey('Bad-Id', 'Label')->assertStatus(422)->assertJsonStructure(['errors' => ['key_id']]);
    uaKey('okay', 'Label', 'regex')->assertStatus(422)->assertJsonStructure(['errors' => ['value_type']]);
    uaKey('okay', "Bad\u{200B}label")->assertStatus(422)->assertJsonStructure(['errors' => ['label']]);
    uaKey('okay', str_repeat('x', 65))->assertStatus(422)->assertJsonStructure(['errors' => ['label']]);
    expect(uaAudits('access.attribute_key.created'))->toHaveCount(1);
});

it('renames under the revision, ignores a key id and type in the body and refuses a stale revision with 409', function () {
    $workspace = Cluster::workspace('Acme');
    uaAdmin($workspace);
    uaKey('region', 'Region')->assertCreated();
    uaKey('team', 'Team')->assertCreated();
    $put = fn (string $key, array $body) => test()->putJson("/api/v1/admin/user-attributes/{$key}", $body, UA_HEADERS);

    $renamed = $put('region', ['label' => 'Sales region', 'revision' => 1, 'key_id' => 'hacked', 'value_type' => 'integer'])->assertOk();

    expect($renamed->json('data'))->toMatchArray(['key_id' => 'region', 'label' => 'Sales region', 'value_type' => 'text', 'revision' => 2]);
    $audit = uaAudits('access.attribute_key.renamed');
    $state = json_decode($audit[0]['after_state'], true);
    expect($audit)->toHaveCount(1)
        ->and($state['label'])->toStartWith(AuditHasher::PREFIX)
        ->and($audit[0]['after_state'])->not->toContain('Sales region');

    $stale = $put('region', ['label' => 'Again', 'revision' => 1])->assertStatus(409);
    expect($stale->json('error.code'))->toBe(ErrorCode::RevisionConflict->value)
        ->and($stale->json('current.revision'))->toBe(2);

    $put('region', ['label' => 'team', 'revision' => 2])->assertStatus(422)->assertJsonStructure(['errors' => ['label']]);
    $put('nope', ['label' => 'X', 'revision' => 1])->assertNotFound();
    expect(uaAudits('access.attribute_key.renamed'))->toHaveCount(1);
});

it('lists keys with a search and counts', function () {
    $workspace = Cluster::workspace('Acme');
    uaAdmin($workspace);
    uaKey('region', 'Region');
    uaKey('team', 'Squad');

    $all = test()->getJson('/api/v1/admin/user-attributes', UA_HEADERS)->assertOk();
    $found = test()->getJson('/api/v1/admin/user-attributes?q=SQU', UA_HEADERS)->assertOk();

    expect(array_column($all->json('data'), 'key_id'))->toBe(['region', 'team'])
        ->and($all->json('meta'))->toBe(['total' => 2, 'matched' => 2])
        ->and(array_column($found->json('data'), 'key_id'))->toBe(['team'])
        ->and($found->json('meta'))->toBe(['total' => 2, 'matched' => 1]);
});

it('refuses any UPDATE of key_id and value_type in the database, for any role', function (string $column, string $value) {
    $workspace = Cluster::workspace('Acme');
    $key = Cluster::seedAttributeKey($workspace, 'region');

    expect(fn () => Cluster::superuser()->prepare("UPDATE user_attribute_keys SET {$column} = ? WHERE id = ?")->execute([$value, $key]))->toThrow(PDOException::class);
    Cluster::superuser()->prepare('UPDATE user_attribute_keys SET label = ? WHERE id = ?')->execute(['Renamed', $key]);
    expect(Cluster::rows(Cluster::superuser(), 'select key_id, value_type, label from user_attribute_keys where id = ?', [$key])[0])->toBe(['key_id' => 'region', 'value_type' => 'text', 'label' => 'Renamed']);
})->with([['key_id', 'other'], ['value_type', 'integer']]);

it('stores a value sealed with a blind index, audits a hash only and emits an ID-only outbox event in one transaction', function () {
    $workspace = Cluster::workspace('Acme');
    [, $ada] = uaAdmin($workspace);
    [, $bo] = uaMember($workspace, 'bo@example.test');
    uaKey('region', 'Region');
    uaKey('employee_no', 'Employee number', 'integer');

    $response = uaSet($bo, ['region' => '  '.UA_CANARY.'  ', 'employee_no' => '42'])->assertOk();

    expect($response->json('data.changed'))->toBe(['employee_no', 'region']);
    $stored = uaStored($bo);
    $expectedIndex = hash_hmac('sha256', 'attr|'.$workspace.'|region|'.UA_CANARY, base64_decode($this->digestKey));
    expect($stored)->toHaveCount(2)
        ->and($stored[1]['value_blind_index'])->toBe($expectedIndex)
        ->and($stored[1]['blind_index_version'])->toBe(1)
        ->and($stored[1]['hex'])->not->toContain(bin2hex(UA_CANARY));

    $audit = uaAudits('access.attribute.changed');
    expect($audit)->toHaveCount(2)
        ->and($audit[0]['actor'])->toBe($ada)
        ->and($audit[0]['subject'])->toBe('membership:'.$bo)
        ->and(json_decode($audit[0]['after_state'], true)['attribute_value'])->toStartWith(AuditHasher::PREFIX);

    $events = uaEvents('access.attribute.changed');
    expect($events)->toHaveCount(2);

    foreach ($events as $event) {
        expect(array_keys(json_decode($event['data'], true)))->toEqualCanonicalizing(['membership_id', 'attribute_key_id', 'attribute_key']);
    }

    $read = test()->getJson("/api/v1/admin/members/{$bo}/attributes", UA_HEADERS)->assertOk();
    expect(array_column($read->json('data'), 'value', 'key_id'))->toBe(['employee_no' => '42', 'region' => UA_CANARY])
        ->and($read->headers->get('Cache-Control'))->toContain('no-store');
});

it('is a no-op without audit or event for the same value and changes only what changed', function () {
    $workspace = Cluster::workspace('Acme');
    uaAdmin($workspace);
    [, $bo] = uaMember($workspace, 'bo@example.test');
    uaKey('region', 'Region');
    uaKey('team', 'Team');
    uaSet($bo, ['region' => 'North'])->assertOk();
    $before = uaStored($bo);

    expect(uaSet($bo, ['region' => ' North '])->assertOk()->json('data.changed'))->toBe([])
        ->and(uaStored($bo))->toBe($before)
        ->and(uaAudits('access.attribute.changed'))->toHaveCount(1)
        ->and(uaEvents('access.attribute.changed'))->toHaveCount(1);

    expect(uaSet($bo, ['region' => 'South', 'team' => 'A'])->assertOk()->json('data.changed'))->toBe(['region', 'team'])
        ->and(uaAudits('access.attribute.changed'))->toHaveCount(3);
});

it('refuses bad values with a 422 naming the field and stores nothing', function (string $type, mixed $value, string $reason) {
    $workspace = Cluster::workspace('Acme');
    uaAdmin($workspace);
    [, $bo] = uaMember($workspace, 'bo@example.test');
    uaKey('good', 'Good');
    uaKey('subject', 'Subject', $type);

    $response = uaSet($bo, ['good' => 'fine', 'subject' => $value])->assertStatus(422);

    expect($response->json('errors'))->toHaveKey('values.subject')
        ->and($response->json('reasons.subject'))->toBe($reason)
        ->and(json_encode($response->json()))->not->toContain('CANARY')
        ->and(uaStored($bo))->toBe([])
        ->and(uaAudits('access.attribute.changed'))->toBe([])
        ->and(uaEvents('access.attribute.changed'))->toBe([]);
})->with([
    'empty text' => ['text', '', 'empty'],
    'blank text' => ['text', '   ', 'empty'],
    'long text' => ['text', str_repeat('a', 257), 'too_long'],
    'control char' => ['text', "CANARY\x07bell", 'invalid'],
    'not a string' => ['text', ['CANARY'], 'invalid'],
    'identifier space' => ['identifier', 'CANARY x', 'invalid'],
    'long identifier' => ['identifier', str_repeat('a', 129), 'too_long'],
    'leading zero' => ['integer', '007', 'invalid'],
    'integer text' => ['integer', 'CANARY', 'invalid'],
    'integer too long' => ['integer', '-1234567890123456789', 'too_long'],
]);

it('accepts the boundary values of each type', function () {
    $workspace = Cluster::workspace('Acme');
    uaAdmin($workspace);
    [, $bo] = uaMember($workspace, 'bo@example.test');
    uaKey('t', 'T');
    uaKey('i', 'I', 'identifier');
    uaKey('n', 'N', 'integer');
    uaKey('z', 'Z', 'integer');

    uaSet($bo, ['t' => str_repeat('é', 256), 'i' => str_repeat('a.-_', 32), 'n' => '-999999999999999999', 'z' => '0'])->assertOk();

    expect(uaStored($bo))->toHaveCount(4);
});

it('refuses an undefined key with a 422 and stores nothing, even for the defined keys of the same request', function () {
    $workspace = Cluster::workspace('Acme');
    uaAdmin($workspace);
    [, $bo] = uaMember($workspace, 'bo@example.test');
    uaKey('region', 'Region');

    uaSet($bo, ['region' => 'North', 'ghost' => 'x'])->assertStatus(422)->assertJsonPath('reasons.ghost', 'undefined_key');
    expect(uaStored($bo))->toBe([]);
});

it('refuses editing your own attributes with 403 and records a security event', function () {
    $workspace = Cluster::workspace('Acme');
    [, $ada] = uaAdmin($workspace);
    uaKey('region', 'Region');

    uaSet($ada, ['region' => 'North'])->assertForbidden()->assertJsonPath('error.code', ErrorCode::SelfChangeForbidden->value);

    $denied = uaAudits('access.admin.denied');
    expect(uaStored($ada))->toBe([])
        ->and($denied)->toHaveCount(1)
        ->and($denied[0]['security'])->toBeTrue()
        ->and($denied[0]['subject'])->toBe('membership:'.$ada)
        ->and(json_decode($denied[0]['after_state'], true))->toMatchArray(['reason' => 'self_change', 'route' => 'api.admin.members.attributes.update'])
        ->and(uaAudits('access.attribute.changed'))->toBe([]);
});

it('answers 404 for a membership of another Workspace and shows nothing of it', function () {
    $acme = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    uaAdmin($acme);
    uaKey('region', 'Region');
    [, $stranger] = uaMember($other, 'stranger@example.test');
    $foreignKey = Cluster::seedAttributeKey($other, 'secret_key');

    uaSet($stranger, ['region' => 'North'])->assertNotFound();
    test()->getJson("/api/v1/admin/members/{$stranger}/attributes", UA_HEADERS)->assertNotFound();
    test()->getJson('/api/v1/admin/user-attributes', UA_HEADERS)->assertOk();
    uaSet('not-a-uuid', ['region' => 'North'])->assertNotFound();

    expect(array_column(test()->getJson('/api/v1/admin/user-attributes', UA_HEADERS)->json('data'), 'key_id'))->toBe(['region'])
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from user_attributes')[0]['n'])->toBe(0);
    test()->putJson("/api/v1/admin/user-attributes/{$foreignKey}", ['label' => 'X', 'revision' => 1], UA_HEADERS)->assertNotFound();
});

it('answers 503 access.attributes_unavailable and stores nothing when either key is unusable', function (string $which, string $content) {
    $workspace = Cluster::workspace('Acme');
    uaAdmin($workspace);
    [, $bo] = uaMember($workspace, 'bo@example.test');
    uaKey('region', 'Region');
    uaSet($bo, ['region' => 'North'])->assertOk();

    file_put_contents($which === 'data' ? $this->dataFile : $this->digestFile, $content);

    uaSet($bo, ['region' => 'South'])->assertStatus(503)->assertJsonPath('error.code', ErrorCode::AttributesUnavailable->value);
    test()->getJson("/api/v1/admin/members/{$bo}/attributes", UA_HEADERS)->assertStatus(503)->assertJsonPath('error.code', 'access.attributes_unavailable');

    expect(uaAudits('access.attribute.changed'))->toHaveCount(1)
        ->and(uaStored($bo))->toHaveCount(1);
})->with([
    'data key missing' => ['data', ''],
    'data key short' => ['data', 'c2hvcnQ='],
    'digest key missing' => ['digest', ''],
    'digest key not base64' => ['digest', '!!!'],
]);

it('answers 503 when the key file is absent', function () {
    $workspace = Cluster::workspace('Acme');
    uaAdmin($workspace);
    [, $bo] = uaMember($workspace, 'bo@example.test');
    uaKey('region', 'Region');
    config(['dashflow.secrets.digest_key_path.value' => '/nonexistent/key-digest']);

    uaSet($bo, ['region' => 'North'])->assertStatus(503);
    expect(uaStored($bo))->toBe([]);
});

it('does not open a value copied to another member or key', function () {
    $workspace = Cluster::workspace('Acme');
    uaAdmin($workspace);
    [, $bo] = uaMember($workspace, 'bo@example.test');
    [, $cy] = uaMember($workspace, 'cy@example.test');
    uaKey('region', 'Region');
    uaSet($bo, ['region' => 'North'])->assertOk();

    // Copy bo's sealed value onto cy: the Workspace, member and key inside the sealed payload no longer match.
    Cluster::superuser()->prepare('INSERT INTO user_attributes (id, workspace_id, membership_id, attribute_key_id, value_ciphertext, value_blind_index, blind_index_version, updated_at) SELECT ?, workspace_id, ?, attribute_key_id, value_ciphertext, value_blind_index, blind_index_version, now() FROM user_attributes WHERE membership_id = ?')
        ->execute([(string) Str::uuid7(), $cy, $bo]);

    test()->getJson("/api/v1/admin/members/{$cy}/attributes", UA_HEADERS)->assertStatus(503);
});

it('deletes a removed membership\'s values through the consumer and nothing of other members', function () {
    $workspace = Cluster::workspace('Acme');
    uaAdmin($workspace);
    [, $bo] = uaMember($workspace, 'bo@example.test');
    [, $cy] = uaMember($workspace, 'cy@example.test');
    uaKey('region', 'Region');
    uaSet($bo, ['region' => 'North'])->assertOk();
    uaSet($cy, ['region' => 'South'])->assertOk();

    app(WorkspaceTransaction::class)->run($workspace, fn () => app(Outbox::class)->emit(AuditAction::AccessMembershipRemoved, 'membership:'.$bo, ['membership_id' => $bo]));
    app(OutboxRelay::class)->relay();

    expect(uaStored($bo))->toBe([])
        ->and(uaStored($cy))->toHaveCount(1)
        ->and(Cluster::rows(Cluster::superuser(), "select applied from outbox_consumptions where consumer = 'access.attributes_on_membership_removed'")[0]['applied'])->toBeTrue();
});

it('gives the app role no DELETE on the tables and a definer function that needs the Workspace context', function () {
    $workspace = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    Cluster::seedTenantRow('user_attributes', $other);
    $app = Cluster::directApp();

    expect(fn () => Cluster::inWorkspace($app, $workspace, fn ($pdo) => $pdo->exec('delete from user_attributes')))->toThrow(PDOException::class);
    expect(fn () => Cluster::inWorkspace($app, $workspace, fn ($pdo) => $pdo->exec('delete from user_attribute_keys')))->toThrow(PDOException::class);
    expect(fn () => Cluster::rows($app, 'select access_delete_member_attributes(?::uuid)', [(string) Str::uuid7()]))->toThrow(PDOException::class);

    $membership = Cluster::rows(Cluster::superuser(), 'select membership_id from user_attributes')[0]['membership_id'];
    // Called from another Workspace's context it removes nothing.
    $removed = Cluster::inWorkspace($app, $workspace, fn ($pdo) => Cluster::rows($pdo, 'select access_delete_member_attributes(?::uuid) as n', [$membership])[0]['n']);

    expect($removed)->toBe(0)
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from user_attributes')[0]['n'])->toBe(1);
});

it('keeps the canary out of Postgres in the clear, logs, audit, outbox, error messages and Inertia props', function () {
    $workspace = Cluster::workspace('Acme');
    uaAdmin($workspace);
    [, $bo] = uaMember($workspace, 'bo@example.test');
    uaKey('region', 'Region');

    $set = uaSet($bo, ['region' => UA_CANARY])->assertOk();
    $bad = uaSet($bo, ['region' => UA_CANARY.str_repeat('x', 300)])->assertStatus(422);
    $own = test()->getJson('/api/v1/admin/user-attributes', UA_HEADERS)->assertOk();
    $members = test()->get('/admin/users', ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))]);

    $dump = '';

    foreach (['user_attributes', 'user_attribute_keys', 'audit_events', 'outbox_events', 'outbox_consumptions'] as $table) {
        foreach (Cluster::rows(Cluster::superuser(), "select t::text as row from {$table} t") as $row) {
            $dump .= $row['row'];
        }
    }

    expect($dump)->not->toContain(UA_CANARY)
        ->and(strtolower($dump))->not->toContain(bin2hex(UA_CANARY))
        ->and($set->getContent())->not->toContain(UA_CANARY)
        ->and($bad->getContent())->not->toContain('CANARY')
        ->and($own->getContent())->not->toContain(UA_CANARY)
        ->and($members->getContent())->not->toContain(UA_CANARY)
        ->and(implode("\n", $this->logged))->not->toContain(UA_CANARY);
});

it('refuses a label with a control or zero-width character, or a key id of the wrong shape, in the database', function (string $keyId, string $label) {
    $workspace = Cluster::workspace('Acme');

    expect(fn () => Cluster::superuser()->prepare('INSERT INTO user_attribute_keys (id, workspace_id, key_id, label, value_type, revision, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, now(), now())')
        ->execute([(string) Str::uuid7(), $workspace, $keyId, $label, 'text']))->toThrow(PDOException::class);
})->with([
    'zero width' => ['good', "Re\u{200B}gion"],
    'line separator' => ['good', "Re\u{2028}gion"],
    'control' => ['good', "Re\x07gion"],
    'bad key id' => ['Bad', 'Region'],
]);

it('finds an underscored key id by search and treats percent and underscore literally', function () {
    $workspace = Cluster::workspace('Acme');
    uaAdmin($workspace);
    uaKey('region_code', 'Code');
    uaKey('regionxcode', 'Other');

    $search = fn (string $q) => array_column(test()->getJson('/api/v1/admin/user-attributes?q='.urlencode($q), UA_HEADERS)->json('data'), 'key_id');

    expect($search('region_code'))->toBe(['region_code'])
        ->and($search('%'))->toBe([])
        ->and($search('region_'))->toBe(['region_code']);
});

it('ties a value to a membership and a key of its own Workspace', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $keyA = Cluster::seedAttributeKey($a);
    $keyB = Cluster::seedAttributeKey($b);
    [, $memberA] = uaMember($a, 'a@example.test');
    [, $memberB] = uaMember($b, 'b@example.test');
    $insert = fn (string $ws, string $member, string $key) => fn () => Cluster::superuser()->prepare("INSERT INTO user_attributes (id, workspace_id, membership_id, attribute_key_id, value_ciphertext, value_blind_index, blind_index_version, updated_at) VALUES (?, ?, ?, ?, decode('00', 'hex'), 'x', 1, now())")->execute([(string) Str::uuid7(), $ws, $member, $key]);

    expect($insert($a, $memberB, $keyA))->toThrow(PDOException::class, 'user_attributes_membership_same_workspace')
        ->and($insert($a, $memberA, $keyB))->toThrow(PDOException::class, 'user_attributes_key_same_workspace');
});

it('rolls back the value when the audit write fails', function () {
    $workspace = Cluster::workspace('Acme');
    uaAdmin($workspace);
    [, $bo] = uaMember($workspace, 'bo@example.test');
    uaKey('region', 'Region');

    $audit = Mockery::mock(Audit::class);
    $audit->shouldReceive('record')->andThrow(new RuntimeException('audit down'));
    app()->instance(Audit::class, $audit);

    uaSet($bo, ['region' => 'North'])->assertStatus(500);

    expect(uaStored($bo))->toBe([])
        ->and(uaEvents('access.attribute.changed'))->toBe([]);
});

it('rolls back the key when the audit write fails', function () {
    $workspace = Cluster::workspace('Acme');
    uaAdmin($workspace);

    $audit = Mockery::mock(Audit::class);
    $audit->shouldReceive('record')->andThrow(new RuntimeException('audit down'));
    app()->instance(Audit::class, $audit);

    uaKey('region', 'Region')->assertStatus(500);

    expect(Cluster::rows(Cluster::superuser(), 'select count(*) as n from user_attribute_keys')[0]['n'])->toBe(0);
});
