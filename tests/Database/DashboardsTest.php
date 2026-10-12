<?php

use App\Modules\Connector\Infrastructure\CurlClient;
use App\Modules\Connector\Infrastructure\CurlResult;
use App\Modules\Ingestion\Contracts\Subscribe;
use App\Modules\Ingestion\Contracts\SubscribeInput;
use App\Modules\Ingestion\Contracts\SubscribeResult;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 4.4 against the real PostgreSQL: the Overview is created on first open, the Inertia page carries only {dashboard_id, revision}, the layout API
// returns clamped geometry, item states and a User-safe Block card, and another member's or Workspace's dashboard is a 404.
beforeEach(fn () => $this->withoutVite());

const DB4_PASSWORD = 'a-long-enough-password';
const DB4_HEADERS = ['Referer' => 'http://localhost:8000'];

/** @return array{0: int, 1: string} user id, membership id */
function dbMember(string $workspace, string $email, string $role = 'user'): array
{
    $user = Cluster::user($email);
    Cluster::superuser()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([Hash::make(DB4_PASSWORD), $user]);
    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspace, $user, $role, 'active']);

    return [$user, $membership];
}

function dbSignIn(string $email): void
{
    test()->postJson('/login', ['email' => $email, 'password' => DB4_PASSWORD, 'role' => 'user'])->assertOk();
}

/** A published Block (a published current version) with the given chrome and size limits; returns its id. */
function dbBlock(string $workspace, string $name = 'Revenue overview', array $config = [], bool $published = true, bool $visible = true): string
{
    $block = Cluster::seedBlock($workspace);
    $frozen = $config + [
        'schema_version' => 1, 'type_key' => 'kpi-card', 'contract_version' => 1,
        'source' => ['data_source_id' => (string) Str::uuid7(), 'endpoint_id' => (string) Str::uuid7(), 'bindings' => []],
        'query_plan' => ['v' => 1, 'extract' => ['fields' => ['rev' => '$.x']], 'shape' => ['headline_value' => 'rev']],
        'chrome' => ['description' => 'Net, this month', 'icon' => 'trending-up', 'show_header' => true, 'show_subtitle' => true, 'allow_minimize' => true],
        'sizes' => ['min_width' => 3, 'max_width' => 6, 'width' => 3, 'min_height' => 'small', 'max_height' => 'medium', 'height' => 'small'],
        'refresh_interval' => 60,
    ];

    if ($published) {
        $version = Cluster::seedBlockVersion($workspace, [
            'block_id' => $block, 'state' => 'published', 'name' => $name, 'config' => json_encode($frozen),
            'prepared_at' => '2026-10-12 09:00:00+00', 'published_at' => '2026-10-12 09:00:01+00',
        ]);
        Cluster::superuser()->prepare('UPDATE blocks SET current_version_id = ? WHERE id = ?')->execute([$version, $block]);
    }

    if ($visible) {
        Cluster::seedAccessSubject($workspace, $block);
    }

    return $block;
}

function dbItem(string $workspace, string $dashboard, string $block, array $columns = []): string
{
    $row = $columns + ['id' => (string) Str::uuid7(), 'workspace_id' => $workspace, 'dashboard_id' => $dashboard, 'block_id' => $block, 'col' => 0, 'row' => 0, 'width' => 3, 'height' => 'small'];
    Cluster::superuser()->prepare('INSERT INTO dashboard_items ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')')
        ->execute(array_values($row));

    return (string) $row['id'];
}

/** @return array{dashboard_id: string, revision: int} the page props of the Overview */
function dbOverview(): array
{
    $props = [];

    test()->get(route('overview'))->assertOk()->assertInertia(function ($page) use (&$props) {
        $page->component('Dashboard');
        $props = $page->toArray()['props'];

        return $page;
    });

    return $props;
}

it('creates the Overview empty with revision 0 on first open, once, and the page carries only the id and the revision', function () {
    $workspace = Cluster::workspace('Acme');
    dbMember($workspace, 'ada@example.test');
    dbSignIn('ada@example.test');

    $first = dbOverview();
    $second = dbOverview();
    $rows = Cluster::rows(Cluster::superuser(), 'select * from dashboards');

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['workspace_id' => $workspace, 'is_overview' => true, 'revision' => 0])
        ->and($first['dashboard_id'])->toBe($rows[0]['id'])->and($second['dashboard_id'])->toBe($first['dashboard_id'])
        ->and($first['revision'])->toBe(0)
        ->and(array_keys($first))->not->toContain('items')->not->toContain('layout')->not->toContain('blocks')
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from dashboard_items')[0]['n'])->toBe(0);
});

it('returns the layout with clamped geometry, item states and a User-safe Block card', function () {
    $workspace = Cluster::workspace('Acme');
    [, $membership] = dbMember($workspace, 'ada@example.test');
    dbSignIn('ada@example.test');
    $dashboard = dbOverview()['dashboard_id'];

    $ok = dbBlock($workspace);
    $unpublished = dbBlock($workspace, 'Old', published: false);
    $removed = dbBlock($workspace, 'Secret', visible: false);
    // Stored wider than the Block allows (max 6): the API clamps, the row stays as it is.
    $item = dbItem($workspace, $dashboard, $ok, ['col' => 0, 'row' => 0, 'width' => 12, 'height' => 'large']);
    dbItem($workspace, $dashboard, $unpublished, ['col' => 0, 'row' => 4]);
    dbItem($workspace, $dashboard, $removed, ['col' => 3, 'row' => 4]);

    $response = $this->getJson("/api/v1/dashboards/{$dashboard}", DB4_HEADERS)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $data = $response->json('data');
    $byBlock = array_column($data['items'], null, 'block_id');

    expect($data)->toMatchArray(['dashboard_id' => $dashboard, 'name' => 'Overview', 'is_overview' => true, 'revision' => 0])
        ->and($byBlock[$ok]['item_id'])->toBe($item)
        ->and($byBlock[$ok]['geometry'])->toBe(['col' => 0, 'row' => 0, 'width' => 6, 'height' => 'medium'])
        ->and($byBlock[$ok]['state'])->toBe('ok')
        ->and($byBlock[$ok]['refresh_interval'])->toBe(60)->and($byBlock[$ok]['is_live'])->toBeFalse()
        ->and($byBlock[$ok]['block'])->toMatchArray(['name' => 'Revenue overview', 'subtitle' => 'Net, this month', 'type' => 'kpi-card', 'icon' => 'trending-up'])
        ->and($byBlock[$unpublished]['state'])->toBe('unpublished')->and($byBlock[$unpublished]['block'])->toBeNull()
        ->and($byBlock[$removed]['state'])->toBe('access_removed')->and($byBlock[$removed]['block'])->toBeNull()
        ->and(Cluster::rows(Cluster::superuser(), 'select width from dashboard_items where id = ?', [$item])[0]['width'])->toBe(12)
        ->and($data['next_free_slot'])->toHaveKeys(['col', 'row']);

    // Nothing of the source reaches a User: no Data Source, Endpoint, Query Plan or version.
    $json = $response->getContent();

    expect($json)->not->toContain('data_source')->not->toContain('endpoint')->not->toContain('query_plan')->not->toContain('headline_value')
        ->and($json)->not->toContain('Secret')->not->toContain('Old');
});

it('re-checks access on every read: a Block hidden after the first load is access_removed on the next', function () {
    $workspace = Cluster::workspace('Acme');
    dbMember($workspace, 'ada@example.test');
    dbSignIn('ada@example.test');
    $dashboard = dbOverview()['dashboard_id'];
    $block = dbBlock($workspace);
    dbItem($workspace, $dashboard, $block);

    expect($this->getJson("/api/v1/dashboards/{$dashboard}", DB4_HEADERS)->json('data.items.0.state'))->toBe('ok');

    Cluster::superuser()->prepare('UPDATE access_subjects SET visible = false WHERE subject_id = ?')->execute([$block]);

    $item = $this->getJson("/api/v1/dashboards/{$dashboard}", DB4_HEADERS)->json('data.items.0');

    expect($item['state'])->toBe('access_removed')->and($item['block'])->toBeNull();
});

it('places a new Block in the first free slot', function () {
    $workspace = Cluster::workspace('Acme');
    dbMember($workspace, 'ada@example.test');
    dbSignIn('ada@example.test');
    $dashboard = dbOverview()['dashboard_id'];
    dbItem($workspace, $dashboard, dbBlock($workspace), ['col' => 0, 'row' => 0, 'width' => 3]);
    dbItem($workspace, $dashboard, dbBlock($workspace), ['col' => 3, 'row' => 0, 'width' => 6]);

    expect($this->getJson("/api/v1/dashboards/{$dashboard}", DB4_HEADERS)->json('data.next_free_slot'))->toBe(['col' => 9, 'row' => 0]);
});

it('answers 404 with nothing disclosed for another member\'s dashboard, another Workspace\'s and one that does not exist', function () {
    $acme = Cluster::workspace('Acme');
    $globex = Cluster::workspace('Globex');
    dbMember($acme, 'ada@example.test');
    [, $other] = dbMember($acme, 'grace@example.test');
    dbMember($globex, 'linus@example.test');

    $theirs = (string) Str::uuid7();
    Cluster::superuser()->prepare("INSERT INTO dashboards (id, workspace_id, membership_id, name, is_overview, revision) VALUES (?, ?, ?, 'Overview', true, 0)")->execute([$theirs, $acme, $other]);
    $foreign = (string) Str::uuid7();
    Cluster::superuser()->prepare("INSERT INTO dashboards (id, workspace_id, membership_id, name, is_overview, revision) VALUES (?, ?, ?, 'Overview', true, 0)")->execute([$foreign, $globex, (string) Str::uuid7()]);

    dbSignIn('ada@example.test');

    foreach ([$theirs, $foreign, (string) Str::uuid7(), 'not-a-uuid'] as $id) {
        $response = $this->getJson("/api/v1/dashboards/{$id}", DB4_HEADERS)->assertNotFound();

        expect($response->getContent())->not->toContain('Overview')->not->toContain($other);
    }
});

it('restores the same layout on another device', function () {
    $workspace = Cluster::workspace('Acme');
    dbMember($workspace, 'ada@example.test');
    dbSignIn('ada@example.test');
    $dashboard = dbOverview()['dashboard_id'];
    dbItem($workspace, $dashboard, dbBlock($workspace), ['col' => 3, 'row' => 2, 'width' => 4, 'height' => 'medium']);
    $first = $this->getJson("/api/v1/dashboards/{$dashboard}", DB4_HEADERS)->json('data');

    $this->flushSession();
    $this->app['auth']->forgetGuards();
    dbSignIn('ada@example.test');

    expect(dbOverview()['dashboard_id'])->toBe($dashboard)
        ->and($this->getJson("/api/v1/dashboards/{$dashboard}", DB4_HEADERS)->json('data'))->toBe($first);
});

it('refuses a geometry the grid cannot hold at the database', function (array $columns) {
    $workspace = Cluster::workspace('Acme');
    $dashboard = (string) Str::uuid7();
    Cluster::superuser()->prepare("INSERT INTO dashboards (id, workspace_id, membership_id, name, is_overview, revision) VALUES (?, ?, ?, 'Overview', true, 0)")->execute([$dashboard, $workspace, (string) Str::uuid7()]);

    expect(fn () => dbItem($workspace, $dashboard, (string) Str::uuid7(), $columns))->toThrow(PDOException::class);
})->with([
    'overflows the right edge' => [['col' => 10, 'width' => 4]],
    'width not a preset' => [['width' => 5]],
    'unknown height' => [['height' => 'huge']],
    'negative row' => [['row' => -1]],
]);

it('keeps one item per Block on a dashboard', function () {
    $workspace = Cluster::workspace('Acme');
    $dashboard = (string) Str::uuid7();
    Cluster::superuser()->prepare("INSERT INTO dashboards (id, workspace_id, membership_id, name, is_overview, revision) VALUES (?, ?, ?, 'Overview', true, 0)")->execute([$dashboard, $workspace, (string) Str::uuid7()]);
    $block = (string) Str::uuid7();
    dbItem($workspace, $dashboard, $block);

    expect(fn () => dbItem($workspace, $dashboard, $block))->toThrow(PDOException::class);
});

// ---- Story 4.7: the Add-blocks Panel's list -------------------------------------------------------------------------------------------------------

it('lists every published Block a member can see, User-safe, with its Data Source name, interval, version and default size', function () {
    $workspace = Cluster::workspace('Acme');
    dbMember($workspace, 'ada@example.test');
    dbSignIn('ada@example.test');
    $source = Cluster::seedDataSource($workspace, 'HR analytics API');

    $shown = dbBlock($workspace, 'Headcount', ['source' => ['data_source_id' => $source, 'endpoint_id' => (string) Str::uuid7(), 'bindings' => []], 'refresh_interval' => 3600]);
    dbBlock($workspace, 'Draft only', published: false);
    dbBlock($workspace, 'Hidden', visible: false);

    $response = $this->getJson('/api/v1/blocks/available', DB4_HEADERS)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $rows = $response->json('data');

    expect($rows)->toHaveCount(1)->and($response->json('meta.total'))->toBe(1)
        ->and($rows[0])->toBe([
            'block_id' => $shown, 'name' => 'Headcount', 'description' => 'Net, this month', 'category' => null, 'type' => 'kpi-card',
            'data_source' => 'HR analytics API', 'refresh_interval' => 3600, 'version' => '1.0', 'is_new' => false,
            'default_size' => ['width' => 3, 'height' => 'small'],
        ]);

    $json = $response->getContent();

    expect($json)->not->toContain('endpoint')->not->toContain('query_plan')->not->toContain('Draft only')->not->toContain('Hidden');
});

it('marks a Block New only inside the pending_input window, and never while it is unset', function () {
    $workspace = Cluster::workspace('Acme');
    dbMember($workspace, 'ada@example.test');
    dbSignIn('ada@example.test');
    dbBlock($workspace, 'Fresh');

    config(['dashflow.drawer.new_window.value' => null]);
    expect($this->getJson('/api/v1/blocks/available', DB4_HEADERS)->json('data.0.is_new'))->toBeFalse();

    // Published at 2026-10-12 09:00:01 in the seed: a window that reaches back far enough makes it new, a short one does not.
    config(['dashflow.drawer.new_window.value' => '999999999']);
    expect($this->getJson('/api/v1/blocks/available', DB4_HEADERS)->json('data.0.is_new'))->toBeTrue();

    config(['dashflow.drawer.new_window.value' => '60']);
    expect($this->getJson('/api/v1/blocks/available', DB4_HEADERS)->json('data.0.is_new'))->toBeFalse();
});

it('shows another Workspace nothing of this one', function () {
    $acme = Cluster::workspace('Acme');
    $globex = Cluster::workspace('Globex');
    dbMember($globex, 'linus@example.test');
    dbBlock($acme, 'Acme only');
    dbSignIn('linus@example.test');

    expect($this->getJson('/api/v1/blocks/available', DB4_HEADERS)->assertOk()->json('data'))->toBe([]);
});

// ---- Story 4.8: the preview's generated sample ----------------------------------------------------------------------------------------------------

function dbSampleBlock(string $workspace, string $name = 'Headcount', bool $visible = true): string
{
    return dbBlock($workspace, $name, [
        'fields' => ['record_path' => null, 'items' => [
            ['alias' => 'rev', 'path' => '$.data.revenue', 'role' => 'measure', 'type' => 'number'],
            ['alias' => 'label', 'path' => '$.data.label', 'role' => 'dimension', 'type' => 'text'],
        ]],
        'query_plan' => ['v' => 1, 'extract' => ['fields' => ['rev' => '$.data.revenue', 'label' => '$.data.label']], 'shape' => ['headline_value' => 'rev', 'kpi_label' => 'label']],
    ], visible: $visible);
}

beforeEach(function () {
    config([
        'dashflow.preview.membership_limit.value' => '100',
        'dashflow.preview.window_seconds.value' => '60',
        'dashflow.preview.max_extracted_values.value' => '1000',
        'dashflow.preview.max_evaluations.value' => '1000',
        'dashflow.preview.max_wall_ms.value' => '5000',
    ]);
});

it('generates a deterministic sample from the version\'s field snapshot, drawn with the real shaper and marked as sample', function () {
    $workspace = Cluster::workspace('Acme');
    dbMember($workspace, 'ada@example.test');
    dbSignIn('ada@example.test');
    $block = dbSampleBlock($workspace);

    $first = $this->getJson("/api/v1/blocks/{$block}/sample", DB4_HEADERS)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $second = $this->getJson("/api/v1/blocks/{$block}/sample", DB4_HEADERS)->assertOk();
    $data = $first->json('data');

    expect($data)->toMatchArray(['block_id' => $block, 'type' => 'kpi-card', 'version' => '1.0', 'sample' => true])
        ->and($data['render_payload']['state'])->toBe('normal')
        ->and($data['render_payload']['headline']['available'])->toBeTrue()
        ->and($data['render_payload']['headline']['value'])->toMatch('/\A[1-9][0-9]*\.[0-9]{2}\z/')
        ->and($data['render_payload']['kpiLabel'])->toBe('Sample label')
        ->and($second->json('data'))->toBe($data);
});

it('gives another version other values and the same version the same ones for every member', function () {
    $workspace = Cluster::workspace('Acme');
    dbMember($workspace, 'ada@example.test');
    dbMember($workspace, 'grace@example.test');
    $a = dbSampleBlock($workspace, 'A');
    $b = dbSampleBlock($workspace, 'B');

    dbSignIn('ada@example.test');
    $adaA = $this->getJson("/api/v1/blocks/{$a}/sample", DB4_HEADERS)->json('data.render_payload.headline.value');
    $adaB = $this->getJson("/api/v1/blocks/{$b}/sample", DB4_HEADERS)->json('data.render_payload.headline.value');

    $this->flushSession();
    $this->app['auth']->forgetGuards();
    dbSignIn('grace@example.test');

    expect($this->getJson("/api/v1/blocks/{$a}/sample", DB4_HEADERS)->json('data.render_payload.headline.value'))->toBe($adaA)
        ->and($adaA)->not->toBe($adaB);
});

it('reads nothing from a source and creates no target, subscription or stored result', function () {
    $workspace = Cluster::workspace('Acme');
    dbMember($workspace, 'ada@example.test');
    dbSignIn('ada@example.test');
    $block = dbSampleBlock($workspace);

    $curl = new class implements CurlClient
    {
        public int $calls = 0;

        public function execute(array $options, ?int $maxBytes = null): CurlResult
        {
            $this->calls++;

            throw new RuntimeException('a source must not be called by a preview');
        }
    };
    app()->instance(CurlClient::class, $curl);
    app()->instance(Subscribe::class, new class implements Subscribe
    {
        public function subscribe(SubscribeInput $input): SubscribeResult
        {
            throw new RuntimeException('a preview must not subscribe');
        }
    });

    $this->getJson('/api/v1/blocks/available', DB4_HEADERS)->assertOk();
    $this->getJson("/api/v1/blocks/{$block}/sample", DB4_HEADERS)->assertOk();

    foreach (['sync_targets', 'sync_subscriptions', 'raw_bodies', 'sync_runs', 'operations'] as $table) {
        expect(Cluster::rows(Cluster::superuser(), "select count(*) as n from {$table}")[0]['n'])->toBe(0, $table);
    }

    expect($curl->calls)->toBe(0);
});

it('answers 404 for a Block the member cannot see, one that is not published and one that does not exist', function () {
    $workspace = Cluster::workspace('Acme');
    dbMember($workspace, 'ada@example.test');
    dbSignIn('ada@example.test');
    $hidden = dbSampleBlock($workspace, 'Hidden', visible: false);
    $draft = dbBlock($workspace, 'Draft', published: false);

    foreach ([$hidden, $draft, (string) Str::uuid7(), 'nope'] as $id) {
        $this->getJson("/api/v1/blocks/{$id}/sample", DB4_HEADERS)->assertNotFound();
    }
});

it('answers 404 rather than a made-up value when the shaper cannot be fed (a plan the engine refuses)', function () {
    $workspace = Cluster::workspace('Acme');
    dbMember($workspace, 'ada@example.test');
    dbSignIn('ada@example.test');
    $block = dbBlock($workspace, 'Odd', ['query_plan' => ['v' => 9]]);

    $this->getJson("/api/v1/blocks/{$block}/sample", DB4_HEADERS)->assertNotFound();
});

// ---- Story 4.9: AddItem and RemoveItem -------------------------------------------------------------------------------------------------------------

function db9Url(string $dashboard, string $tail = 'blocks'): string
{
    return "/api/v1/dashboards/{$dashboard}/{$tail}";
}

function db9Revision(string $dashboard): int
{
    return (int) Cluster::rows(Cluster::superuser(), 'select revision from dashboards where id = ?', [$dashboard])[0]['revision'];
}

function db9Setup(): array
{
    $workspace = Cluster::workspace('Acme');
    [, $membership] = dbMember($workspace, 'ada@example.test');
    dbSignIn('ada@example.test');

    return [$workspace, $membership, dbOverview()['dashboard_id']];
}

it('places a Block at the first free position at its default size, bumps the revision, emits the change and moves nothing', function () {
    [$workspace, , $dashboard] = db9Setup();
    $existing = dbBlock($workspace, 'First');
    $kept = dbItem($workspace, $dashboard, $existing, ['col' => 0, 'row' => 0, 'width' => 3, 'height' => 'small']);
    $new = dbBlock($workspace, 'Second', ['sizes' => ['min_width' => 3, 'max_width' => 12, 'width' => 6, 'min_height' => 'small', 'max_height' => 'large', 'height' => 'medium']]);

    $response = $this->postJson(db9Url($dashboard), ['block_id' => $new, 'expected_revision' => 0], DB4_HEADERS)->assertCreated();
    $item = $response->json('data.item');

    expect($item['block_id'])->toBe($new)
        ->and($item['geometry'])->toBe(['col' => 3, 'row' => 0, 'width' => 6, 'height' => 'medium'])
        ->and($item['state'])->toBe('ok')->and($item['block']['name'])->toBe('Second')
        ->and($response->json('data.revision'))->toBe(1)
        ->and(db9Revision($dashboard))->toBe(1)
        ->and(Cluster::rows(Cluster::superuser(), 'select col, row, width from dashboard_items where id = ?', [$kept])[0])->toBe(['col' => 0, 'row' => 0, 'width' => 3]);

    $event = Cluster::rows(Cluster::superuser(), "select data from outbox_events where type = 'dashboards.dashboard.changed'");

    expect($event)->toHaveCount(1)
        ->and(json_decode($event[0]['data'], true))->toMatchArray(['command' => 'add_item', 'dashboard_id' => $dashboard, 'item_id' => $item['item_id'], 'revision' => 1]);
});

it('is idempotent per Block: the same add again returns the item, adds no row and is not a conflict', function () {
    [$workspace, , $dashboard] = db9Setup();
    $block = dbBlock($workspace);

    $first = $this->postJson(db9Url($dashboard), ['block_id' => $block, 'expected_revision' => 0], DB4_HEADERS)->assertCreated()->json('data.item.item_id');
    $again = $this->postJson(db9Url($dashboard), ['block_id' => $block, 'expected_revision' => 0], DB4_HEADERS)->assertOk();

    expect($again->json('data.item.item_id'))->toBe($first)
        ->and($again->json('data.revision'))->toBe(1)
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from dashboard_items')[0]['n'])->toBe(1)
        ->and(Cluster::rows(Cluster::superuser(), "select count(*) as n from outbox_events where type = 'dashboards.dashboard.changed'")[0]['n'])->toBe(1);
});

it('refuses a stale revision with 409, an invisible or unknown Block with 404 and an unpublished one with 422, placing nothing', function () {
    [$workspace, , $dashboard] = db9Setup();
    $visible = dbBlock($workspace);
    $hidden = dbBlock($workspace, 'Hidden', visible: false);
    $unpublished = dbBlock($workspace, 'Unpublished', published: false);

    $conflict = $this->postJson(db9Url($dashboard), ['block_id' => $visible, 'expected_revision' => 5], DB4_HEADERS)->assertStatus(409);

    expect($conflict->json('error.code'))->toBe('dashboards.revision_conflict')->and($conflict->json('error.revision'))->toBe(0);

    $this->postJson(db9Url($dashboard), ['block_id' => $hidden, 'expected_revision' => 0], DB4_HEADERS)->assertNotFound();
    $this->postJson(db9Url($dashboard), ['block_id' => (string) Str::uuid7(), 'expected_revision' => 0], DB4_HEADERS)->assertNotFound();

    $closed = $this->postJson(db9Url($dashboard), ['block_id' => $unpublished, 'expected_revision' => 0], DB4_HEADERS)->assertStatus(422);

    expect($closed->json('error.code'))->toBe('dashboards.block_unavailable')
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from dashboard_items')[0]['n'])->toBe(0)
        ->and(db9Revision($dashboard))->toBe(0);
});

it('removes by Block with the revision, emits the change, and a repeat is a no-op', function () {
    [$workspace, , $dashboard] = db9Setup();
    $block = dbBlock($workspace);
    $other = dbBlock($workspace, 'Other');
    dbItem($workspace, $dashboard, $block);
    dbItem($workspace, $dashboard, $other, ['col' => 3]);

    $response = $this->deleteJson(db9Url($dashboard, "blocks/{$block}"), ['expected_revision' => 0], DB4_HEADERS)->assertOk();

    expect($response->json('data'))->toBe(['block_id' => $block, 'removed' => true, 'revision' => 1])
        ->and(Cluster::rows(Cluster::superuser(), 'select block_id from dashboard_items')[0]['block_id'])->toBe($other);

    $again = $this->deleteJson(db9Url($dashboard, "blocks/{$block}"), ['expected_revision' => 0], DB4_HEADERS)->assertOk();

    expect($again->json('data'))->toBe(['block_id' => $block, 'removed' => false, 'revision' => 1])
        ->and(Cluster::rows(Cluster::superuser(), "select count(*) as n from outbox_events where type = 'dashboards.dashboard.changed'")[0]['n'])->toBe(1);
});

it('refuses removing with a stale revision (409) and removing a Mandatory item (403), leaving the row', function () {
    [$workspace, , $dashboard] = db9Setup();
    $block = dbBlock($workspace);
    $locked = dbBlock($workspace, 'Locked');
    dbItem($workspace, $dashboard, $block);
    dbItem($workspace, $dashboard, $locked, ['col' => 3, 'mandatory' => 'true']);

    $this->deleteJson(db9Url($dashboard, "blocks/{$block}"), ['expected_revision' => 9], DB4_HEADERS)->assertStatus(409)->assertJsonPath('error.revision', 0);

    $forbidden = $this->deleteJson(db9Url($dashboard, "blocks/{$locked}"), ['expected_revision' => 0], DB4_HEADERS)->assertForbidden();

    expect($forbidden->json('error.code'))->toBe('dashboards.item_locked')
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from dashboard_items')[0]['n'])->toBe(2)
        ->and(db9Revision($dashboard))->toBe(0);
});

it('keeps DELETE away from the web role: items go only through the definer function, scoped to the Workspace and dashboard', function () {
    [$workspace, , $dashboard] = db9Setup();
    $block = dbBlock($workspace);
    $item = dbItem($workspace, $dashboard, $block);

    expect(Cluster::rows(Cluster::superuser(), "select has_table_privilege('app', 'public.dashboard_items', 'DELETE') as p")[0]['p'])->toBeFalse()
        ->and(fn () => Cluster::inWorkspace(Cluster::directApp(), $workspace, fn ($pdo) => $pdo->exec('DELETE FROM dashboard_items')))->toThrow(PDOException::class, 'permission denied');

    // Another dashboard's id, or no Workspace context, removes nothing.
    $foreign = Cluster::seedDashboard($workspace);
    $removed = Cluster::inWorkspace(Cluster::directApp(), $workspace, fn ($pdo) => Cluster::rows($pdo, 'select dashboards_remove_item(?::uuid, ?::uuid) as r', [$foreign, $item])[0]['r']);

    expect($removed)->toBeFalse()
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from dashboard_items where id = ?', [$item])[0]['n'])->toBe(1)
        ->and(fn () => Cluster::rows(Cluster::directApp(), 'select dashboards_remove_item(?::uuid, ?::uuid)', [$dashboard, $item]))->toThrow(PDOException::class);
});

it('answers 404 for another member\'s dashboard on add and remove', function () {
    [$workspace] = db9Setup();
    $theirs = Cluster::seedDashboard($workspace);
    $block = dbBlock($workspace);

    $this->postJson(db9Url($theirs), ['block_id' => $block, 'expected_revision' => 0], DB4_HEADERS)->assertNotFound();
    $this->deleteJson(db9Url($theirs, "blocks/{$block}"), ['expected_revision' => 0], DB4_HEADERS)->assertNotFound();
});

// ---- Story 4.10: RestoreItem ---------------------------------------------------------------------------------------------------------------------

function db10Restore(string $dashboard, string $block, array $body)
{
    return test()->postJson("/api/v1/dashboards/{$dashboard}/blocks/{$block}/restore", $body, DB4_HEADERS);
}

it('puts a removed Block back at its remembered spot when it is free, with its minimized state, moving nothing', function () {
    [$workspace, , $dashboard] = db9Setup();
    $block = dbBlock($workspace);
    $other = dbBlock($workspace, 'Other');
    $kept = dbItem($workspace, $dashboard, $other, ['col' => 0, 'row' => 0, 'width' => 3]);

    $response = db10Restore($dashboard, $block, ['geometry' => ['col' => 6, 'row' => 2, 'width' => 4, 'height' => 'medium'], 'minimized' => true, 'expected_revision' => 0])->assertCreated();

    expect($response->json('data.item.geometry'))->toBe(['col' => 6, 'row' => 2, 'width' => 4, 'height' => 'medium'])
        ->and($response->json('data.item.minimized'))->toBeTrue()
        ->and($response->json('data.revision'))->toBe(1)
        ->and(Cluster::rows(Cluster::superuser(), 'select col, row from dashboard_items where id = ?', [$kept])[0])->toBe(['col' => 0, 'row' => 0]);

    $event = json_decode(Cluster::rows(Cluster::superuser(), "select data from outbox_events where type = 'dashboards.dashboard.changed'")[0]['data'], true);

    expect($event['command'])->toBe('restore_item');
});

it('uses the first free spot at the remembered size when the original is taken, and the default size when nothing was remembered', function () {
    [$workspace, , $dashboard] = db9Setup();
    $taken = dbBlock($workspace, 'Taken');
    dbItem($workspace, $dashboard, $taken, ['col' => 0, 'row' => 0, 'width' => 6]);
    $block = dbBlock($workspace);
    $plain = dbBlock($workspace, 'Plain');

    $moved = db10Restore($dashboard, $block, ['geometry' => ['col' => 0, 'row' => 0, 'width' => 6, 'height' => 'small'], 'expected_revision' => 0])->assertCreated();

    expect($moved->json('data.item.geometry'))->toBe(['col' => 6, 'row' => 0, 'width' => 6, 'height' => 'small']);

    $default = db10Restore($dashboard, $plain, ['expected_revision' => 1])->assertCreated();

    expect($default->json('data.item.geometry.width'))->toBe(3)->and($default->json('data.item.minimized'))->toBeFalse();
});

it('clamps a remembered size to the Block\'s own limits and refuses what cannot be restored', function () {
    [$workspace, , $dashboard] = db9Setup();
    $narrow = dbBlock($workspace, 'Narrow');
    $gone = dbBlock($workspace, 'Gone', published: false);

    $clamped = db10Restore($dashboard, $narrow, ['geometry' => ['col' => 0, 'row' => 0, 'width' => 12, 'height' => 'large'], 'expected_revision' => 0])->assertCreated();

    // dbBlock allows 3 to 6 columns and small to medium.
    expect($clamped->json('data.item.geometry'))->toBe(['col' => 0, 'row' => 0, 'width' => 6, 'height' => 'medium']);

    db10Restore($dashboard, $gone, ['expected_revision' => 1])->assertStatus(422)->assertJsonPath('error.code', 'dashboards.block_unavailable');
    db10Restore($dashboard, (string) Str::uuid7(), ['expected_revision' => 1])->assertNotFound();
    db10Restore($dashboard, dbBlock($workspace, 'Late'), ['expected_revision' => 0])->assertStatus(409)->assertJsonPath('error.revision', 1);
    db10Restore($dashboard, dbBlock($workspace, 'Bad'), ['geometry' => ['col' => 11, 'row' => 0, 'width' => 5, 'height' => 'small'], 'expected_revision' => 1])->assertStatus(422);
});

it('is idempotent: restoring a Block that is already back returns it', function () {
    [$workspace, , $dashboard] = db9Setup();
    $block = dbBlock($workspace);

    $first = db10Restore($dashboard, $block, ['expected_revision' => 0])->assertCreated()->json('data.item.item_id');
    $again = db10Restore($dashboard, $block, ['expected_revision' => 0])->assertOk();

    expect($again->json('data.item.item_id'))->toBe($first)
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from dashboard_items')[0]['n'])->toBe(1);
});

// ---- Story 4.11: MoveResizeItems -----------------------------------------------------------------------------------------------------------------

function db11Put(string $dashboard, array $items, int $revision)
{
    return test()->putJson("/api/v1/dashboards/{$dashboard}/layout", ['items' => $items, 'expected_revision' => $revision], DB4_HEADERS);
}

function db11Geometry(string $item): array
{
    return Cluster::rows(Cluster::superuser(), 'select col, row, width, height from dashboard_items where id = ?', [$item])[0];
}

it('moves and resizes items, pushes what they overlap down, bumps the revision once and returns the saved layout', function () {
    [$workspace, , $dashboard] = db9Setup();
    $a = dbItem($workspace, $dashboard, dbBlock($workspace, 'A'), ['col' => 0, 'row' => 0, 'width' => 3]);
    $b = dbItem($workspace, $dashboard, dbBlock($workspace, 'B'), ['col' => 6, 'row' => 0, 'width' => 3]);
    $c = dbItem($workspace, $dashboard, dbBlock($workspace, 'C'), ['col' => 0, 'row' => 6, 'width' => 3]);

    $response = db11Put($dashboard, [['item_id' => $a, 'col' => 6, 'row' => 0, 'width' => 4, 'height' => 'medium']], 0)->assertOk();

    expect($response->json('data.revision'))->toBe(1)
        ->and(db11Geometry($a))->toBe(['col' => 6, 'row' => 0, 'width' => 4, 'height' => 'medium'])
        // B sat under A's new place: pushed below A's six rows, and C, untouched, stays.
        ->and(db11Geometry($b))->toBe(['col' => 6, 'row' => 6, 'width' => 3, 'height' => 'small'])
        ->and(db11Geometry($c))->toBe(['col' => 0, 'row' => 6, 'width' => 3, 'height' => 'small'])
        ->and(db9Revision($dashboard))->toBe(1)
        ->and(array_column($response->json('data.items'), 'item_id'))->toContain($a, $b, $c);

    $event = json_decode(Cluster::rows(Cluster::superuser(), "select data from outbox_events where type = 'dashboards.dashboard.changed'")[0]['data'], true);

    expect($event)->toMatchArray(['command' => 'move_resize_items', 'revision' => 1, 'items' => 1]);
});

it('clamps a size to the Block\'s limits and keeps the size of a Block that does not allow resize', function () {
    [$workspace, , $dashboard] = db9Setup();
    $wide = dbItem($workspace, $dashboard, dbBlock($workspace, 'Wide'), ['col' => 0, 'row' => 0, 'width' => 3]);
    $fixed = dbItem($workspace, $dashboard, dbBlock($workspace, 'Fixed', ['chrome' => ['allow_resize' => false]]), ['col' => 6, 'row' => 0, 'width' => 3]);

    db11Put($dashboard, [
        ['item_id' => $wide, 'col' => 0, 'row' => 0, 'width' => 12, 'height' => 'large'],
        ['item_id' => $fixed, 'col' => 6, 'row' => 4, 'width' => 8, 'height' => 'large'],
    ], 0)->assertOk();

    // dbBlock allows 3 to 6 columns and small to medium.
    expect(db11Geometry($wide))->toBe(['col' => 0, 'row' => 0, 'width' => 6, 'height' => 'medium'])
        ->and(db11Geometry($fixed))->toBe(['col' => 6, 'row' => 4, 'width' => 3, 'height' => 'small']);
});

it('refuses an unknown or repeated item with 422 and a stale revision with 409, changing nothing', function () {
    [$workspace, , $dashboard] = db9Setup();
    $a = dbItem($workspace, $dashboard, dbBlock($workspace, 'A'));
    $foreign = Cluster::seedDashboardItem($workspace);

    $unknown = db11Put($dashboard, [['item_id' => $foreign, 'col' => 0, 'row' => 0, 'width' => 3, 'height' => 'small']], 0)->assertStatus(422);

    expect($unknown->json('error.code'))->toBe('dashboards.invalid_layout');

    db11Put($dashboard, [['item_id' => (string) Str::uuid7(), 'col' => 0, 'row' => 0, 'width' => 3, 'height' => 'small']], 0)->assertStatus(422);
    db11Put($dashboard, [
        ['item_id' => $a, 'col' => 3, 'row' => 0, 'width' => 3, 'height' => 'small'],
        ['item_id' => $a, 'col' => 6, 'row' => 0, 'width' => 3, 'height' => 'small'],
    ], 0)->assertStatus(422);

    $stale = db11Put($dashboard, [['item_id' => $a, 'col' => 6, 'row' => 0, 'width' => 3, 'height' => 'small']], 7)->assertStatus(409);

    expect($stale->json('error.revision'))->toBe(0)
        ->and(db11Geometry($a))->toBe(['col' => 0, 'row' => 0, 'width' => 3, 'height' => 'small'])
        ->and(db9Revision($dashboard))->toBe(0)
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from dashboard_items')[0]['n'])->toBe(2);
});

it('validates the geometry shape and keeps another member\'s dashboard out of reach', function () {
    [$workspace, , $dashboard] = db9Setup();
    $a = dbItem($workspace, $dashboard, dbBlock($workspace, 'A'));

    foreach ([
        ['col' => 12, 'row' => 0, 'width' => 3, 'height' => 'small'],
        ['col' => 0, 'row' => -1, 'width' => 3, 'height' => 'small'],
        ['col' => 0, 'row' => 0, 'width' => 5, 'height' => 'small'],
        ['col' => 0, 'row' => 0, 'width' => 3, 'height' => 'huge'],
    ] as $geometry) {
        db11Put($dashboard, [['item_id' => $a] + $geometry], 0)->assertStatus(422);
    }

    $theirs = Cluster::seedDashboard($workspace);

    db11Put($theirs, [['item_id' => $a, 'col' => 0, 'row' => 0, 'width' => 3, 'height' => 'small']], 0)->assertNotFound();
});

it('lets a Mandatory item move and resize', function () {
    [$workspace, , $dashboard] = db9Setup();
    $locked = dbItem($workspace, $dashboard, dbBlock($workspace, 'Locked'), ['mandatory' => 'true']);

    db11Put($dashboard, [['item_id' => $locked, 'col' => 3, 'row' => 2, 'width' => 4, 'height' => 'medium']], 0)->assertOk();

    expect(db11Geometry($locked))->toBe(['col' => 3, 'row' => 2, 'width' => 4, 'height' => 'medium']);
});

// ---- Story 4.12: SetCompactOrder -----------------------------------------------------------------------------------------------------------------

function db12Put(string $dashboard, array $order, int $revision)
{
    return test()->putJson("/api/v1/dashboards/{$dashboard}/compact-order", ['order' => $order, 'expected_revision' => $revision], DB4_HEADERS);
}

it('stores the stacking order apart from the desktop positions, bumps the revision and returns it', function () {
    [$workspace, , $dashboard] = db9Setup();
    $a = dbItem($workspace, $dashboard, dbBlock($workspace, 'A'), ['col' => 0, 'row' => 0]);
    $b = dbItem($workspace, $dashboard, dbBlock($workspace, 'B'), ['col' => 3, 'row' => 0]);
    $c = dbItem($workspace, $dashboard, dbBlock($workspace, 'C'), ['col' => 6, 'row' => 0]);

    expect($this->getJson("/api/v1/dashboards/{$dashboard}", DB4_HEADERS)->json('data.compact_order'))->toBeNull();

    $response = db12Put($dashboard, [$c, $a, $b], 0)->assertOk();

    expect($response->json('data.compact_order'))->toBe([$c, $a, $b])
        ->and($response->json('data.revision'))->toBe(1)
        ->and(db11Geometry($a))->toBe(['col' => 0, 'row' => 0, 'width' => 3, 'height' => 'small'])
        ->and(db11Geometry($c))->toBe(['col' => 6, 'row' => 0, 'width' => 3, 'height' => 'small'])
        ->and($this->getJson("/api/v1/dashboards/{$dashboard}", DB4_HEADERS)->json('data.compact_order'))->toBe([$c, $a, $b]);

    $event = json_decode(Cluster::rows(Cluster::superuser(), "select data from outbox_events where type = 'dashboards.dashboard.changed'")[0]['data'], true);

    expect($event['command'])->toBe('set_compact_order');
});

it('refuses an order that is not exactly the dashboard\'s items and a stale revision', function () {
    [$workspace, , $dashboard] = db9Setup();
    $a = dbItem($workspace, $dashboard, dbBlock($workspace, 'A'));
    $b = dbItem($workspace, $dashboard, dbBlock($workspace, 'B'), ['col' => 3]);

    db12Put($dashboard, [$a], 0)->assertStatus(422)->assertJsonPath('error.code', 'dashboards.invalid_layout');
    db12Put($dashboard, [$a, $b, (string) Str::uuid7()], 0)->assertStatus(422);
    db12Put($dashboard, [$a, $a], 0)->assertStatus(422);
    db12Put($dashboard, [$b, $a], 3)->assertStatus(409)->assertJsonPath('error.revision', 0);

    expect(db9Revision($dashboard))->toBe(0);
});

it('appends a new item to an existing order and drops a removed one from it', function () {
    [$workspace, , $dashboard] = db9Setup();
    $a = dbItem($workspace, $dashboard, dbBlock($workspace, 'A'));
    $b = dbItem($workspace, $dashboard, dbBlock($workspace, 'B'), ['col' => 3]);
    db12Put($dashboard, [$b, $a], 0)->assertOk();

    $new = dbBlock($workspace, 'New');
    $added = $this->postJson(db9Url($dashboard), ['block_id' => $new, 'expected_revision' => 1], DB4_HEADERS)->assertCreated()->json('data.item.item_id');

    expect($this->getJson("/api/v1/dashboards/{$dashboard}", DB4_HEADERS)->json('data.compact_order'))->toBe([$b, $a, $added]);

    $blockOfA = Cluster::rows(Cluster::superuser(), 'select block_id from dashboard_items where id = ?', [$a])[0]['block_id'];
    $this->deleteJson(db9Url($dashboard, "blocks/{$blockOfA}"), ['expected_revision' => 2], DB4_HEADERS)->assertOk();

    expect($this->getJson("/api/v1/dashboards/{$dashboard}", DB4_HEADERS)->json('data.compact_order'))->toBe([$b, $added]);
});
