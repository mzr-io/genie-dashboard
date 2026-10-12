<?php

use App\Models\User;
use App\Modules\Connector\Infrastructure\CurlClient;
use App\Modules\Connector\Infrastructure\CurlResult;
use App\Modules\Results\Application\ComputeResults;
use App\Modules\Results\Contracts\ResultState;
use App\Modules\Results\Contracts\ResultStore;
use App\Modules\Results\Contracts\ViewedResult;
use App\Modules\Results\Contracts\ViewedResults;
use App\Modules\Results\Contracts\ViewRequest;
use App\Modules\Results\Infrastructure\ValkeyResultStore;
use App\Platform\Period\InvalidPeriodInput;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;
use Tests\Database\Support\RespClient;

// Story 4.3 against the real PostgreSQL and Valkey: viewing a Block subscribes (or touches) its data and reads the stored result with its freshness
// judged by the one rule; unchanged data stays fresh; a refused subscription never fails the read; no source is called.
const VR_HEADERS = ['Referer' => 'http://localhost:8000'];
const VR_MEMBER = '0190aaaa-0000-7000-8000-0000000000e1';

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
 * A Workspace with a Data Source, an Endpoint with a period parameter pair, and a published kpi-card version that points at them.
 *
 * @return array{ws: string, source: string, endpoint: string, version: string}
 */
function vrWorld(array $display = [], array $endpoint = [], array $source = []): array
{
    $ws = Cluster::workspace('Acme');
    $user = Cluster::user('ada-'.Str::random(6).'@example.test');
    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $ws, $user, 'admin', 'active']);
    Cluster::superuser()->prepare('INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
        ->execute([(string) Str::uuid7(), $ws, $membership, 'data_sources.manage']);
    Cluster::seedHostEntry($ws, 'api.example.com');
    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $ws, 'area' => 'admin']);

    $dataSource = Cluster::seedDataSource($ws, 'Sales API');
    $endpointId = (string) test()->postJson("/api/v1/admin/data-sources/{$dataSource}/endpoints", $endpoint + [
        'method' => 'GET', 'path' => '/revenue', 'headers' => [], 'body_template' => null, 'read_only_query' => false, 'confirm_read_only' => false,
        'params' => [['name' => 'from', 'binding' => 'period_start'], ['name' => 'to', 'binding' => 'period_end']],
    ], VR_HEADERS)->assertCreated()->json('data.endpoint_id');

    $config = [
        'schema_version' => 1, 'type_key' => 'kpi-card', 'contract_version' => 1,
        'source' => $source + ['data_source_id' => $dataSource, 'endpoint_id' => $endpointId, 'bindings' => [], 'refresh_interval' => 60, 'default_date_range' => ['preset' => 'this_month']],
        'query_plan' => ['v' => 1, 'extract' => ['fields' => ['rev' => '$.data.revenue']], 'shape' => ['headline_value' => 'rev']],
        'presentation' => [], 'fields' => ['record_path' => null, 'items' => []], 'refresh_interval' => 60,
    ] + ['period' => $display['period'] ?? 'follow_dashboard'];
    $version = Cluster::seedBlockVersion($ws, ['state' => $display['state'] ?? 'published', 'config' => json_encode($config), 'prepared_at' => '2026-10-12 09:00:00+00']
        + (($display['state'] ?? 'published') === 'published' ? ['published_at' => '2026-10-12 09:00:01+00'] : []));

    return ['ws' => $ws, 'source' => $dataSource, 'endpoint' => $endpointId, 'version' => $version];
}

function vrView(array $w, array $over = []): ViewedResult
{
    $request = new ViewRequest(...($over + ['workspaceId' => $w['ws'], 'membershipId' => VR_MEMBER, 'blockVersionId' => $w['version']]));

    return app(WorkspaceTransaction::class)->run($w['ws'], fn () => app(ViewedResults::class)->view($request));
}

/** Gives the target the version watches a payload, `$checkedAgo` seconds since its last good check and `$changedAgo` since it last changed; computes. */
function vrHold(array $w, string $body, int $checkedAgo = 0, int $changedAgo = 0, int $seq = 1): void
{
    $target = Cluster::rows(Cluster::superuser(), 'select t.id from sync_targets t join sync_subscriptions s on s.sync_target_id = t.id where s.block_version_id = ? order by t.created_at limit 1', [$w['version']])[0]['id'];
    $payload = Cluster::seedRawBody($w['ws'], $target, $body);
    Cluster::superuser()->prepare('UPDATE sync_targets SET current_payload_id = ?, payload_seq = ?, last_success_at = now() - make_interval(secs => ?), payload_changed_at = now() - make_interval(secs => ?) WHERE id = ?')
        ->execute([$payload, $seq, $checkedAgo, $changedAgo, $target]);
    app(WorkspaceTransaction::class)->run($w['ws'], fn () => app(ComputeResults::class)->forTarget($w['ws'], $target));
}

it('subscribes on the first view with the interval, context and resolved period, and answers pending while there is no payload', function () {
    $w = vrWorld();

    $first = vrView($w);
    $subs = Cluster::rows(Cluster::superuser(), 'select * from sync_subscriptions');

    expect($first->state)->toBe(ResultState::Pending)->and($first->result)->toBeNull()->and($first->hotRefusal)->toBeNull()
        ->and($subs)->toHaveCount(1)
        ->and($subs[0])->toMatchArray(['block_version_id' => $w['version'], 'role' => 'primary', 'refresh_interval_seconds' => 60])
        ->and($subs[0]['compute_context'])->toStartWith('cc1:')
        ->and($subs[0]['period_start'])->toBe(gmdate('Y-m-01'))
        ->and($subs[0]['hot_until'])->not->toBeNull();

    $second = vrView($w);

    expect(Cluster::rows(Cluster::superuser(), 'select count(*) as n from sync_subscriptions')[0]['n'])->toBe(1)
        ->and($second->hotUntil)->not->toBeNull();
});

it('serves the stored result fresh within two intervals, marks it Stale after, and never changes the data', function () {
    $w = vrWorld();
    vrView($w);
    vrHold($w, '{"data":{"revenue":1234.50}}', checkedAgo: 100, changedAgo: 100);

    $fresh = vrView($w);

    expect($fresh->state)->toBe(ResultState::Ok)
        ->and($fresh->result->stale)->toBeFalse()
        ->and($fresh->result->renderPayload['headline']['value'])->toBe('1234.50')
        ->and($fresh->staleAt)->not->toBeNull();

    Cluster::superuser()->exec("UPDATE sync_targets SET last_success_at = now() - interval '121 seconds'");
    $stale = vrView($w);

    expect($stale->result->stale)->toBeTrue()
        ->and($stale->result->refreshing)->toBeTrue()
        ->and($stale->result->renderPayload)->toBe($fresh->result->renderPayload);

    Cluster::superuser()->exec("UPDATE sync_targets SET last_success_at = now() - interval '119 seconds'");

    expect(vrView($w)->result->stale)->toBeFalse();
});

it('keeps a block fresh when the source answered but nothing changed, while data_as_of shows the real age', function () {
    $w = vrWorld();
    vrView($w);
    vrHold($w, '{"data":{"revenue":10}}', checkedAgo: 30, changedAgo: 86400);

    $view = vrView($w);

    expect($view->result->stale)->toBeFalse()
        ->and(strtotime($view->result->dataAsOf))->toBeLessThan(time() - 86000)
        ->and(strtotime($view->result->lastSuccessAt))->toBeGreaterThan(time() - 120);
});

it('does not keep the block hot when the cold-key budget refuses, and still answers without failing', function () {
    config(['dashflow.tunables.budgets.max_new_cold_keys_per_membership_per_hour.value' => '1']);
    $w = vrWorld(endpoint: ['params' => [['name' => 'who', 'binding' => 'user_email']]]);
    $membership = (string) Str::uuid7();

    $first = vrView($w, ['membershipId' => $membership, 'bound' => ['who' => 'a@example.test']]);
    $second = vrView($w, ['membershipId' => $membership, 'bound' => ['who' => 'b@example.test']]);

    expect($first->hotRefusal)->toBeNull()
        ->and($second->hotRefusal)->toBe('budget.new_cold_keys')
        ->and($second->state)->toBe(ResultState::Pending);
});

it('refuses a selection the block does not offer with a 422 and leaves nothing subscribed', function () {
    $w = vrWorld(['period' => 'own_selector']);

    expect(fn () => vrView($w, ['selection' => 'last_decade']))->toThrow(InvalidPeriodInput::class)
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from sync_subscriptions')[0]['n'])->toBe(0);
});

it('reports a version that is not published as unpublished and subscribes nothing', function () {
    $w = vrWorld(['state' => 'prepared']);

    expect(vrView($w)->state)->toBe(ResultState::Unpublished)
        ->and(Cluster::rows(Cluster::superuser(), 'select count(*) as n from sync_subscriptions')[0]['n'])->toBe(0);
});

it('never calls the source on the read path, even when every call would fail', function () {
    $w = vrWorld();
    vrView($w);
    vrHold($w, '{"data":{"revenue":7}}');

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

    $view = vrView($w);

    expect($view->state)->toBe(ResultState::Ok)->and($curl->calls)->toBe(0);
});
