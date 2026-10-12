<?php

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 4.16 against the real PostgreSQL: list, create, rename, duplicate and delete a member's own dashboards, scoped to the member and the
// Workspace, with the Overview protected and the Block viewers kept right through per-item events.
beforeEach(fn () => $this->withoutVite());

const DM_PASSWORD = 'a-long-enough-password';
const DM_HEADERS = ['Referer' => 'http://localhost:8000'];

/** @return array{0: string, 1: string} workspace id, membership id of the signed-in member */
function dmSetup(string $email = 'ada@example.test'): array
{
    $workspace = Cluster::workspace('Acme');
    $user = Cluster::user($email);
    Cluster::superuser()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([Hash::make(DM_PASSWORD), $user]);
    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspace, $user, 'user', 'active']);
    test()->postJson('/login', ['email' => $email, 'password' => DM_PASSWORD, 'role' => 'user'])->assertOk();

    return [$workspace, $membership];
}

function dmBlock(string $workspace, bool $published = true, bool $visible = true): string
{
    $block = Cluster::seedBlock($workspace);
    $config = [
        'schema_version' => 1, 'type_key' => 'kpi-card', 'contract_version' => 1,
        'source' => ['data_source_id' => (string) Str::uuid7(), 'endpoint_id' => (string) Str::uuid7(), 'bindings' => []],
        'query_plan' => ['v' => 1, 'extract' => ['fields' => ['rev' => '$.x']], 'shape' => ['headline_value' => 'rev']],
        'chrome' => ['description' => 'Net', 'icon' => 'trending-up', 'show_header' => true, 'show_subtitle' => true, 'allow_minimize' => true],
        'sizes' => ['min_width' => 3, 'max_width' => 6, 'width' => 3, 'min_height' => 'small', 'max_height' => 'medium', 'height' => 'small'],
        'refresh_interval' => 60,
    ];

    if ($published) {
        $version = Cluster::seedBlockVersion($workspace, [
            'block_id' => $block, 'state' => 'published', 'name' => 'Block '.$block, 'config' => json_encode($config),
            'prepared_at' => '2026-10-12 09:00:00+00', 'published_at' => '2026-10-12 09:00:01+00',
        ]);
        Cluster::superuser()->prepare('UPDATE blocks SET current_version_id = ? WHERE id = ?')->execute([$version, $block]);
    }

    if ($visible) {
        Cluster::seedAccessSubject($workspace, $block);
    }

    return $block;
}

function dmItem(string $workspace, string $dashboard, string $block, array $columns = []): string
{
    $row = $columns + ['id' => (string) Str::uuid7(), 'workspace_id' => $workspace, 'dashboard_id' => $dashboard, 'block_id' => $block, 'col' => 0, 'row' => 0, 'width' => 3, 'height' => 'small'];
    Cluster::superuser()->prepare('INSERT INTO dashboard_items ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')')
        ->execute(array_values($row));

    return (string) $row['id'];
}

function dmEvents(string $command): array
{
    $rows = Cluster::rows(Cluster::superuser(), "select data from outbox_events where type = 'dashboards.dashboard.changed'");

    return array_values(array_filter(
        array_map(fn (array $r): array => json_decode($r['data'], true), $rows),
        fn (array $d): bool => $d['command'] === $command,
    ));
}

it('lists the Overview first, created on first use, then the others by creation with their item counts', function () {
    [$workspace] = dmSetup();

    $first = $this->postJson('/api/v1/dashboards', ['name' => 'Sales'], DM_HEADERS)->assertCreated()->json('data');
    $this->postJson('/api/v1/dashboards', ['name' => 'Ops'], DM_HEADERS)->assertCreated();
    dmItem($workspace, $first['dashboard_id'], dmBlock($workspace));

    $list = $this->getJson('/api/v1/dashboards', DM_HEADERS)->assertOk()->json('data');

    expect(array_column($list, 'name'))->toBe(['Overview', 'Sales', 'Ops'])
        ->and(array_column($list, 'is_overview'))->toBe([true, false, false])
        ->and(array_column($list, 'item_count'))->toBe([0, 1, 0]);
});

it('creates an empty dashboard at revision 0, trimmed, and refuses an empty, over-long or control-character name with a field error', function () {
    dmSetup();

    $created = $this->postJson('/api/v1/dashboards', ['name' => '  Sales  '], DM_HEADERS)->assertCreated()->json('data');
    $shown = $this->getJson("/api/v1/dashboards/{$created['dashboard_id']}", DM_HEADERS)->assertOk()->json('data');

    expect($created['name'])->toBe('Sales')->and($created['is_overview'])->toBeFalse()
        ->and($shown['revision'])->toBe(0)->and($shown['items'])->toBe([])->and($shown['is_overview'])->toBeFalse();

    foreach (['', '   ', str_repeat('a', 121), "bad\u{0007}name", "zero\u{200B}width"] as $name) {
        $this->postJson('/api/v1/dashboards', ['name' => $name], DM_HEADERS)->assertUnprocessable()->assertJsonPath('error.code', 'dashboards.invalid_name')->assertJsonPath('error.fields.name.0', 'msg:dashboard-name-invalid');
    }

    $this->postJson('/api/v1/dashboards', ['name' => str_repeat('a', 120)], DM_HEADERS)->assertCreated();
});

it('renames a dashboard without moving its revision and emits the change', function () {
    dmSetup();
    $id = $this->postJson('/api/v1/dashboards', ['name' => 'Sales'], DM_HEADERS)->json('data.dashboard_id');

    $this->putJson("/api/v1/dashboards/{$id}/name", ['name' => 'Revenue'], DM_HEADERS)->assertOk()->assertJsonPath('data.name', 'Revenue');

    $shown = $this->getJson("/api/v1/dashboards/{$id}", DM_HEADERS)->json('data');

    expect($shown['name'])->toBe('Revenue')->and($shown['revision'])->toBe(0)
        ->and(dmEvents('rename'))->toHaveCount(1);

    $this->putJson("/api/v1/dashboards/{$id}/name", ['name' => ''], DM_HEADERS)->assertUnprocessable()->assertJsonPath('error.code', 'dashboards.invalid_name')->assertJsonPath('error.fields.name.0', 'msg:dashboard-name-invalid');
    expect($this->getJson("/api/v1/dashboards/{$id}", DM_HEADERS)->json('data.name'))->toBe('Revenue');
});

it('duplicates the items, positions, sizes, minimized, Mandatory, Date Range and period choices with its own revision, and counts what it omits', function () {
    [$workspace] = dmSetup();
    $source = $this->postJson('/api/v1/dashboards', ['name' => 'Sales'], DM_HEADERS)->json('data.dashboard_id');
    $a = dmBlock($workspace);
    $b = dmBlock($workspace);
    $gone = dmBlock($workspace, true, false);
    $unpublished = dmBlock($workspace, false);

    dmItem($workspace, $source, $a, ['col' => 3, 'row' => 2, 'width' => 4, 'height' => 'medium', 'minimized' => true, 'mandatory' => true, 'period_selection' => 'this_month']);
    dmItem($workspace, $source, $b, ['col' => 0, 'row' => 0]);
    dmItem($workspace, $source, $gone, ['col' => 6, 'row' => 0]);
    dmItem($workspace, $source, $unpublished, ['col' => 9, 'row' => 0]);
    Cluster::superuser()->prepare("UPDATE dashboards SET revision = 7, date_range = 'last_week' WHERE id = ?")->execute([$source]);

    $copy = $this->postJson("/api/v1/dashboards/{$source}/duplicate", [], DM_HEADERS)->assertCreated()->json('data');
    $shown = $this->getJson("/api/v1/dashboards/{$copy['dashboard_id']}", DM_HEADERS)->assertOk()->json('data');
    $byBlock = array_column($shown['items'], null, 'block_id');

    expect($copy['omitted'])->toBe(2)->and($copy['name'])->toBe('Sales (copy)')
        ->and($shown['revision'])->toBe(0)->and($shown['is_overview'])->toBeFalse()
        ->and($shown['date_range'])->toBe(['range' => 'last_week', 'from' => null, 'to' => null])
        ->and(array_keys($byBlock))->toEqualCanonicalizing([$a, $b])
        ->and($byBlock[$a]['geometry'])->toBe(['col' => 3, 'row' => 2, 'width' => 4, 'height' => 'medium'])
        ->and($byBlock[$a]['minimized'])->toBeTrue()
        ->and($byBlock[$a]['period']['selection'])->toBe('this_month');

    $mandatory = Cluster::rows(Cluster::superuser(), 'select block_id, mandatory from dashboard_items where dashboard_id = ?', [$copy['dashboard_id']]);

    expect(array_column($mandatory, 'mandatory', 'block_id')[$a])->toBeTrue()
        ->and(dmEvents('add_item'))->toHaveCount(2)
        ->and($this->getJson("/api/v1/dashboards/{$source}", DM_HEADERS)->json('data.revision'))->toBe(7)
        ->and(count($this->getJson("/api/v1/dashboards/{$source}", DM_HEADERS)->json('data.items')))->toBe(4);
});

it('deletes a dashboard with its items and releases each Block viewer, and refuses the Overview', function () {
    [$workspace] = dmSetup();
    $overview = $this->getJson('/api/v1/dashboards', DM_HEADERS)->json('data.0.dashboard_id');
    $id = $this->postJson('/api/v1/dashboards', ['name' => 'Sales'], DM_HEADERS)->json('data.dashboard_id');
    dmItem($workspace, $id, dmBlock($workspace));
    dmItem($workspace, $id, dmBlock($workspace), ['col' => 3]);

    $this->deleteJson("/api/v1/dashboards/{$id}", [], DM_HEADERS)->assertOk();

    expect(Cluster::rows(Cluster::superuser(), 'select count(*) as n from dashboards where id = ?', [$id])[0]['n'])->toBe(0)
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from dashboard_items where dashboard_id = ?', [$id])[0]['n'])->toBe(0)
        ->and(dmEvents('remove_item'))->toHaveCount(2)
        ->and(dmEvents('delete'))->toHaveCount(1);

    $this->getJson("/api/v1/dashboards/{$id}", DM_HEADERS)->assertNotFound();
    $this->deleteJson("/api/v1/dashboards/{$overview}", [], DM_HEADERS)->assertUnprocessable()->assertJsonPath('error.code', 'dashboards.overview_undeletable');
    $this->getJson("/api/v1/dashboards/{$overview}", DM_HEADERS)->assertOk();
});

it('keeps another member\'s and another Workspace\'s dashboards out of reach of every command', function () {
    [$workspace] = dmSetup();
    $user = Cluster::user('bob@example.test');
    $other = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$other, $workspace, $user, 'user', 'active']);
    $elsewhere = Cluster::workspace('Elsewhere');
    $strangers = [
        Cluster::seedDashboard($workspace, $other),
        Cluster::seedDashboard($elsewhere),
    ];

    foreach ($strangers as $id) {
        $this->putJson("/api/v1/dashboards/{$id}/name", ['name' => 'Mine now'], DM_HEADERS)->assertNotFound();
        $this->postJson("/api/v1/dashboards/{$id}/duplicate", [], DM_HEADERS)->assertNotFound();
        $this->deleteJson("/api/v1/dashboards/{$id}", [], DM_HEADERS)->assertNotFound();
        $this->get("/dashboards/{$id}")->assertNotFound();
    }

    expect(array_column($this->getJson('/api/v1/dashboards', DM_HEADERS)->json('data'), 'name'))->toBe(['Overview']);
});

it('opens a member\'s own dashboard by address with its id and revision', function () {
    dmSetup();
    $id = $this->postJson('/api/v1/dashboards', ['name' => 'Sales'], DM_HEADERS)->json('data.dashboard_id');

    $this->get("/dashboards/{$id}")->assertOk()->assertInertia(fn ($page) => $page->component('Dashboard')->where('dashboard_id', $id)->where('revision', 0));
    $this->get('/dashboards')->assertOk()->assertInertia(fn ($page) => $page->component('MyDashboards'));
});
