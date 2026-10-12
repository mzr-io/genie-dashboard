<?php

use App\Models\User;
use App\Modules\Connector\Infrastructure\CurlClient;
use App\Modules\Connector\Infrastructure\CurlResult;
use App\Modules\Results\Application\BroadcastResultUpdated;
use App\Modules\Results\Application\ComputeResults;
use App\Modules\Results\Application\ComputeResultsJob;
use App\Modules\Results\Contracts\ResultStore;
use App\Modules\Results\Contracts\ResultUpdated;
use App\Modules\Results\Infrastructure\ValkeyResultStore;
use App\Platform\Outbox\OutboxEnvelope;
use App\Platform\Outbox\OutboxRelay;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Database\Support\Cluster;
use Tests\Database\Support\RespClient;

// Story 4.5 against the real PostgreSQL and Valkey: GET /api/v1/dashboards/{id}/results answers from what is stored, immediately and without a source;
// a miss queues one compute, access is re-checked per item, and a range the Blocks do not offer is a 422.
const DR5_HEADERS = ['Referer' => 'http://localhost:8000'];

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();

    $this->digestFile = tempnam(sys_get_temp_dir(), 'dashflow-digest-key');
    file_put_contents($this->digestFile, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    $this->valkey = new RespClient;
    $this->valkey->command('FLUSHALL');
    app()->instance(ResultStore::class, new ValkeyResultStore($this->valkey, fn () => 600));

    config([
        'dashflow.secrets.digest_key_path.value' => $this->digestFile,
        'dashflow.tunables.sync.refresh_intervals.value' => '60,300,900',
        'dashflow.tunables.sync.hot_window.value' => '600',
        'dashflow.tunables.budgets.max_hot_keys_per_workspace.value' => null,
        'dashflow.tunables.budgets.max_new_cold_keys_per_membership_per_hour.value' => null,
        'dashflow.tunables.guards.max_bytes.value' => '5000',
        'dashflow.tunables.guards.depth_limit.value' => '10',
        'dashflow.preview.membership_limit.value' => '100',
        'dashflow.preview.window_seconds.value' => '60',
        'dashflow.preview.max_extracted_values.value' => '1000',
        'dashflow.preview.max_evaluations.value' => '1000',
        'dashflow.preview.max_wall_ms.value' => '5000',
        'dashflow.results.ttl.value' => '600',
    ]);
});

afterEach(fn () => @unlink($this->digestFile));

/**
 * A signed-in member with a published kpi-card Block on their Overview, pointing at a real Data Source and Endpoint.
 *
 * @return array{ws: string, membership: string, dashboard: string, block: string, version: string, item: string}
 */
function dr5World(bool $userBound = false, bool $visible = true): array
{
    $ws = Cluster::workspace('Acme');
    $user = Cluster::user('ada-'.Str::random(6).'@example.test');
    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $ws, $user, 'admin', 'active']);
    Cluster::superuser()->prepare('INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
        ->execute([(string) Str::uuid7(), $ws, $membership, 'data_sources.manage']);
    Cluster::seedHostEntry($ws, 'api.example.com');
    $attribute = $userBound ? 'region' : null;

    if ($userBound) {
        Cluster::seedAttributeKey($ws, 'region');
    }

    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $ws, 'area' => 'user']);

    $source = Cluster::seedDataSource($ws, 'Sales API');
    $endpointId = (string) test()->withSession(['workspace_id' => $ws, 'area' => 'admin'])->postJson("/api/v1/admin/data-sources/{$source}/endpoints", [
        'method' => 'GET', 'path' => '/revenue', 'headers' => [], 'body_template' => null, 'read_only_query' => false, 'confirm_read_only' => false,
        'params' => $userBound
            ? [['name' => 'who', 'binding' => 'user_attribute', 'value' => $attribute]]
            : [['name' => 'from', 'binding' => 'period_start'], ['name' => 'to', 'binding' => 'period_end']],
    ], DR5_HEADERS)->assertCreated()->json('data.endpoint_id');
    test()->withSession(['workspace_id' => $ws, 'area' => 'user']);

    $block = Cluster::seedBlock($ws);
    $config = [
        'schema_version' => 1, 'type_key' => 'kpi-card', 'contract_version' => 1, 'period' => 'follow_dashboard',
        'source' => ['data_source_id' => $source, 'endpoint_id' => $endpointId, 'bindings' => [], 'refresh_interval' => 60, 'default_date_range' => ['preset' => 'this_month']],
        'query_plan' => ['v' => 1, 'extract' => ['fields' => ['rev' => '$.data.revenue']], 'shape' => ['headline_value' => 'rev']],
        'presentation' => [], 'fields' => ['record_path' => null, 'items' => []], 'refresh_interval' => 60,
        'chrome' => ['show_header' => true],
        'sizes' => ['min_width' => 3, 'max_width' => 12, 'width' => 3, 'min_height' => 'small', 'max_height' => 'large', 'height' => 'small'],
    ];
    $version = Cluster::seedBlockVersion($ws, [
        'block_id' => $block, 'state' => 'published', 'name' => 'Revenue', 'config' => json_encode($config),
        'prepared_at' => '2026-10-12 09:00:00+00', 'published_at' => '2026-10-12 09:00:01+00',
    ]);
    Cluster::superuser()->prepare('UPDATE blocks SET current_version_id = ? WHERE id = ?')->execute([$version, $block]);

    if ($visible) {
        Cluster::seedAccessSubject($ws, $block);
    }

    $dashboard = Cluster::seedDashboard($ws, $membership);
    $item = Cluster::seedDashboardItem($ws, $dashboard);
    Cluster::superuser()->prepare('UPDATE dashboard_items SET block_id = ? WHERE id = ?')->execute([$block, $item]);

    return ['ws' => $ws, 'membership' => $membership, 'dashboard' => $dashboard, 'block' => $block, 'version' => $version, 'item' => $item];
}

function dr5Results(array $w, string $query = ''): TestResponse
{
    return test()->getJson("/api/v1/dashboards/{$w['dashboard']}/results".$query, DR5_HEADERS);
}

/** Gives the target the Block watches a payload and computes its results. */
function dr5Hold(array $w, string $body, int $checkedAgo = 0): void
{
    $target = Cluster::rows(Cluster::superuser(), 'select t.id from sync_targets t join sync_subscriptions s on s.sync_target_id = t.id where s.block_version_id = ? limit 1', [$w['version']])[0]['id'];
    $payload = Cluster::seedRawBody($w['ws'], $target, $body);
    Cluster::superuser()->prepare('UPDATE sync_targets SET current_payload_id = ?, payload_seq = 1, last_success_at = now() - make_interval(secs => ?), payload_changed_at = now() WHERE id = ?')
        ->execute([$payload, $checkedAgo, $target]);
    app(WorkspaceTransaction::class)->run($w['ws'], fn () => app(ComputeResults::class)->forTarget($w['ws'], $target));
}

it('answers pending for a miss, and queues one compute only once a payload exists', function () {
    Queue::fake();
    $w = dr5World();

    $first = dr5Results($w)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data.items.0');

    expect($first)->toMatchArray(['item_id' => $w['item'], 'block_id' => $w['block'], 'state' => 'pending', 'render_payload' => null, 'result_etag' => null])
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from sync_subscriptions')[0]['n'])->toBe(1);
    Queue::assertNotPushed(ComputeResultsJob::class);

    // A current payload with no result: the next read queues a compute (once, however many reads follow).
    $target = Cluster::rows(Cluster::superuser(), 'select id from sync_targets')[0]['id'];
    $payload = Cluster::seedRawBody($w['ws'], $target, '{"data":{"revenue":42}}');
    Cluster::superuser()->prepare('UPDATE sync_targets SET current_payload_id = ?, payload_seq = 1, last_success_at = now(), payload_changed_at = now() WHERE id = ?')->execute([$payload, $target]);

    $second = dr5Results($w)->assertOk()->json('data.items.0');
    dr5Results($w)->assertOk();

    expect($second['state'])->toBe('pending');
    Queue::assertPushed(ComputeResultsJob::class, 1);
    Queue::assertPushed(ComputeResultsJob::class, fn (ComputeResultsJob $j) => $j->syncTargetId === $target && $j->workspaceId === $w['ws']);
});

it('returns a hit immediately with its data_as_of, last_success_at, stale_at and result_etag, and never calls the source', function () {
    $w = dr5World();
    dr5Results($w)->assertOk();
    dr5Hold($w, '{"data":{"revenue":1234.50}}', checkedAgo: 20);

    $curl = new class implements CurlClient
    {
        public int $calls = 0;

        public function execute(array $options, ?int $maxBytes = null): CurlResult
        {
            $this->calls++;

            throw new RuntimeException('the source is down');
        }
    };
    app()->instance(CurlClient::class, $curl);

    $item = dr5Results($w)->assertOk()->json('data.items.0');

    expect($item['state'])->toBe('ok')
        ->and($item['stale'])->toBeFalse()
        ->and($item['render_payload']['headline']['value'])->toBe('1234.50')
        ->and($item['result_etag'])->toMatch('/\A[0-9a-f]{64}\z/')
        ->and($item['data_as_of'])->not->toBeNull()
        ->and($item['last_success_at'])->not->toBeNull()
        ->and($item['stale_at'])->not->toBeNull()
        ->and($item['slot_states']['headline_value'])->toBe(['state' => 'ok'])
        ->and($curl->calls)->toBe(0);

    // The same data gives the same ETag, so the client can cache by it.
    expect(dr5Results($w)->json('data.items.0.result_etag'))->toBe($item['result_etag']);
});

it('marks a result stale after two intervals without a check and keeps its data', function () {
    $w = dr5World();
    dr5Results($w)->assertOk();
    dr5Hold($w, '{"data":{"revenue":7}}', checkedAgo: 500);

    $item = dr5Results($w)->assertOk()->json('data.items.0');

    expect($item['state'])->toBe('ok')->and($item['stale'])->toBeTrue()->and($item['render_payload']['headline']['value'])->toBe('7');
});

it('re-checks access for each item on the read and returns access_removed with no data', function () {
    $w = dr5World();
    dr5Results($w)->assertOk();
    dr5Hold($w, '{"data":{"revenue":9}}');

    expect(dr5Results($w)->json('data.items.0.state'))->toBe('ok');

    Cluster::superuser()->prepare('UPDATE access_subjects SET visible = false WHERE subject_id = ?')->execute([$w['block']]);

    $item = dr5Results($w)->assertOk()->json('data.items.0');

    expect($item)->toMatchArray(['state' => 'access_removed', 'render_payload' => null, 'result_etag' => null, 'data_as_of' => null]);
});

it('reports a Block with no published version as unpublished and a user-bound Block with no attribute value as unavailable', function () {
    $w = dr5World();
    Cluster::superuser()->prepare('UPDATE blocks SET current_version_id = NULL WHERE id = ?')->execute([$w['block']]);

    expect(dr5Results($w)->json('data.items.0.state'))->toBe('unpublished');

    $u = dr5World(userBound: true);

    // An attribute key the member has no value for: nothing is fetched or guessed.
    expect(dr5Results($u)->assertOk()->json('data.items.0.state'))->toBe('unavailable')
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from sync_targets where workspace_id = ?', [$u['ws']])[0]['n'])->toBe(0);
});

it('refuses a range the Blocks do not offer, a date and a non-string with 422, and another member\'s dashboard with 404', function () {
    $w = dr5World();

    foreach (['?range=last_year', '?range=2026-01-01', '?range[]=this_month'] as $query) {
        dr5Results($w, $query)->assertStatus(422);
    }

    dr5Results($w, '?range=this_month')->assertOk();

    $other = Cluster::seedDashboard($w['ws']);
    $this->getJson("/api/v1/dashboards/{$other}/results", DR5_HEADERS)->assertNotFound();
});

// ---- Story 4.6: Block chrome ----------------------------------------------------------------------------------------------------------------------

function dr6Url(array $w, string $tail): string
{
    return "/api/v1/dashboards/{$w['dashboard']}/items/{$w['item']}/{$tail}";
}

function dr6Revision(array $w): int
{
    return (int) Cluster::rows(Cluster::superuser(), 'select revision from dashboards where id = ?', [$w['dashboard']])[0]['revision'];
}

it('minimizes an item with expected_revision, bumps the revision once, emits the change and shows it in the layout', function () {
    $w = dr5World();

    $response = $this->putJson(dr6Url($w, 'minimized'), ['minimized' => true, 'expected_revision' => 0], DR5_HEADERS)->assertOk();

    expect($response->json('data'))->toBe(['item_id' => $w['item'], 'minimized' => true, 'revision' => 1])
        ->and(dr6Revision($w))->toBe(1);

    $events = Cluster::rows(Cluster::superuser(), "select data from outbox_events where type = 'dashboards.dashboard.changed'");
    $data = json_decode($events[0]['data'], true);

    expect($events)->toHaveCount(1)
        ->and($data)->toMatchArray(['dashboard_id' => $w['dashboard'], 'item_id' => $w['item'], 'command' => 'set_minimized', 'revision' => 1])
        ->and($this->getJson("/api/v1/dashboards/{$w['dashboard']}", DR5_HEADERS)->json('data.items.0.minimized'))->toBeTrue()
        ->and($this->getJson("/api/v1/dashboards/{$w['dashboard']}", DR5_HEADERS)->json('data.revision'))->toBe(1);

    // The same value again changes nothing: no new revision and no event.
    $this->putJson(dr6Url($w, 'minimized'), ['minimized' => true, 'expected_revision' => 1], DR5_HEADERS)->assertOk()->assertJsonPath('data.revision', 1);

    expect(Cluster::rows(Cluster::superuser(), "select count(*) as n from outbox_events where type = 'dashboards.dashboard.changed'")[0]['n'])->toBe(1);

    $this->putJson(dr6Url($w, 'minimized'), ['minimized' => false, 'expected_revision' => 1], DR5_HEADERS)->assertOk()->assertJsonPath('data.revision', 2);
});

it('refuses a stale expected_revision with 409 and the current revision, and changes nothing', function () {
    $w = dr5World();
    $this->putJson(dr6Url($w, 'minimized'), ['minimized' => true, 'expected_revision' => 0], DR5_HEADERS)->assertOk();

    $response = $this->putJson(dr6Url($w, 'minimized'), ['minimized' => false, 'expected_revision' => 0], DR5_HEADERS)->assertStatus(409);

    expect($response->json('error.code'))->toBe('dashboards.revision_conflict')
        ->and($response->json('error.revision'))->toBe(1)
        ->and(dr6Revision($w))->toBe(1)
        ->and(Cluster::rows(Cluster::superuser(), 'select minimized from dashboard_items where id = ?', [$w['item']])[0]['minimized'])->toBeTrue();
});

it('validates the minimize body and answers 404 for another dashboard\'s item', function () {
    $w = dr5World();
    $other = Cluster::seedDashboardItem($w['ws']);

    $this->putJson(dr6Url($w, 'minimized'), ['minimized' => 'maybe', 'expected_revision' => -1], DR5_HEADERS)->assertStatus(422);
    $this->putJson("/api/v1/dashboards/{$w['dashboard']}/items/{$other}/minimized", ['minimized' => true, 'expected_revision' => 0], DR5_HEADERS)->assertNotFound();
    expect(dr6Revision($w))->toBe(0);
});

it('brings only this Block\'s target forward on refresh, once per window, and calls no source', function () {
    config(['dashflow.tunables.sync.manual_refresh_window.value' => '30']);
    $w = dr5World();
    dr5Results($w)->assertOk();
    $target = Cluster::rows(Cluster::superuser(), 'select id from sync_targets')[0]['id'];
    Cluster::superuser()->prepare("UPDATE sync_targets SET next_due_at = now() + interval '1 hour' WHERE id = ?")->execute([$target]);

    $curl = new class implements CurlClient
    {
        public int $calls = 0;

        public function execute(array $options, ?int $maxBytes = null): CurlResult
        {
            $this->calls++;

            throw new RuntimeException('no source call is allowed');
        }
    };
    app()->instance(CurlClient::class, $curl);

    $this->postJson(dr6Url($w, 'refresh'), [], DR5_HEADERS)->assertStatus(202)->assertJsonPath('data.status', 'requested');

    $due = Cluster::rows(Cluster::superuser(), 'select next_due_at from sync_targets where id = ?', [$target])[0]['next_due_at'];

    expect(strtotime($due))->toBeLessThanOrEqual(time() + 1);

    // Inside the window: 429 with Retry-After, and the target is not moved again.
    Cluster::superuser()->prepare("UPDATE sync_targets SET next_due_at = now() + interval '1 hour' WHERE id = ?")->execute([$target]);
    $limited = $this->postJson(dr6Url($w, 'refresh'), [], DR5_HEADERS)->assertStatus(429);

    expect($limited->json('error.code'))->toBe('dashboards.refresh_limited')
        ->and((int) $limited->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(30)
        ->and(strtotime(Cluster::rows(Cluster::superuser(), 'select next_due_at from sync_targets where id = ?', [$target])[0]['next_due_at']))->toBeGreaterThan(time() + 3000)
        ->and($curl->calls)->toBe(0);
});

it('does not limit a manual refresh while the window is unset', function () {
    config(['dashflow.tunables.sync.manual_refresh_window.value' => null]);
    $w = dr5World();

    $this->postJson(dr6Url($w, 'refresh'), [], DR5_HEADERS)->assertStatus(202);
    $this->postJson(dr6Url($w, 'refresh'), [], DR5_HEADERS)->assertStatus(202);
});

it('carries the About facts in the layout: description, Data Source name, interval, version and period behaviour, and nothing technical', function () {
    $w = dr5World();

    $about = $this->getJson("/api/v1/dashboards/{$w['dashboard']}", DR5_HEADERS)->json('data.items.0.block.about');

    expect($about)->toBe(['description' => null, 'source_name' => 'Sales API', 'refresh_interval' => 60, 'version' => '1.0', 'period' => 'follow_dashboard']);
});

// ---- Story 4.13: live updates ----------------------------------------------------------------------------------------------------------------------

function dr13Viewers(): array
{
    return Cluster::rows(Cluster::superuser(), 'select membership_id, block_id, items from block_viewers order by block_id');
}

it('keeps who has which Block on a dashboard from the change events, counting items and releasing at zero', function () {
    $w = dr5World();
    $block = $w['block'];

    // The seeded item has no event; the first add on a second dashboard is the first count.
    $second = (string) Str::uuid7();
    Cluster::superuser()->prepare("INSERT INTO dashboards (id, workspace_id, membership_id, name, is_overview, revision) VALUES (?, ?, ?, 'Second', false, 0)")->execute([$second, $w['ws'], $w['membership']]);
    $blockB = Cluster::seedBlock($w['ws']);
    Cluster::seedAccessSubject($w['ws'], $blockB);
    $version = Cluster::seedBlockVersion($w['ws'], [
        'block_id' => $blockB, 'state' => 'published', 'name' => 'B', 'prepared_at' => '2026-10-12 09:00:00+00', 'published_at' => '2026-10-12 09:00:01+00',
        'config' => json_encode(['schema_version' => 1, 'type_key' => 'kpi-card', 'contract_version' => 1, 'sizes' => ['min_width' => 3, 'max_width' => 12, 'width' => 3, 'min_height' => 'small', 'max_height' => 'large', 'height' => 'small']]),
    ]);
    Cluster::superuser()->prepare('UPDATE blocks SET current_version_id = ? WHERE id = ?')->execute([$version, $blockB]);

    $this->postJson("/api/v1/dashboards/{$w['dashboard']}/blocks", ['block_id' => $blockB, 'expected_revision' => 0], DR5_HEADERS)->assertCreated();
    $this->postJson("/api/v1/dashboards/{$second}/blocks", ['block_id' => $blockB, 'expected_revision' => 0], DR5_HEADERS)->assertCreated();

    app(OutboxRelay::class)->relay();

    expect(dr13Viewers())->toHaveCount(1)
        ->and(dr13Viewers()[0])->toMatchArray(['membership_id' => $w['membership'], 'block_id' => $blockB, 'items' => 2]);

    $this->deleteJson("/api/v1/dashboards/{$w['dashboard']}/blocks/{$blockB}", ['expected_revision' => 1], DR5_HEADERS)->assertOk();
    app(OutboxRelay::class)->relay();

    expect(dr13Viewers()[0]['items'])->toBe(1);

    $this->deleteJson("/api/v1/dashboards/{$second}/blocks/{$blockB}", ['expected_revision' => 1], DR5_HEADERS)->assertOk();
    app(OutboxRelay::class)->relay();

    expect(dr13Viewers())->toBe([])->and($block)->not->toBe($blockB);
});

it('announces a new result with only the Block and its ETag, to the Block channel for shared data, and only while somebody views it', function () {
    Event::fake([ResultUpdated::class]);
    $w = dr5World();
    dr5Results($w)->assertOk();
    dr5Hold($w, '{"data":{"revenue":5}}');

    $events = Cluster::rows(Cluster::superuser(), "select data from outbox_events where type = 'results.result.updated'");
    $data = json_decode($events[0]['data'], true);

    expect($events)->toHaveCount(1)
        ->and($data)->toMatchArray(['block_id' => $w['block'], 'scope' => 'shared'])
        ->and($data['result_etag'])->toMatch('/\A[0-9a-f]{64}\z/')
        ->and($data)->not->toHaveKey('membership_id')
        ->and(json_encode($data))->not->toContain('revenue');

    $envelope = OutboxEnvelope::fromRow(Cluster::rows(Cluster::superuser(), "select * from outbox_events where type = 'results.result.updated'")[0]);
    $relay = fn () => app(WorkspaceTransaction::class)->run($w['ws'], fn () => (new BroadcastResultUpdated)->handle($envelope));

    // Nobody views the Block yet: relaying the event broadcasts nothing.
    $relay();
    Event::assertNotDispatched(ResultUpdated::class);

    Cluster::superuser()->prepare('INSERT INTO block_viewers (workspace_id, membership_id, block_id, items) VALUES (?, ?, ?, 1)')->execute([$w['ws'], $w['membership'], $w['block']]);
    $relay();

    Event::assertDispatched(ResultUpdated::class, function ($event) use ($w, $data) {
        $channel = $event->broadcastOn()[0]->name;

        return $channel === 'private-workspace.'.$w['ws'].'.block.'.$w['block']
            && $event->broadcastWith() === ['block_id' => $w['block'], 'result_etag' => $data['result_etag']]
            && $event->broadcastAs() === 'result.updated';
    });
});

it('authorises a Block channel only for a member who can see the Block, in the session\'s Workspace', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => '1',
    ]);
    // The channels are registered on the driver that is the default at boot; this test switches the default, so it registers them again.
    Broadcast::purge();
    require base_path('routes/channels.php');
    $w = dr5World();
    $auth = fn (string $channel) => $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-'.$channel], DR5_HEADERS);

    $auth("workspace.{$w['ws']}.block.{$w['block']}")->assertOk()->assertJsonStructure(['auth']);
    $auth("workspace.{$w['ws']}.membership.{$w['membership']}")->assertOk();

    // Another member's channel, another Workspace's channel and a hidden Block are refused.
    $auth("workspace.{$w['ws']}.membership.".(string) Str::uuid7())->assertForbidden();
    $auth('workspace.'.(string) Str::uuid7().".block.{$w['block']}")->assertForbidden();

    Cluster::superuser()->prepare('UPDATE access_subjects SET visible = false WHERE subject_id = ?')->execute([$w['block']]);

    $auth("workspace.{$w['ws']}.block.{$w['block']}")->assertForbidden();
});

it('refetches only the items asked for', function () {
    $w = dr5World();
    dr5Results($w)->assertOk();
    $other = Cluster::seedBlock($w['ws']);
    Cluster::seedAccessSubject($w['ws'], $other);
    Cluster::superuser()->prepare("INSERT INTO dashboard_items (id, workspace_id, dashboard_id, block_id, col, row, width, height) VALUES (?, ?, ?, ?, 3, 0, 3, 'small')")
        ->execute([(string) Str::uuid7(), $w['ws'], $w['dashboard'], $other]);

    $all = dr5Results($w)->assertOk()->json('data.items');
    $one = test()->getJson("/api/v1/dashboards/{$w['dashboard']}/results?items[]={$w['item']}", DR5_HEADERS)->assertOk()->json('data.items');

    expect($all)->toHaveCount(2)->and($one)->toHaveCount(1)->and($one[0]['item_id'])->toBe($w['item']);

    test()->getJson("/api/v1/dashboards/{$w['dashboard']}/results?items[]=nope", DR5_HEADERS)->assertStatus(422);
});

it('tells the browser how to stay current: the socket details and the poll interval, null while unset', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'pub-key',
        'broadcasting.connections.reverb.options.host' => 'rt.example.test',
        'broadcasting.connections.reverb.options.port' => 8443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
        'dashflow.live.poll_interval.value' => '45',
    ]);
    $w = dr5World();

    $live = test()->getJson("/api/v1/dashboards/{$w['dashboard']}", DR5_HEADERS)->assertOk()->json('data.live');

    expect($live)->toBe(['poll_interval_seconds' => 45, 'realtime' => ['key' => 'pub-key', 'host' => 'rt.example.test', 'port' => 8443, 'scheme' => 'https']]);

    config(['dashflow.live.poll_interval.value' => null, 'broadcasting.default' => 'null']);

    expect(test()->getJson("/api/v1/dashboards/{$w['dashboard']}", DR5_HEADERS)->json('data.live'))->toBe(['poll_interval_seconds' => null, 'realtime' => null]);
});
