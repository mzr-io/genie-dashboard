<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Modules\Blocks\Application\PurgeDiscardedDrafts;
use App\Modules\Blocks\Application\PurgeDiscardedDraftsJob;
use App\Modules\Blocks\Contracts\ErrorCode as BlocksErrorCode;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use App\Platform\EditLock\EditLockResources;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 3.3 against the real PostgreSQL: an Admin with `blocks.edit` starts, saves and discards a Draft block; rows live under
// row-level security; the audit event lands in the same transaction; a save is a compare-and-set on `revision` and `lock_epoch`; a
// discarded Draft is hidden at once and its rows are deleted by the `maintenance` role, never by the web tier.
beforeEach(fn () => $this->withoutVite());

const BD_HEADERS = ['Referer' => 'http://localhost:8000'];
const BD_URL = '/api/v1/admin/blocks/drafts';

/** A member of the Workspace; returns [user ID, membership ID]. */
function bdMember(string $workspaceId, string $email, string $role = 'admin', array $permissions = ['blocks.edit']): array
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

/** The Admin holding `blocks.edit`, signed in; returns [user ID, membership ID]. */
function bdAdmin(string $workspaceId, array $permissions = ['blocks.edit'], string $email = 'ada@example.test', string $area = 'admin'): array
{
    [$user, $membership] = bdMember($workspaceId, $email, 'admin', $permissions);
    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspaceId, 'area' => $area]);

    return [$user, $membership];
}

function bdCreate(array $overrides = [])
{
    return test()->postJson(BD_URL, $overrides + ['name' => 'Revenue today', 'type' => 'kpi-card'], BD_HEADERS);
}

function bdSave(string $id, int $revision, int $epoch, array $overrides = [])
{
    return test()->putJson(BD_URL."/{$id}", $overrides + ['name' => 'Revenue', 'type' => 'kpi-card', 'expected_revision' => $revision, 'lock_epoch' => $epoch], BD_HEADERS);
}

function bdRows(string $table = 'block_versions'): array
{
    return Cluster::rows(Cluster::superuser(), "select * from {$table} order by id");
}

function bdAudits(?string $action = null): array
{
    return Cluster::rows(Cluster::superuser(), "select * from audit_events where action like 'blocks.draft.%' and (?::text is null or action = ?) order by occurred_at, id", [$action, $action]);
}

function bdCount(string $table): int
{
    return (int) Cluster::rows(Cluster::superuser(), "select count(*) as n from {$table}")[0]['n'];
}

it('creates a Block and its Draft in one transaction: revision 1, epoch 1, version 1.0, UUIDv7 keys, audited without the name', function () {
    $workspace = Cluster::workspace('Acme');
    [, $owner] = bdAdmin($workspace);

    $response = bdCreate(['name' => '  Revenue today  '])->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
    $id = $response->json('data.draft_id');
    $blockId = $response->json('data.block_id');

    expect($response->json('data'))->toMatchArray([
        'name' => 'Revenue today', 'type' => 'kpi-card', 'category' => 'General', 'version' => '1.0', 'state' => 'draft',
        'revision' => 1, 'lock_epoch' => 1, 'step' => 1, 'owner_membership_id' => $owner,
    ])
        ->and(substr($id, 14, 1))->toBe('7')
        ->and(substr($blockId, 14, 1))->toBe('7');

    $versions = bdRows();
    $blocks = bdRows('blocks');
    $ui = bdRows('draft_ui_state');
    expect($versions)->toHaveCount(1)
        ->and($versions[0])->toMatchArray(['id' => $id, 'block_id' => $blockId, 'workspace_id' => $workspace, 'state' => 'draft', 'version' => '1.0', 'name' => 'Revenue today', 'revision' => 1, 'lock_epoch' => 1, 'created_by_membership_id' => $owner])
        ->and($blocks)->toHaveCount(1)
        ->and($blocks[0])->toMatchArray(['id' => $blockId, 'workspace_id' => $workspace, 'type_key' => 'kpi-card', 'owner_membership_id' => $owner])
        ->and($ui)->toHaveCount(1)
        ->and($ui[0])->toMatchArray(['block_version_id' => $id, 'workspace_id' => $workspace, 'step' => 1]);

    $audit = bdAudits();
    $after = json_decode($audit[0]['after_state'], true);
    expect($audit)->toHaveCount(1)
        ->and($audit[0]['action'])->toBe('blocks.draft.created')
        ->and($audit[0]['actor'])->toBe($owner)
        ->and($audit[0]['subject'])->toBe('block_draft:'.$id)
        ->and($audit[0]['request_id'])->not->toBeNull()
        ->and($after)->toMatchArray(['block_id' => $blockId, 'draft_id' => $id, 'block_type' => 'kpi-card', 'revision' => 1])
        ->and($after['name'])->not->toContain('Revenue');
});

it('refuses an unknown block type, a blank name and a missing name with a 422 and creates nothing', function (array $body, string $field) {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);

    bdCreate($body)->assertStatus(422)->assertJsonPath('error.code', PlatformErrorCode::ValidationFailed->value)->assertJsonValidationErrors([$field], 'errors');

    expect(bdCount('blocks'))->toBe(0)->and(bdCount('block_versions'))->toBe(0)->and(bdCount('draft_ui_state'))->toBe(0)->and(bdAudits())->toBe([]);
})->with([
    'unknown type' => [['type' => 'pie-chart'], 'type'],
    'blank name' => [['name' => '   '], 'name'],
    'too long' => [['name' => str_repeat('a', 121)], 'name'],
    'control character' => [['name' => "bad\u{0007}name"], 'name'],
]);

it('answers 409 blocks.draft_exists with the current Draft ID when the Block already has a Draft, and creates nothing', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $created = bdCreate()->assertCreated();
    $blockId = $created->json('data.block_id');
    $draftId = $created->json('data.draft_id');

    bdCreate(['block_id' => $blockId])->assertStatus(409)
        ->assertJsonPath('error.code', BlocksErrorCode::DraftExists->value)
        ->assertJsonPath('draft_id', $draftId);

    expect(bdCount('block_versions'))->toBe(1)->and(bdCount('draft_ui_state'))->toBe(1)->and(bdAudits())->toHaveCount(1);

    // The partial unique index is the backstop: even a direct insert cannot make a second Draft.
    expect(fn () => Cluster::seedBlockVersion($workspace, ['block_id' => $blockId]))->toThrow(PDOException::class, 'block_versions_one_draft_unique');
});

it('starts a new Draft of an existing Block once its Draft was discarded, and refuses a Block that does not exist or has another type', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    Queue::fake();
    $first = bdCreate()->assertCreated();

    $this->deleteJson(BD_URL.'/'.$first->json('data.draft_id'), [], BD_HEADERS)->assertOk();
    $second = bdCreate(['block_id' => $first->json('data.block_id')])->assertCreated();

    expect($second->json('data.block_id'))->toBe($first->json('data.block_id'))
        ->and($second->json('data.draft_id'))->not->toBe($first->json('data.draft_id'))
        ->and($second->json('data.revision'))->toBe(1);

    bdCreate(['block_id' => (string) Str::uuid7()])->assertStatus(422)->assertJsonValidationErrors(['block_id'], 'errors');
    bdCreate(['block_id' => 'not-a-uuid'])->assertStatus(422)->assertJsonValidationErrors(['block_id'], 'errors');
});

it('saves with a matching revision and epoch: revision rises, no audit event is written', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');

    foreach ([1, 2, 3, 4] as $revision) {
        bdSave($id, $revision, 1, ['name' => "Revenue {$revision}"])->assertOk()->assertJsonPath('data.revision', $revision + 1);
    }

    $response = bdSave($id, 5, 1, ['name' => ' Final name '])->assertOk();

    expect($response->json('data'))->toMatchArray(['name' => 'Final name', 'revision' => 6, 'lock_epoch' => 1, 'draft_id' => $id])
        ->and(bdRows()[0])->toMatchArray(['name' => 'Final name', 'revision' => 6])
        ->and(bdAudits())->toHaveCount(1)
        ->and(bdAudits()[0]['action'])->toBe('blocks.draft.created');
});

it('refuses a stale revision with 409 and the current state, and writes nothing', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');
    foreach ([1, 2, 3] as $revision) {
        bdSave($id, $revision, 1)->assertOk();
    }
    $before = bdRows();

    bdSave($id, 3, 1, ['name' => 'Stale write'])->assertStatus(409)
        ->assertJsonPath('error.code', BlocksErrorCode::RevisionConflict->value)
        ->assertJsonPath('current.data.revision', 4)
        ->assertJsonPath('current.data.name', 'Revenue')
        ->assertJsonPath('current.data.draft_id', $id);

    expect(bdRows())->toBe($before);
});

it('refuses a stale lock epoch with 423 platform.edit_lock_lost, checked before the revision, and writes nothing', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');
    Cluster::superuser()->prepare('UPDATE block_versions SET lock_epoch = 2, revision = 4 WHERE id = ?')->execute([$id]);
    $before = bdRows();

    // Revision right, epoch behind.
    bdSave($id, 4, 1, ['name' => 'Lost the lock'])->assertStatus(423)
        ->assertJsonPath('error.code', PlatformErrorCode::EditLockLost->value)
        ->assertJsonPath('current.data.lock_epoch', 2)
        ->assertJsonPath('current.data.revision', 4);

    // Both stale: the lock outranks the revision, as on the Data source form.
    bdSave($id, 3, 1)->assertStatus(423);

    expect(bdRows())->toBe($before)->and(bdSave($id, 4, 2)->assertOk()->json('data.revision'))->toBe(5);
});

it('requires the revision and the epoch to save', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');

    $this->putJson(BD_URL."/{$id}", ['name' => 'x'], BD_HEADERS)->assertStatus(422)->assertJsonValidationErrors(['expected_revision', 'lock_epoch'], 'errors');
    expect(bdRows()[0]['revision'])->toBe(1);
});

it('lets a save change the Block Type to another registered one only, and ignores any other field', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');

    bdSave($id, 1, 1, ['type' => 'pie-chart'])->assertStatus(422)->assertJsonValidationErrors(['type'], 'errors');
    expect(bdRows()[0]['revision'])->toBe(1);

    bdSave($id, 1, 1, ['config' => ['a' => 1], 'version' => '9.9', 'state' => 'discarded'])->assertOk()->assertJsonPath('data.state', 'draft');
    expect(bdRows()[0])->toMatchArray(['state' => 'draft', 'version' => '1.0'])
        ->and(json_decode(bdRows()[0]['config'], true))->toBe(['schema_version' => 1]);
});

it('refuses a secret-valued field on create and on save with 422 blocks.secret_values_refused and stores nothing', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);

    bdCreate(['api_key' => 'sk-live-123'])->assertStatus(422)->assertJsonPath('error.code', BlocksErrorCode::SecretValuesRefused->value);
    expect(bdCount('blocks'))->toBe(0)->and(bdCount('block_versions'))->toBe(0);

    $id = bdCreate()->assertCreated()->json('data.draft_id');
    bdSave($id, 1, 1, ['config' => ['headers' => [['name' => 'Authorization', 'secret' => true, 'value' => 'Bearer abc']]]])
        ->assertStatus(422)->assertJsonPath('error.code', BlocksErrorCode::SecretValuesRefused->value);
    bdSave($id, 1, 1, ['password' => 'hunter2'])->assertStatus(422)->assertJsonPath('error.code', BlocksErrorCode::SecretValuesRefused->value);

    expect(bdRows()[0])->toMatchArray(['revision' => 1, 'name' => 'Revenue today'])->and(json_encode(bdRows()))->not->toContain('hunter2')->not->toContain('sk-live');
});

it('discards a Draft: hidden and unsavable at once, audited, rows deleted by the maintenance job, the Block kept', function () {
    $workspace = Cluster::workspace('Acme');
    [, $editor] = bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');
    $blockId = bdRows('blocks')[0]['id'];
    Cluster::seedDraftSample($workspace, $id);

    Queue::fake();
    $this->deleteJson(BD_URL."/{$id}", [], BD_HEADERS)->assertOk()->assertJsonPath('data.state', 'discarded');

    // The web transaction only marks it: the rows are still there.
    expect(bdRows()[0])->toMatchArray(['id' => $id, 'state' => 'discarded'])
        ->and(bdRows()[0]['discarded_at'])->not->toBeNull()
        ->and(bdCount('draft_samples'))->toBe(1)
        ->and(bdCount('draft_ui_state'))->toBe(1);

    $audit = bdAudits('blocks.draft.discarded');
    $before = json_decode($audit[0]['before_state'], true);
    expect($audit)->toHaveCount(1)
        ->and($audit[0]['actor'])->toBe($editor)
        ->and($audit[0]['subject'])->toBe('block_draft:'.$id)
        ->and($before)->toMatchArray(['draft_id' => $id, 'block_id' => $blockId, 'block_type' => 'kpi-card']);

    // Hidden: not listed, not savable, not discardable twice.
    $this->getJson(BD_URL, BD_HEADERS)->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
    bdSave($id, 1, 1)->assertNotFound();
    $this->deleteJson(BD_URL."/{$id}", [], BD_HEADERS)->assertNotFound();
    expect(bdAudits('blocks.draft.discarded'))->toHaveCount(1);

    // The queued signed job, on the `maintenance` queue, does the physical delete.
    Queue::assertPushedOn('maintenance', PurgeDiscardedDraftsJob::class, fn (PurgeDiscardedDraftsJob $job): bool => $job->workspaceId === $workspace);
    // Queue::fake() holds the job back; run its handler as the worker does.
    (new PurgeDiscardedDraftsJob($workspace))->handle(app(PurgeDiscardedDrafts::class));

    expect(bdCount('block_versions'))->toBe(0)->and(bdCount('draft_samples'))->toBe(0)->and(bdCount('draft_ui_state'))->toBe(0)
        ->and(bdCount('blocks'))->toBe(0);
});

it('keeps a Block that still has another version when a Draft is purged', function () {
    $workspace = Cluster::workspace('Acme');
    $block = Cluster::seedBlock($workspace);
    $live = Cluster::seedBlockVersion($workspace, ['block_id' => $block]);
    Cluster::seedBlockVersion($workspace, ['block_id' => $block, 'state' => 'discarded']);

    (new PurgeDiscardedDraftsJob($workspace))->handle(app(PurgeDiscardedDrafts::class));

    expect(array_column(bdRows(), 'id'))->toBe([$live])->and(bdCount('blocks'))->toBe(1);
});

it('purges per Workspace under row-level security and never touches a live Draft', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    $liveA = Cluster::seedBlockVersion($a);
    $deadA = Cluster::seedBlockVersion($a, ['state' => 'discarded']);
    $deadB = Cluster::seedBlockVersion($b, ['state' => 'discarded']);
    foreach ([$liveA, $deadA, $deadB] as $version) {
        Cluster::seedDraftUiState($version === $deadB ? $b : $a, $version);
        Cluster::seedDraftSample($version === $deadB ? $b : $a, $version);
    }

    dispatch_sync(new PurgeDiscardedDraftsJob($a));

    $left = array_column(bdRows(), 'id');
    expect($left)->toContain($liveA, $deadB)->not->toContain($deadA)
        ->and(bdCount('draft_ui_state'))->toBe(2)->and(bdCount('draft_samples'))->toBe(2);

    // Without a Workspace the run spans every Workspace: the safety net behind a lost enqueue.
    dispatch_sync(new PurgeDiscardedDraftsJob);

    expect(array_column(bdRows(), 'id'))->toBe([$liveA])->and(bdCount('draft_ui_state'))->toBe(1)->and(bdCount('draft_samples'))->toBe(1);

    // Idempotent.
    dispatch_sync(new PurgeDiscardedDraftsJob);
    expect(bdCount('block_versions'))->toBe(1);
});

it('keeps the discard when the purge cannot be queued', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');

    // The default queue connection does not exist: the dispatch throws, and the discard must stand.
    config(['queue.default' => 'nowhere']);
    Log::spy();

    $this->deleteJson(BD_URL."/{$id}", [], BD_HEADERS)->assertOk();

    expect(bdRows()[0]['state'])->toBe('discarded')->and(bdAudits('blocks.draft.discarded'))->toHaveCount(1);
    Log::shouldHaveReceived('error')->with('blocks.draft_purge.enqueue_failed', Mockery::on(fn (array $context): bool => $context['workspace_id'] === $workspace))->once();
});

it('gives the web role no DELETE on any Blocks table, and refuses to restore a discarded Draft or lower an epoch', function () {
    $workspace = Cluster::workspace('Acme');
    $dead = Cluster::seedBlockVersion($workspace, ['state' => 'discarded']);
    $live = Cluster::seedBlockVersion($workspace, ['lock_epoch' => 3]);

    foreach (['blocks', 'block_versions', 'draft_samples', 'draft_ui_state'] as $table) {
        foreach ([Cluster::directApp(), Cluster::pooledApp()] as $app) {
            expect(fn () => Cluster::inWorkspace($app, $workspace, fn ($pdo) => $pdo->exec("delete from {$table}")))->toThrow(PDOException::class, 'permission denied');
        }
    }

    expect(fn () => Cluster::superuser()->prepare("UPDATE block_versions SET state = 'draft', discarded_at = NULL WHERE id = ?")->execute([$dead]))
        ->toThrow(PDOException::class, 'cannot be restored')
        ->and(fn () => Cluster::superuser()->prepare('UPDATE block_versions SET lock_epoch = 2 WHERE id = ?')->execute([$live]))
        ->toThrow(PDOException::class, 'never decreases');
});

it('lists only Drafts, newest edit first, with the owner, search and counts, and hides other Workspaces', function () {
    $workspace = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    [, $owner] = bdAdmin($workspace);
    Queue::fake();

    $revenue = bdCreate(['name' => 'Revenue today'])->assertCreated()->json('data.draft_id');
    $headcount = bdCreate(['name' => 'Headcount'])->assertCreated()->json('data.draft_id');
    $gone = bdCreate(['name' => 'Discarded one'])->assertCreated()->json('data.draft_id');
    $this->deleteJson(BD_URL."/{$gone}", [], BD_HEADERS)->assertOk();
    Cluster::seedBlockVersion($other, ['name' => 'Their draft']);
    bdSave($revenue, 1, 1)->assertOk();
    // Timestamps are whole seconds: make the order certain.
    Cluster::superuser()->prepare("UPDATE block_versions SET updated_at = now() - interval '1 minute' WHERE id = ?")->execute([$headcount]);

    $all = $this->getJson(BD_URL, BD_HEADERS)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect(array_column($all->json('data'), 'name'))->toBe(['Revenue', 'Headcount'])
        ->and($all->json('meta'))->toBe(['total' => 2, 'matched' => 2])
        ->and($all->json('data.0.owner'))->toBe('ada@example.test')
        ->and($all->json('data.0.owner_membership_id'))->toBe($owner);

    $found = $this->getJson(BD_URL.'?q=head', BD_HEADERS)->assertOk();
    expect(array_column($found->json('data'), 'name'))->toBe(['Headcount'])->and($found->json('meta'))->toBe(['total' => 2, 'matched' => 1]);

    $wild = $this->getJson(BD_URL.'?q=%25', BD_HEADERS)->assertOk();
    expect($wild->json('meta.matched'))->toBe(0);
    expect($this->getJson(BD_URL.'?q=kpi', BD_HEADERS)->json('meta.matched'))->toBe(2);

    // Every Draft starts in General; a Draft in another category matches by that name only.
    $general = $this->getJson(BD_URL.'?q=general', BD_HEADERS)->assertOk();
    expect($general->json('meta.matched'))->toBe($general->json('meta.total'))->and($general->json('data.0.category'))->toBe('General');
    $finance = Cluster::seedBlockCategory($workspace, 'Finance');
    $first = $general->json('data.0.draft_id');
    bdSave($first, $general->json('data.0.revision'), 1, ['category_id' => $finance])->assertOk();
    expect($this->getJson(BD_URL.'?q=finance', BD_HEADERS)->json('data.0.draft_id'))->toBe($first)
        ->and($this->getJson(BD_URL.'?q=general', BD_HEADERS)->json('meta.matched'))->toBe($general->json('meta.total') - 1);
});

it('answers 403 and audits the denial without blocks.edit, on the API and on the page, and writes nothing', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace, ['data_sources.manage']);
    Cache::flush();

    $this->getJson(BD_URL, BD_HEADERS)->assertForbidden();
    Cache::flush();
    bdCreate()->assertForbidden();
    Cache::flush();
    $this->get('/admin/blocks/drafts')->assertForbidden();

    expect(bdCount('blocks'))->toBe(0)
        ->and(Cluster::rows(Cluster::superuser(), "select count(*) as n from audit_events where action = 'access.admin.denied'")[0]['n'])->toBe(3);
});

it('answers 403 to a User-area session and renders the list page to an Admin', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace, ['blocks.edit'], 'ada@example.test', 'user');
    Cache::flush();

    bdCreate()->assertForbidden();
    expect(bdCount('blocks'))->toBe(0);

    bdAdmin($workspace, ['blocks.edit'], 'grace@example.test');
    $this->get('/admin/blocks/drafts', ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/'))])
        ->assertOk()->assertJsonPath('component', 'admin/DraftBlocks');
});

it('cannot see or save a Draft of another Workspace', function () {
    $mine = Cluster::workspace('Mine');
    $theirs = Cluster::workspace('Theirs');
    bdAdmin($mine);
    $foreign = Cluster::seedBlockVersion($theirs);

    bdSave($foreign, 1, 1)->assertNotFound();
    $this->deleteJson(BD_URL."/{$foreign}", [], BD_HEADERS)->assertNotFound();
    expect(bdRows()[0])->toMatchArray(['id' => $foreign, 'revision' => 1, 'state' => 'draft']);
});

it('reads no row without a Workspace context', function () {
    $workspace = Cluster::workspace('Acme');
    Cluster::seedDraftSample($workspace);
    Cluster::seedDraftUiState($workspace);

    foreach (['blocks', 'block_versions', 'draft_samples', 'draft_ui_state'] as $table) {
        expect(Cluster::rows(Cluster::directApp(), "select id from {$table}"))->toBe([], "{$table} without a context");
    }
});

it('resolves the draft_block lock epochs: current and increment see live Drafts of this Workspace only', function () {
    $workspace = Cluster::workspace('Acme');
    $other = Cluster::workspace('Other');
    $live = Cluster::seedBlockVersion($workspace, ['lock_epoch' => 2]);
    $dead = Cluster::seedBlockVersion($workspace, ['state' => 'discarded']);
    $foreign = Cluster::seedBlockVersion($other);
    Cluster::superuser()->prepare("UPDATE block_versions SET revision = 5, updated_at = '2026-01-01 00:00:00+00' WHERE id = ?")->execute([$live]);

    $epochs = app(EditLockResources::class)->for('draft_block');
    $result = app(WorkspaceTransaction::class)->run($workspace, fn (): array => [
        'live' => $epochs->current($workspace, $live),
        'dead' => $epochs->current($workspace, $dead),
        'foreign' => $epochs->current($workspace, $foreign),
        'junk' => $epochs->current($workspace, 'not-a-uuid'),
        'raised' => $epochs->increment($workspace, $live),
        'dead_raised' => $epochs->increment($workspace, $dead),
        'junk_raised' => $epochs->increment($workspace, 'not-a-uuid'),
    ]);

    expect($result)->toBe(['live' => 2, 'dead' => null, 'foreign' => null, 'junk' => null, 'raised' => 3, 'dead_raised' => null, 'junk_raised' => null]);

    $row = Cluster::rows(Cluster::superuser(), 'select lock_epoch, revision, updated_at from block_versions where id = ?', [$live])[0];
    expect($row['lock_epoch'])->toBe(3)->and($row['revision'])->toBe(5)->and($row['updated_at'])->toStartWith('2026-01-01 00:00:00');
    expect(Cluster::rows(Cluster::superuser(), 'select lock_epoch from block_versions where id = ?', [$dead])[0]['lock_epoch'])->toBe(1);
});
