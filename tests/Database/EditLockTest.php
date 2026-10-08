<?php

use App\Models\User;
use App\Platform\Tenancy\TenantCache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 2.8 against the real PostgreSQL: the soft lock on the Data source form. The first editor holds a lock in the cache, the
// others see a read-only form with a take-over option, a take-over saves the holder's work first and raises `lock_epoch`, and a
// write made with an old epoch is refused with 423 before any secret is sealed. Without a TTL the lock is off and `revision`
// alone protects the form.
const LK_URL = '/api/v1/admin/data-sources';
const LK_HEADERS = ['Referer' => 'http://localhost:8000'];
// A Referer on a stateful domain, so the request starts the session and the idle clock applies.
const LK_STATEFUL = ['Referer' => 'http://127.0.0.1:8000'];
const LK_PASSWORD = 'admin-password-1';
const LK_CANARY = 'CANARY-lock-s3cr3t-77';

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();

    $pair = sodium_crypto_box_keypair();
    config([
        'dashflow.secrets.cred_public_key.value' => base64_encode(sodium_crypto_box_publickey($pair)),
        'dashflow.secrets.cred_key_version.value' => '1',
        'dashflow.tunables.timeouts.edit_lock_ttl.value' => '60',
        'dashflow.edit_lock.flush_timeout_seconds.value' => null,
    ]);
});

afterEach(fn () => $this->travelBack());

/** @return array{0: int, 1: string} user ID and membership ID */
function lkMember(string $workspace, string $email, array $permissions = ['data_sources.manage']): array
{
    $user = Cluster::user($email);
    Cluster::superuser()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([Hash::make(LK_PASSWORD), $user]);
    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspace, $user, 'admin', 'active']);

    foreach ($permissions as $permission) {
        Cluster::superuser()->prepare('INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
            ->execute([(string) Str::uuid7(), $workspace, $membership, $permission]);
    }

    return [$user, $membership];
}

function lkAs(int $user, string $workspace, array $session = []): void
{
    test()->flushSession();

    // A different person than the last request's: the guard must not keep the previous one (or its password hash in the session).
    if (auth()->guard('web')->id() !== $user) {
        auth()->forgetGuards();
    }

    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspace, 'area' => 'admin'] + $session);
}

function lkBody(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Sales API', 'base_url' => 'https://api.example.com/v1', 'headers' => [], 'timeout_seconds' => null,
        'max_response_bytes' => null, 'max_pages' => null, 'live_capable' => false, 'auth_type' => 'none',
    ];
}

/**
 * A Workspace with two Admins (Ada and Bob) and one Data Source; the session is Ada's.
 *
 * @return array{workspace: string, id: string, ada: int, bob: int, adaMembership: string, bobMembership: string}
 */
function lkWorld(): array
{
    $workspace = Cluster::workspace('Acme');
    [$ada, $adaMembership] = lkMember($workspace, 'ada@example.test');
    [$bob, $bobMembership] = lkMember($workspace, 'bob@example.test');
    Cluster::seedHostEntry($workspace, 'api.example.com');
    lkAs($ada, $workspace);
    $id = test()->postJson(LK_URL, lkBody(), LK_HEADERS)->assertCreated()->json('data.data_source_id');

    return ['workspace' => $workspace, 'id' => $id, 'ada' => $ada, 'bob' => $bob, 'adaMembership' => $adaMembership, 'bobMembership' => $bobMembership];
}

function lkCall(string $method, string $id, string $path = '', array $body = [], array $headers = [])
{
    return test()->json($method, LK_URL."/{$id}/lock{$path}", $body, $headers + LK_HEADERS);
}

function lkSave(string $id, int $revision, int $epoch, string $token, array $overrides = [])
{
    return test()->putJson(LK_URL."/{$id}", lkBody($overrides) + ['revision' => $revision, 'lock_epoch' => $epoch, 'lock_token' => $token], LK_HEADERS);
}

function lkEpoch(string $id): int
{
    return (int) Cluster::rows(Cluster::superuser(), 'select lock_epoch from data_sources where id = ?', [$id])[0]['lock_epoch'];
}

function lkRow(string $id): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from data_sources where id = ?', [$id])[0];
}

function lkEntry(string $workspace, string $id): mixed
{
    return app(TenantCache::class)->get($workspace, "edit_lock:data_source:{$id}");
}

it('answers {enabled: false} when the TTL is unset and leaves the form to its revision check', function () {
    config(['dashflow.tunables.timeouts.edit_lock_ttl.value' => null]);
    $w = lkWorld();

    lkCall('POST', $w['id'])->assertOk()->assertJsonPath('data.enabled', false);
    lkCall('PUT', $w['id'], '', ['token' => 'x'])->assertOk()->assertJsonPath('data.enabled', false);
    lkCall('POST', $w['id'], '/takeover')->assertOk()->assertJsonPath('data.enabled', false);
    expect(lkEntry($w['workspace'], $w['id']))->toBeNull();

    // No claim is needed: the save works with the revision alone, and a stale one is still a 409 with the current state.
    test()->putJson(LK_URL."/{$w['id']}", lkBody(['name' => 'Renamed']) + ['revision' => 1], LK_HEADERS)->assertOk()->assertJsonPath('data.revision', 2);
    test()->putJson(LK_URL."/{$w['id']}", lkBody(['name' => 'Again']) + ['revision' => 1], LK_HEADERS)
        ->assertStatus(409)->assertJsonPath('error.code', 'connector.revision_conflict')->assertJsonPath('current.data.name', 'Renamed');
});

it('grants the first editor the lock at epoch 1 and shows the second a read-only holder', function () {
    $w = lkWorld();

    $a = lkCall('POST', $w['id'])->assertOk();
    expect($a->json('data'))->toMatchArray(['enabled' => true, 'status' => 'granted', 'epoch' => 1, 'ttl_seconds' => 60, 'flush_requested' => false])
        ->and($a->json('data.token'))->toMatch('/^[0-9a-f]{32}$/')
        ->and($a->json('data.csrf_token'))->toBeString()
        ->and(lkEntry($w['workspace'], $w['id']))->toMatchArray(['membership_id' => $w['adaMembership'], 'epoch' => 1, 'name' => 'ada@example.test']);

    lkAs($w['bob'], $w['workspace']);
    $b = lkCall('POST', $w['id'])->assertOk();
    expect($b->json('data'))->toMatchArray(['enabled' => true, 'status' => 'held', 'holder' => ['name' => 'ada@example.test', 'since' => $b->json('data.holder.since')]])
        ->and($b->json('data.holder.since'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($b->json('data'))->not->toHaveKey('token')
        ->and($b->getContent())->not->toContain($a->json('data.token'))
        ->and(lkEntry($w['workspace'], $w['id'])['membership_id'])->toBe($w['adaMembership']);
});

it('lets the same person replace their own lock from another tab, which then loses it', function () {
    $w = lkWorld();

    $first = lkCall('POST', $w['id'])->json('data.token');
    $second = lkCall('POST', $w['id'])->assertOk()->assertJsonPath('data.status', 'granted')->json('data.token');

    expect($second)->not->toBe($first);
    lkCall('PUT', $w['id'], '', ['token' => $first])->assertOk()->assertJsonPath('data.status', 'lost');
    lkCall('PUT', $w['id'], '', ['token' => $second])->assertOk()->assertJsonPath('data.status', 'granted');
});

it('renews the TTL on a heartbeat and frees the lock for the next reload when the holder is idle past it', function () {
    $w = lkWorld();
    $token = lkCall('POST', $w['id'])->json('data.token');

    $this->travel(40)->seconds();
    lkCall('PUT', $w['id'], '', ['token' => $token], ['X-Background' => '1'])->assertOk()->assertJsonPath('data.status', 'granted');

    // 40 + 40 seconds after the acquire, but only 40 after the heartbeat: still held.
    $this->travel(40)->seconds();
    lkAs($w['bob'], $w['workspace']);
    lkCall('POST', $w['id'])->assertJsonPath('data.status', 'held');

    $this->travel(30)->seconds();
    lkCall('POST', $w['id'])->assertJsonPath('data.status', 'granted')->assertJsonPath('data.epoch', 1);

    // Ada's idle tab learns it lost the lock (no notice: nobody took it over), and a save from it is refused.
    lkAs($w['ada'], $w['workspace']);
    lkCall('PUT', $w['id'], '', ['token' => $token])->assertJsonPath('data.status', 'lost');
    lkSave($w['id'], 1, 1, $token, ['name' => 'Late'])->assertStatus(423)->assertJsonPath('error.code', 'platform.edit_lock_lost');
    expect(lkRow($w['id'])['name'])->toBe('Sales API');
});

it('releases the lock only for the token that holds it, with the token in the body (a beacon)', function () {
    $w = lkWorld();
    $token = lkCall('POST', $w['id'])->json('data.token');

    lkCall('POST', $w['id'], '/release', ['token' => 'not-the-token'])->assertOk()->assertJsonPath('data.released', false);
    expect(lkEntry($w['workspace'], $w['id']))->not->toBeNull();

    lkAs($w['bob'], $w['workspace']);
    lkCall('POST', $w['id'], '/release', ['token' => $token])->assertOk()->assertJsonPath('data.released', false);
    lkCall('POST', $w['id'])->assertJsonPath('data.status', 'held');

    lkAs($w['ada'], $w['workspace']);
    lkCall('POST', $w['id'], '/release', ['token' => $token, '_token' => 'whatever'])->assertOk()->assertJsonPath('data.released', true);
    expect(lkEntry($w['workspace'], $w['id']))->toBeNull();

    lkAs($w['bob'], $w['workspace']);
    lkCall('POST', $w['id'])->assertJsonPath('data.status', 'granted');
});

it('takes over after the holder flushes: its work is saved, the epoch rises, it is told, and its later save gets 423 with no secret stored', function () {
    config(['dashflow.edit_lock.flush_timeout_seconds.value' => '30']);
    $w = lkWorld();
    $a = lkCall('POST', $w['id'])->json('data');

    // Bob asks to take over: the lock is flagged, one outbox event is emitted (IDs and enums), and he waits.
    lkAs($w['bob'], $w['workspace']);
    $request = lkCall('POST', $w['id'], '/takeover')->assertOk();
    $bobToken = $request->json('data.token');
    expect($request->json('data.status'))->toBe('waiting')
        ->and($bobToken)->toMatch('/^[0-9a-f]{32}$/')
        ->and(lkEpoch($w['id']))->toBe(1);

    $events = Cluster::rows(Cluster::superuser(), "select * from outbox_events where type = 'platform.edit_lock.flush_requested'");
    expect($events)->toHaveCount(1)
        ->and($events[0]['subject'])->toBe('data_source:'.$w['id'])
        ->and($events[0]['actor'])->toBe($w['bobMembership'])
        ->and(json_decode($events[0]['data'], true))->toEqualCanonicalizing(['resource_type' => 'data_source', 'resource_id' => $w['id'], 'epoch' => 1, 'requested_by' => $w['bobMembership']]);

    lkCall('GET', $w['id'], '/takeover', [], ['X-Background' => '1', 'X-Lock-Token' => $bobToken])->assertOk()->assertJsonPath('data.status', 'waiting');

    // Ada's heartbeat reports the request; she saves her valid non-secret fields through the normal update, then acknowledges.
    lkAs($w['ada'], $w['workspace']);
    lkCall('PUT', $w['id'], '', ['token' => $a['token']], ['X-Background' => '1'])->assertOk()->assertJsonPath('data.flush_requested', true);
    lkSave($w['id'], 1, 1, $a['token'], ['name' => 'Ada flushed'])->assertOk()->assertJsonPath('data.revision', 2);
    lkCall('POST', $w['id'], '/flush', ['token' => $a['token']])->assertOk()
        ->assertJsonPath('data.status', 'taken_over')->assertJsonPath('data.flush_acknowledged', true)->assertJsonPath('data.taken_over_by.name', 'bob@example.test');
    expect(lkEpoch($w['id']))->toBe(2);

    // The completed take-over leaves a trail: IDs, the epoch and whether the flush was acknowledged, nothing else.
    $taken = Cluster::rows(Cluster::superuser(), "select * from outbox_events where type = 'platform.edit_lock.taken'");
    expect($taken)->toHaveCount(1)
        ->and($taken[0]['subject'])->toBe('data_source:'.$w['id'])
        ->and($taken[0]['actor'])->toBe($w['bobMembership'])
        ->and(json_decode($taken[0]['data'], true))->toEqualCanonicalizing(['resource_type' => 'data_source', 'resource_id' => $w['id'], 'taken_by' => $w['bobMembership'], 'former_holder' => $w['adaMembership'], 'flush_acknowledged' => true, 'epoch' => 2]);

    // Bob is granted the lock at the new epoch and edits the post-flush revision.
    lkAs($w['bob'], $w['workspace']);
    $granted = lkCall('GET', $w['id'], '/takeover', [], ['X-Background' => '1', 'X-Lock-Token' => $bobToken])->assertOk();
    expect($granted->json('data'))->toMatchArray(['status' => 'granted', 'token' => $bobToken, 'epoch' => 2]);
    test()->getJson(LK_URL."/{$w['id']}", LK_HEADERS)->assertJsonPath('data.revision', 2)->assertJsonPath('data.lock_epoch', 2)->assertJsonPath('data.name', 'Ada flushed');

    // Ada's later save, with her old epoch and an unsaved secret value: 423, nothing written, nothing sealed.
    lkAs($w['ada'], $w['workspace']);
    $late = lkSave($w['id'], 2, 1, $a['token'], ['name' => 'Too late', 'auth_type' => 'bearer', 'secrets' => ['bearer_token' => LK_CANARY], 'confirm_password' => LK_PASSWORD])
        ->assertStatus(423)->assertJsonPath('error.code', 'platform.edit_lock_lost')
        ->assertJsonPath('current.data.revision', 2)->assertJsonPath('current.data.lock_epoch', 2);
    expect(lkRow($w['id']))->toMatchArray(['name' => 'Ada flushed', 'revision' => 2, 'auth_type' => 'none'])
        ->and(Cluster::rows(Cluster::superuser(), 'select * from secrets'))->toBe([])
        ->and($late->getContent())->not->toContain(LK_CANARY);

    // The holder's notice was consumed by the acknowledgement; Bob saves normally.
    lkAs($w['bob'], $w['workspace']);
    lkSave($w['id'], 2, 2, $bobToken, ['name' => 'Bob edits'])->assertOk()->assertJsonPath('data.revision', 3);
});

it('completes at once when no flush timeout is set and tells the holder, without the saved claim', function () {
    $w = lkWorld();
    $a = lkCall('POST', $w['id'])->json('data');

    lkAs($w['bob'], $w['workspace']);
    $taken = lkCall('POST', $w['id'], '/takeover')->assertOk();
    expect($taken->json('data'))->toMatchArray(['status' => 'granted', 'epoch' => 2])
        ->and(lkEpoch($w['id']))->toBe(2);

    lkAs($w['ada'], $w['workspace']);
    lkCall('PUT', $w['id'], '', ['token' => $a['token']])->assertOk()
        ->assertJsonPath('data.status', 'taken_over')->assertJsonPath('data.flush_acknowledged', false);
    // One-shot: the next heartbeat only says the lock is gone.
    lkCall('PUT', $w['id'], '', ['token' => $a['token']])->assertJsonPath('data.status', 'lost');
    lkSave($w['id'], 1, 1, $a['token'], ['name' => 'Discarded'])->assertStatus(423);
});

it('completes after the flush timeout passes without an acknowledgement', function () {
    config(['dashflow.edit_lock.flush_timeout_seconds.value' => '10']);
    $w = lkWorld();
    $a = lkCall('POST', $w['id'])->json('data');

    lkAs($w['bob'], $w['workspace']);
    $bobToken = lkCall('POST', $w['id'], '/takeover')->assertJsonPath('data.status', 'waiting')->json('data.token');

    $this->travel(5)->seconds();
    lkCall('GET', $w['id'], '/takeover', [], ['X-Lock-Token' => $bobToken])->assertJsonPath('data.status', 'waiting');

    $this->travel(6)->seconds();
    lkCall('GET', $w['id'], '/takeover', [], ['X-Lock-Token' => $bobToken])->assertJsonPath('data.status', 'granted')->assertJsonPath('data.epoch', 2);
    expect(lkEpoch($w['id']))->toBe(2);

    lkAs($w['ada'], $w['workspace']);
    lkCall('PUT', $w['id'], '', ['token' => $a['token']])->assertJsonPath('data.status', 'taken_over')->assertJsonPath('data.flush_acknowledged', false);
    lkSave($w['id'], 1, 1, $a['token'])->assertStatus(423);
});

it('lets the holder\'s own heartbeat complete a take-over whose flush timeout has passed', function () {
    config(['dashflow.edit_lock.flush_timeout_seconds.value' => '10']);
    $w = lkWorld();
    $a = lkCall('POST', $w['id'])->json('data');

    lkAs($w['bob'], $w['workspace']);
    lkCall('POST', $w['id'], '/takeover')->assertJsonPath('data.status', 'waiting');

    $this->travel(11)->seconds();
    lkAs($w['ada'], $w['workspace']);
    lkCall('PUT', $w['id'], '', ['token' => $a['token']])->assertJsonPath('data.status', 'taken_over')->assertJsonPath('data.flush_acknowledged', false);
    expect(lkEpoch($w['id']))->toBe(2);
});

it('gives the lock to a taker whose holder released or expired while waiting, and refuses a second pending request', function () {
    config(['dashflow.edit_lock.flush_timeout_seconds.value' => '30']);
    $w = lkWorld();
    [$cy] = lkMember($w['workspace'], 'cy@example.test');
    $a = lkCall('POST', $w['id'])->json('data');

    lkAs($w['bob'], $w['workspace']);
    $bobToken = lkCall('POST', $w['id'], '/takeover')->assertJsonPath('data.status', 'waiting')->json('data.token');

    lkAs($cy, $w['workspace']);
    lkCall('POST', $w['id'], '/takeover')->assertOk()->assertJsonPath('data.status', 'held')->assertJsonPath('data.holder.name', 'ada@example.test');

    // Ada closes the tab: the beacon frees the lock, and Bob's next poll finds it free.
    lkAs($w['ada'], $w['workspace']);
    lkCall('POST', $w['id'], '/release', ['token' => $a['token']])->assertJsonPath('data.released', true);
    lkAs($w['bob'], $w['workspace']);
    // Free now: the poll reports it and never grants; Bob acquires.
    lkCall('GET', $w['id'], '/takeover', [], ['X-Lock-Token' => $bobToken])->assertJsonPath('data.status', 'free');
    expect(lkEntry($w['workspace'], $w['id']))->toBeNull();
    lkCall('POST', $w['id'])->assertJsonPath('data.status', 'granted');
    // A poll with a token nobody holds, while someone else does, is just a read-only answer.
    lkAs($cy, $w['workspace']);
    lkCall('GET', $w['id'], '/takeover', [], ['X-Lock-Token' => str_repeat('a', 32)])->assertJsonPath('data.status', 'held');
});

it('refuses a save that carries no claim, a stale epoch or a token that does not hold the lock, and writes nothing', function () {
    $w = lkWorld();
    $a = lkCall('POST', $w['id'])->json('data');

    // No claim at all while the lock is enabled.
    test()->putJson(LK_URL."/{$w['id']}", lkBody(['name' => 'No claim']) + ['revision' => 1], LK_HEADERS)->assertStatus(423)->assertJsonPath('error.code', 'platform.edit_lock_lost');
    // A made-up token.
    lkSave($w['id'], 1, 1, 'f'.str_repeat('0', 31), ['name' => 'Forged'])->assertStatus(423);
    // The right token at the wrong epoch.
    lkSave($w['id'], 1, 2, $a['token'], ['name' => 'Epoch'])->assertStatus(423);
    // Another Admin's session cannot use the holder's token.
    lkAs($w['bob'], $w['workspace']);
    lkSave($w['id'], 1, 1, $a['token'], ['name' => 'Stolen'])->assertStatus(423);

    expect(lkRow($w['id']))->toMatchArray(['name' => 'Sales API', 'revision' => 1]);

    lkAs($w['ada'], $w['workspace']);
    lkSave($w['id'], 1, 1, $a['token'], ['name' => 'Holder'])->assertOk();
});

it('checks an epoch that a save carries even when the lock is off, and the revision only after it', function () {
    config(['dashflow.tunables.timeouts.edit_lock_ttl.value' => null]);
    $w = lkWorld();

    test()->putJson(LK_URL."/{$w['id']}", lkBody(['name' => 'Stale']) + ['revision' => 1, 'lock_epoch' => 5], LK_HEADERS)->assertStatus(423)->assertJsonPath('current.data.lock_epoch', 1);
    test()->putJson(LK_URL."/{$w['id']}", lkBody(['name' => 'Right']) + ['revision' => 1, 'lock_epoch' => 1], LK_HEADERS)->assertOk();
    // Both stale: the lost lock is what the holder needs to hear.
    test()->putJson(LK_URL."/{$w['id']}", lkBody(['name' => 'Both']) + ['revision' => 1, 'lock_epoch' => 9], LK_HEADERS)->assertStatus(423);
});

it('lets one of two writers on the same revision win and refuses the other with 409 and the current state', function () {
    $w = lkWorld();
    $a = lkCall('POST', $w['id'])->json('data');

    lkSave($w['id'], 1, 1, $a['token'], ['name' => 'First'])->assertOk()->assertJsonPath('data.revision', 2);
    lkSave($w['id'], 1, 1, $a['token'], ['name' => 'Second'])->assertStatus(409)
        ->assertJsonPath('error.code', 'connector.revision_conflict')->assertJsonPath('current.data.name', 'First')->assertJsonPath('current.data.revision', 2);
    expect(lkRow($w['id'])['name'])->toBe('First');
});

it('never lowers lock_epoch', function () {
    $w = lkWorld();
    Cluster::superuser()->prepare('UPDATE data_sources SET lock_epoch = 3 WHERE id = ?')->execute([$w['id']]);

    expect(fn () => Cluster::superuser()->prepare('UPDATE data_sources SET lock_epoch = 2 WHERE id = ?')->execute([$w['id']]))->toThrow(PDOException::class)
        ->and(fn () => Cluster::superuser()->prepare('UPDATE data_sources SET lock_epoch = 0 WHERE id = ?')->execute([$w['id']]))->toThrow(PDOException::class)
        ->and(lkEpoch($w['id']))->toBe(3);
});

it('does not extend the auth session on a heartbeat or poll with X-Background, and does on a user action', function () {
    $w = lkWorld();
    $token = lkCall('POST', $w['id'])->json('data.token');
    $past = now()->getTimestamp() - 100;

    lkAs($w['ada'], $w['workspace'], ['last_user_activity' => $past]);
    lkCall('PUT', $w['id'], '', ['token' => $token], LK_STATEFUL + ['X-Background' => '1'])->assertOk();
    expect(session('last_user_activity'))->toBe($past);

    lkCall('GET', $w['id'], '/takeover', [], LK_STATEFUL + ['X-Background' => '1', 'X-Lock-Token' => str_repeat('b', 32)])->assertOk();
    expect(session('last_user_activity'))->toBe($past);
});

it('extends the auth session on a lock call that is a user action', function () {
    $w = lkWorld();
    $past = now()->getTimestamp() - 100;

    lkAs($w['ada'], $w['workspace'], ['last_user_activity' => $past]);
    lkCall('POST', $w['id'], '', [], LK_STATEFUL)->assertOk();
    expect(session('last_user_activity'))->toBeGreaterThan($past);
});

it('never lets a lock grant access: 403 without data_sources.manage, 404 for a Data Source of another Workspace', function () {
    $w = lkWorld();
    [$nobody] = lkMember($w['workspace'], 'nobody@example.test', []);
    $other = Cluster::workspace('Globex');
    [$eve] = lkMember($other, 'eve@example.test');

    lkAs($nobody, $w['workspace']);
    foreach ([['POST', ''], ['PUT', ''], ['POST', '/release'], ['POST', '/takeover'], ['GET', '/takeover'], ['POST', '/flush']] as [$method, $path]) {
        lkCall($method, $w['id'], $path, ['token' => 'x'])->assertForbidden();
    }
    expect(lkEntry($w['workspace'], $w['id']))->toBeNull();

    // Eve is an Admin of Globex: Acme's Data Source does not exist for her, and nothing is stored under either Workspace.
    lkAs($eve, $other);
    foreach ([['POST', ''], ['PUT', ''], ['POST', '/release'], ['POST', '/takeover'], ['GET', '/takeover'], ['POST', '/flush']] as [$method, $path]) {
        lkCall($method, $w['id'], $path, ['token' => 'x'])->assertNotFound();
    }
    expect(lkEntry($other, $w['id']))->toBeNull()->and(lkEntry($w['workspace'], $w['id']))->toBeNull();
});

it('keeps the lock token out of logs, audit and the stored Data Source', function () {
    $logFile = tempnam(sys_get_temp_dir(), 'dashflow-log');
    config(['logging.default' => 'single', 'logging.channels.single.path' => $logFile]);
    $w = lkWorld();
    $a = lkCall('POST', $w['id'])->json('data');
    lkSave($w['id'], 1, 1, $a['token'], ['name' => 'Saved'])->assertOk();
    lkCall('POST', $w['id'], '/release', ['token' => $a['token']])->assertOk();

    $everything = json_encode([
        Cluster::rows(Cluster::superuser(), 'select * from audit_events'),
        Cluster::rows(Cluster::superuser(), 'select * from outbox_events'),
        Cluster::rows(Cluster::superuser(), 'select * from data_sources'),
        (string) file_get_contents($logFile),
    ], JSON_THROW_ON_ERROR);
    @unlink($logFile);

    expect($everything)->not->toContain($a['token'])->not->toContain($a['csrf_token']);
});

it('withdraws a pending take-over when the taker releases with its own token, so the holder is no longer asked to flush', function () {
    config(['dashflow.edit_lock.flush_timeout_seconds.value' => '30']);
    $w = lkWorld();
    $a = lkCall('POST', $w['id'])->json('data');

    lkAs($w['bob'], $w['workspace']);
    $bobToken = lkCall('POST', $w['id'], '/takeover')->assertJsonPath('data.status', 'waiting')->json('data.token');

    lkAs($w['ada'], $w['workspace']);
    lkCall('PUT', $w['id'], '', ['token' => $a['token']])->assertJsonPath('data.flush_requested', true);

    lkAs($w['bob'], $w['workspace']);
    lkCall('POST', $w['id'], '/release', ['token' => $bobToken])->assertJsonPath('data.released', true);

    lkAs($w['ada'], $w['workspace']);
    lkCall('PUT', $w['id'], '', ['token' => $a['token']])->assertJsonPath('data.status', 'granted')->assertJsonPath('data.flush_requested', false);
    expect(lkEpoch($w['id']))->toBe(1);
});

it('rolls the lock_epoch migration back and migrates forward again at epoch 1', function () {
    $w = lkWorld();
    $columns = fn (): int => Cluster::rows(Cluster::superuser(), "select count(*) as n from information_schema.columns where table_name = 'data_sources' and column_name = 'lock_epoch'")[0]['n'];

    try {
        // Four steps: the newest migrations are Story 2.12's (user attributes), Story 2.11's (pagination) and Story 2.9's (Endpoints), then this one.
        expect(Artisan::call('migrate:rollback', ['--database' => 'migrator', '--step' => 4, '--force' => true]))->toBe(0)
            ->and($columns())->toBe(0);
    } finally {
        Artisan::call('migrate', ['--database' => 'migrator', '--force' => true]);
    }

    expect($columns())->toBe(1)->and(lkEpoch($w['id']))->toBe(1);
});

it('never grants from the take-over poll: it answers free, refuses an unminted token and ignores one in the URL', function () {
    config(['dashflow.edit_lock.flush_timeout_seconds.value' => '30']);
    $w = lkWorld();

    // Nobody holds the lock: a poll with any well-formed token only reports it free, and nothing is stored.
    lkCall('GET', $w['id'], '/takeover', [], ['X-Lock-Token' => str_repeat('c', 32)])->assertOk()->assertJsonPath('data.status', 'free');
    expect(lkEntry($w['workspace'], $w['id']))->toBeNull();

    foreach (['', 'short', strtoupper(str_repeat('c', 32)), str_repeat('c', 33)] as $bad) {
        lkCall('GET', $w['id'], '/takeover', [], $bad === '' ? [] : ['X-Lock-Token' => $bad])->assertStatus(422);
    }

    // The URL is never read: a token there is no token.
    lkCall('GET', $w['id'], '/takeover?token='.str_repeat('c', 32))->assertStatus(422);
    expect(lkEntry($w['workspace'], $w['id']))->toBeNull();
});

it('answers a retryable 503 with Retry-After when the resource is busy for too long', function () {
    $w = lkWorld();
    $held = app(TenantCache::class)->lock($w['workspace'], 'edit_lock:data_source:'.$w['id'], 30);
    expect($held->get())->toBeTrue();

    try {
        lkCall('POST', $w['id'])->assertStatus(503)->assertHeader('Retry-After', '1');
        lkCall('POST', $w['id'], '/release', ['token' => 'x'])->assertStatus(503);
    } finally {
        $held->release();
    }

    lkCall('POST', $w['id'])->assertOk()->assertJsonPath('data.status', 'granted');
});

it('keeps a third person\'s pending take-over when the holder opens another tab, and lets a taker resume its own request', function () {
    config(['dashflow.edit_lock.flush_timeout_seconds.value' => '30']);
    $w = lkWorld();
    $first = lkCall('POST', $w['id'])->json('data.token');

    lkAs($w['bob'], $w['workspace']);
    $bobToken = lkCall('POST', $w['id'], '/takeover')->assertJsonPath('data.status', 'waiting')->json('data.token');

    // Ada's second tab replaces her lock; Bob's request is still pending and her new token is asked to flush.
    lkAs($w['ada'], $w['workspace']);
    $second = lkCall('POST', $w['id'])->assertJsonPath('data.status', 'granted')->assertJsonPath('data.flush_requested', true)->json('data.token');
    expect($second)->not->toBe($first);
    lkCall('PUT', $w['id'], '', ['token' => $second])->assertJsonPath('data.flush_requested', true);

    // Bob asks again (a retry, or another tab): his own request resumes under a new token, and the old one is dead.
    lkAs($w['bob'], $w['workspace']);
    $resumed = lkCall('POST', $w['id'], '/takeover')->assertOk()->assertJsonPath('data.status', 'waiting')->json('data.token');
    expect($resumed)->not->toBe($bobToken);
    lkCall('GET', $w['id'], '/takeover', [], ['X-Lock-Token' => $resumed])->assertJsonPath('data.status', 'waiting');
    lkCall('GET', $w['id'], '/takeover', [], ['X-Lock-Token' => $bobToken])->assertJsonPath('data.status', 'held');
    expect(Cluster::rows(Cluster::superuser(), "select count(*) as n from outbox_events where type = 'platform.edit_lock.flush_requested'")[0]['n'])->toBe(1);
});

it('gives the lock routes their own throttle bucket: exhausting it does not throttle the Data Source update', function () {
    $w = lkWorld();
    // The named limiter's bucket: the limiter name and the key it chose, hashed.
    $key = md5('data-source-lock'.'data-source-lock|'.$w['ada']);

    for ($i = 0; $i < 600; $i++) {
        RateLimiter::hit($key, 60);
    }

    lkCall('POST', $w['id'])->assertStatus(429);
    lkCall('PUT', $w['id'], '', ['token' => 'x'])->assertStatus(429);
    test()->putJson(LK_URL."/{$w['id']}", lkBody(['name' => 'Still works']) + ['revision' => 1, 'lock_epoch' => 1, 'lock_token' => 'x'], LK_HEADERS)->assertStatus(423);
    RateLimiter::clear($key);
    lkCall('POST', $w['id'])->assertOk();
});
