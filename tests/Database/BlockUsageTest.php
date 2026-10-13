<?php

use App\Modules\Blocks\Application\RebuildBlockUsage;
use App\Modules\Blocks\Application\TrackBlockUsage;
use App\Modules\Blocks\Contracts\BlockImpact;
use App\Platform\Outbox\OutboxEnvelope;
use App\Platform\Outbox\OutboxRelay;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 6.2: block_usage, fed by the dashboards' change events and read through BlockImpact. Helpers (dr5*) come from DashboardResultsTest.
function bu(): array
{
    return Cluster::rows(Cluster::superuser(), 'select membership_id, block_id, kind, items from block_usage order by block_id, membership_id');
}

function buAddBlock(array $w, string $dashboard)
{
    return test()->postJson("/api/v1/dashboards/{$dashboard}/blocks", ['block_id' => $w['block2'], 'expected_revision' => 0], DR5_HEADERS);
}

/** A second published Block in the Workspace of the world, with its Access subject. */
function buSecondBlock(array $w): string
{
    $block = Cluster::seedBlock($w['ws']);
    Cluster::seedAccessSubject($w['ws'], $block);
    $version = Cluster::seedBlockVersion($w['ws'], [
        'block_id' => $block, 'state' => 'published', 'name' => 'B', 'prepared_at' => '2026-10-12 09:00:00+00', 'published_at' => '2026-10-12 09:00:01+00',
        'config' => json_encode(['schema_version' => 1, 'type_key' => 'kpi-card', 'contract_version' => 1, 'sizes' => ['min_width' => 3, 'max_width' => 12, 'width' => 3, 'min_height' => 'small', 'max_height' => 'large', 'height' => 'small']]),
    ]);
    Cluster::superuser()->prepare('UPDATE blocks SET current_version_id = ? WHERE id = ?')->execute([$version, $block]);

    return $block;
}

function buDashboard(array $w, string $name): string
{
    $id = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO dashboards (id, workspace_id, membership_id, name, is_overview, revision) VALUES (?, ?, ?, ?, false, 0)')->execute([$id, $w['ws'], $w['membership'], $name]);

    return $id;
}

it('counts a member once however many dashboards hold the Block, and releases at zero', function () {
    $w = dr5World();
    $w['block2'] = buSecondBlock($w);
    $second = buDashboard($w, 'Second');
    $third = buDashboard($w, 'Third');

    foreach ([$w['dashboard'], $second, $third] as $dashboard) {
        buAddBlock($w, $dashboard)->assertCreated();
    }

    app(OutboxRelay::class)->relay();

    $impact = app(BlockImpact::class)->forBlock($w['ws'], $w['block2']);

    expect($impact)->toBe(['users' => 1, 'templates' => []])
        ->and(array_values(array_filter(bu(), fn ($r) => $r['block_id'] === $w['block2']))[0]['items'])->toBe(3);

    foreach ([$w['dashboard'], $second, $third] as $dashboard) {
        test()->deleteJson("/api/v1/dashboards/{$dashboard}/blocks/{$w['block2']}", ['expected_revision' => 1], DR5_HEADERS)->assertOk();
    }

    app(OutboxRelay::class)->relay();

    expect(app(BlockImpact::class)->forBlock($w['ws'], $w['block2']))->toBe(['users' => 0, 'templates' => []])
        ->and(array_filter(bu(), fn ($r) => $r['block_id'] === $w['block2']))->toBe([]);
});

it('drops an event at or below the last sequence applied for its dashboard', function () {
    $w = dr5World();
    $w['block2'] = buSecondBlock($w);
    buAddBlock($w, $w['dashboard'])->assertCreated();
    app(OutboxRelay::class)->relay();

    $before = bu();
    $row = Cluster::rows(Cluster::superuser(), "select * from outbox_events where type = 'dashboards.dashboard.changed' order by subject_seq desc limit 1")[0];
    $event = OutboxEnvelope::fromRow($row);
    $applied = app(WorkspaceTransaction::class)->run($w['ws'], fn () => TrackBlockUsage::apply($event));

    expect($applied)->toBeFalse()->and(bu())->toBe($before);
});

it('is rebuilt from the events to the same counts', function () {
    $w = dr5World();
    $w['block2'] = buSecondBlock($w);
    $second = buDashboard($w, 'Second');
    buAddBlock($w, $w['dashboard'])->assertCreated();
    buAddBlock($w, $second)->assertCreated();
    test()->deleteJson("/api/v1/dashboards/{$second}/blocks/{$w['block2']}", ['expected_revision' => 1], DR5_HEADERS)->assertOk();
    app(OutboxRelay::class)->relay();

    $live = bu();

    // Damage the model, then make it again from the events.
    Cluster::superuser()->exec('DELETE FROM block_usage; DELETE FROM block_usage_cursors');
    expect(bu())->toBe([]);

    $events = array_map(
        fn (array $row) => OutboxEnvelope::fromRow($row),
        Cluster::rows(Cluster::superuser(), "select * from outbox_events where workspace_id = ? and type = 'dashboards.dashboard.changed' order by subject, subject_seq", [$w['ws']]),
    );
    $applied = app(RebuildBlockUsage::class)->replay($w['ws'], $events);

    expect($applied)->toBeGreaterThan(0)->and(bu())->toBe($live);
});

it('shows another Workspace nothing of a Block', function () {
    $w = dr5World();
    $w['block2'] = buSecondBlock($w);
    buAddBlock($w, $w['dashboard'])->assertCreated();
    app(OutboxRelay::class)->relay();

    $other = Cluster::workspace('Other');

    expect(app(BlockImpact::class)->forBlock($other, $w['block2']))->toBe(['users' => 0, 'templates' => []])
        ->and(app(BlockImpact::class)->forBlock($w['ws'], $w['block2'])['users'])->toBe(1);
});
