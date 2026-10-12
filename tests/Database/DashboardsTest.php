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
