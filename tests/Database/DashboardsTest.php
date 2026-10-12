<?php

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
