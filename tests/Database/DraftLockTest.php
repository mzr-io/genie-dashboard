<?php

use App\Models\User;
use App\Platform\Tenancy\TenantCache;
use Illuminate\Support\Facades\Cache;
use Tests\Database\Support\Cluster;

// Story 3.6 against the real PostgreSQL: the soft lock on a Draft block reuses the Platform\EditLock kernel. The first editor holds it,
// the second is read-only and every write of theirs is a 423; a take-over saves the holder's work first and raises `lock_epoch`;
// the lock never grants access and is workspace-prefixed. Helpers come from DraftBlocksTest.
beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
    config([
        'dashflow.tunables.timeouts.edit_lock_ttl.value' => '60',
        'dashflow.edit_lock.flush_timeout_seconds.value' => '30',
    ]);
});

afterEach(fn () => $this->travelBack());

function dlCall(string $method, string $id, string $path = '', array $body = [], array $headers = [])
{
    return test()->json($method, BD_URL."/{$id}/lock{$path}", $body, $headers + BD_HEADERS);
}

function dlSave(string $id, int $revision, int $epoch, ?string $token, array $overrides = [])
{
    return bdSave($id, $revision, $epoch, $overrides + ($token === null ? [] : ['lock_token' => $token]));
}

function dlAs(int $user, string $workspace): void
{
    test()->flushSession();

    if (auth()->guard('web')->id() !== $user) {
        auth()->forgetGuards();
    }

    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspace, 'area' => 'admin']);
}

/** @return array{workspace: string, id: string, ada: int, bob: int, adaM: string, bobM: string} */
function dlWorld(): array
{
    $workspace = Cluster::workspace('Acme');
    [$ada, $adaM] = bdAdmin($workspace);
    [$bob, $bobM] = bdMember($workspace, 'bob@example.test');
    dlAs($ada, $workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');

    return ['workspace' => $workspace, 'id' => $id, 'ada' => $ada, 'bob' => $bob, 'adaM' => $adaM, 'bobM' => $bobM];
}

function dlEpoch(string $id): int
{
    return (int) Cluster::rows(Cluster::superuser(), 'select lock_epoch from block_versions where id = ?', [$id])[0]['lock_epoch'];
}

it('grants a free Draft, shows a held one read-only, and refuses the second editor\'s writes with 423', function () {
    $w = dlWorld();
    $a = dlCall('POST', $w['id'])->assertOk()->assertJsonPath('data.status', 'granted')->assertJsonPath('data.epoch', 1)->json('data');

    dlAs($w['bob'], $w['workspace']);
    dlCall('POST', $w['id'])->assertOk()->assertJsonPath('data.status', 'held')->assertJsonPath('data.holder.name', 'ada@example.test');
    // B has no token and a guessed one: both are refused, nothing is written.
    dlSave($w['id'], 1, 1, null, ['name' => 'Bob'])->assertStatus(423)->assertJsonPath('error.code', 'platform.edit_lock_lost');
    dlSave($w['id'], 1, 1, str_repeat('a', 32), ['name' => 'Bob'])->assertStatus(423);
    expect(bdRows()[0])->toMatchArray(['name' => 'Revenue today', 'revision' => 1]);

    dlAs($w['ada'], $w['workspace']);
    dlSave($w['id'], 1, 1, $a['token'], ['name' => 'Ada'])->assertOk()->assertJsonPath('data.revision', 2);
});

it('treats a second tab of the same Admin as another editor', function () {
    $w = dlWorld();
    $first = dlCall('POST', $w['id'])->json('data');
    // A new tab acquires and replaces the lock: the first tab's token no longer holds it.
    $second = dlCall('POST', $w['id'])->assertJsonPath('data.status', 'granted')->json('data');

    dlSave($w['id'], 1, 1, $first['token'])->assertStatus(423);
    dlSave($w['id'], 1, 1, $second['token'])->assertOk();
});

it('leaves the epoch check alone when the lock is disabled', function () {
    config(['dashflow.tunables.timeouts.edit_lock_ttl.value' => null]);
    $w = dlWorld();

    dlCall('POST', $w['id'])->assertOk()->assertJsonPath('data.enabled', false);
    dlSave($w['id'], 1, 1, null)->assertOk()->assertJsonPath('data.revision', 2);
    dlSave($w['id'], 2, 5, null)->assertStatus(423);
});

it('frees the lock when the holder releases by beacon or goes idle past the TTL', function () {
    $w = dlWorld();
    $a = dlCall('POST', $w['id'])->json('data');
    dlCall('PUT', $w['id'], '', ['token' => $a['token']], ['X-Background' => '1'])->assertOk()->assertJsonPath('data.status', 'granted');

    dlCall('POST', $w['id'], '/release', ['token' => $a['token'], '_token' => 'x'])->assertOk()->assertJsonPath('data.released', true);
    dlAs($w['bob'], $w['workspace']);
    $b = dlCall('POST', $w['id'])->assertJsonPath('data.status', 'granted')->json('data');

    $this->travel(61)->seconds();
    dlAs($w['ada'], $w['workspace']);
    dlCall('POST', $w['id'])->assertOk()->assertJsonPath('data.status', 'granted');
    expect($b['token'])->not->toBe('');
});

it('takes over after the holder flushes: B loads the post-flush revision, A is told, and A\'s old-epoch write is a 423', function () {
    $w = dlWorld();
    $a = dlCall('POST', $w['id'])->json('data');

    dlAs($w['bob'], $w['workspace']);
    $bobToken = dlCall('POST', $w['id'], '/takeover')->assertJsonPath('data.status', 'waiting')->json('data.token');

    dlAs($w['ada'], $w['workspace']);
    dlCall('PUT', $w['id'], '', ['token' => $a['token']], ['X-Background' => '1'])->assertJsonPath('data.flush_requested', true);
    dlSave($w['id'], 1, 1, $a['token'], ['name' => 'Ada flushed'])->assertOk();
    dlCall('POST', $w['id'], '/flush', ['token' => $a['token']])->assertOk()
        ->assertJsonPath('data.status', 'taken_over')->assertJsonPath('data.flush_acknowledged', true);
    expect(dlEpoch($w['id']))->toBe(2);

    dlAs($w['bob'], $w['workspace']);
    dlCall('GET', $w['id'], '/takeover', [], ['X-Background' => '1', 'X-Lock-Token' => $bobToken])->assertJsonPath('data.status', 'granted')->assertJsonPath('data.epoch', 2);
    $this->getJson(BD_URL."/{$w['id']}", BD_HEADERS)->assertJsonPath('data.name', 'Ada flushed')->assertJsonPath('data.lock_epoch', 2);

    dlAs($w['ada'], $w['workspace']);
    dlSave($w['id'], 2, 1, $a['token'], ['name' => 'Late'])->assertStatus(423);
    expect(bdRows()[0]['name'])->toBe('Ada flushed');
});

it('completes a take-over without a flush when the holder is offline, and the holder is told it was not saved', function () {
    $w = dlWorld();
    $a = dlCall('POST', $w['id'])->json('data');

    dlAs($w['bob'], $w['workspace']);
    dlCall('POST', $w['id'], '/takeover')->assertJsonPath('data.status', 'waiting');
    $this->travel(31)->seconds();
    dlCall('POST', $w['id'], '/takeover')->assertOk();

    dlAs($w['ada'], $w['workspace']);
    dlCall('PUT', $w['id'], '', ['token' => $a['token']])->assertJsonPath('data.status', 'taken_over')->assertJsonPath('data.flush_acknowledged', false);
    expect(dlEpoch($w['id']))->toBe(2);
});

it('records the take-over for audit and keeps the lock key workspace-prefixed', function () {
    $w = dlWorld();
    $a = dlCall('POST', $w['id'])->json('data');
    dlAs($w['bob'], $w['workspace']);
    dlCall('POST', $w['id'], '/takeover')->assertOk();
    dlAs($w['ada'], $w['workspace']);
    dlCall('POST', $w['id'], '/flush', ['token' => $a['token']])->assertOk();

    $events = Cluster::rows(Cluster::superuser(), "select * from outbox_events where type in ('platform.edit_lock.flush_requested', 'platform.edit_lock.taken')");
    expect($events)->toHaveCount(2)->and($events[0]['subject'])->toBe('draft_block:'.$w['id']);

    dlAs($w['bob'], $w['workspace']);
    dlCall('POST', $w['id'])->assertOk();
    expect(app(TenantCache::class)->get($w['workspace'], 'edit_lock:draft_block:'.$w['id']))->not->toBeNull()
        ->and(Cache::get('edit_lock:draft_block:'.$w['id']))->toBeNull();
});

it('never lets a lock grant access: 403 without blocks.edit, 404 for a Draft of another Workspace or a discarded one', function () {
    $w = dlWorld();
    $other = Cluster::workspace('Globex');
    [$eve] = bdMember($other, 'eve@example.test');
    [$nope] = bdMember($w['workspace'], 'nope@example.test', 'admin', []);

    dlAs($eve, $other);
    dlCall('POST', $w['id'])->assertNotFound();
    dlCall('POST', $w['id'], '/takeover')->assertNotFound();
    dlCall('POST', $w['id'], '/release', ['token' => 'x'])->assertNotFound();

    dlAs($nope, $w['workspace']);
    dlCall('POST', $w['id'])->assertForbidden();

    dlAs($w['ada'], $w['workspace']);
    $a = dlCall('POST', $w['id'])->json('data');
    $this->deleteJson(BD_URL."/{$w['id']}", ['lock_token' => $a['token'], 'lock_epoch' => 1], BD_HEADERS)->assertOk();
    dlCall('POST', $w['id'])->assertNotFound();
});

it('refuses a discard without the lock claim with 423 and changes nothing, and discards for the holder', function () {
    $w = dlWorld();
    $a = dlCall('POST', $w['id'])->json('data');

    dlAs($w['bob'], $w['workspace']);
    $this->deleteJson(BD_URL."/{$w['id']}", [], BD_HEADERS)->assertStatus(423)->assertJsonPath('error.code', 'platform.edit_lock_lost');
    $this->deleteJson(BD_URL."/{$w['id']}", ['lock_token' => str_repeat('a', 32), 'lock_epoch' => 1], BD_HEADERS)->assertStatus(423);

    dlAs($w['ada'], $w['workspace']);
    $this->deleteJson(BD_URL."/{$w['id']}", ['lock_token' => $a['token'], 'lock_epoch' => 9], BD_HEADERS)->assertStatus(423);
    expect(bdRows()[0]['state'])->toBe('draft');
    $this->deleteJson(BD_URL."/{$w['id']}", ['lock_token' => $a['token'], 'lock_epoch' => 1], BD_HEADERS)->assertOk()->assertJsonPath('data.state', 'discarded');
});

it('discards as before when the lock is disabled', function () {
    config(['dashflow.tunables.timeouts.edit_lock_ttl.value' => null]);
    $w = dlWorld();

    $this->deleteJson(BD_URL."/{$w['id']}", [], BD_HEADERS)->assertOk();
});
