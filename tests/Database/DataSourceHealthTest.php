<?php

use App\Models\User;
use App\Modules\Connector\Contracts\CallOutcome;
use App\Modules\Connector\Contracts\EgressTransportFailed;
use App\Modules\Connector\Contracts\HostResolver;
use App\Modules\Connector\Contracts\ResponseLimitExceeded;
use App\Modules\Connector\Contracts\SourceGovernor;
use App\Modules\Connector\Infrastructure\CurlClient;
use App\Modules\Ingestion\Application\DispatchDueProbesJob;
use App\Modules\Ingestion\Application\EvaluateSourceHealth;
use App\Modules\Ingestion\Application\FetchJob;
use App\Modules\Ingestion\Application\ProbeDataSourceJob;
use App\Modules\Ingestion\Application\RecordProbeResult;
use App\Modules\Ingestion\Contracts\SourceHealths;
use App\Modules\Ingestion\Infrastructure\SyncSettings;
use App\Platform\Outbox\OutboxRelay;
use App\Platform\Tenancy\WorkspaceMismatchException;
use App\Platform\Tenancy\WorkspaceTransaction;
use App\Support\Observability\MetricEmitter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;
use Tests\Unit\Support\FakeCurl;
use Tests\Unit\Support\FakeGovernor;
use Tests\Unit\Support\FakeResolver;

// Story 2.18 against the real PostgreSQL: a saved Data Source gets a health row (through the outbox) and a probe; the status follows the probe,
// the final runs of its targets and the breaker under `pending_input` thresholds; a change is emitted and audited once; the list and the Admin
// overview read it; and nothing secret reaches a run, an event, the audit log, the log or a metric.
const HL_HEADERS = ['Referer' => 'http://localhost:8000'];
const HL_CANARY = 'CANARY-hl-5d27ab';

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();

    $this->logFile = tempnam(sys_get_temp_dir(), 'dashflow-log');
    config([
        'logging.default' => 'single', 'logging.channels.single.path' => $this->logFile,
        'dashflow.tunables.sync.refresh_intervals.value' => '900,300',
    ]);

    $this->curl = new FakeCurl;
    app()->instance(HostResolver::class, new FakeResolver(['api.example.com' => ['93.184.216.34']]));
    app()->instance(CurlClient::class, $this->curl);
});

afterEach(fn () => @unlink($this->logFile));

/** @param  array<string, string|null>  $settings  dotted names without `.value`, under `dashflow.` */
function hlSettings(array $settings): void
{
    foreach ($settings as $name => $value) {
        config(['dashflow.'.$name.'.value' => $value]);
    }
}

/** The thresholds of a deployment that set all of them: unreachable after 3 failed runs, healthy from 70 %, degraded from 40 %, a one-hour window. */
function hlRulesOn(): void
{
    hlSettings([
        'tunables.health.window' => '3600', 'tunables.health.threshold_healthy' => '70',
        'tunables.health.threshold_degraded' => '40', 'tunables.health.threshold_unreachable' => '3',
    ]);
}

/** @return array{0: string, 1: string} the Workspace and the signed-in Admin's membership (holding `data_sources.manage`), allowlisting api.example.com */
function hlSetup(array $permissions = ['data_sources.manage']): array
{
    $workspace = Cluster::workspace('Acme');
    $user = Cluster::user('ada-'.Str::random(6).'@example.test');
    $membership = (string) Str::uuid7();
    Cluster::superuser()->prepare('INSERT INTO workspace_memberships (id, workspace_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, now(), now())')
        ->execute([$membership, $workspace, $user, 'admin', 'active']);

    foreach ($permissions as $permission) {
        Cluster::superuser()->prepare('INSERT INTO membership_permissions (id, workspace_id, membership_id, permission, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())')
            ->execute([(string) Str::uuid7(), $workspace, $membership, $permission]);
    }

    Cluster::seedHostEntry($workspace, 'api.example.com');

    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspace, 'area' => 'admin']);

    return [$workspace, $membership];
}

/** @param  array<string, mixed>  $overrides */
function hlSave(array $overrides = []): string
{
    return (string) test()->postJson('/api/v1/admin/data-sources', $overrides + [
        'name' => 'Sales API', 'base_url' => 'https://api.example.com/v1', 'headers' => [], 'auth_type' => 'none',
    ], HL_HEADERS)->assertCreated()->json('data.data_source_id');
}

function hlRelay(): int
{
    return app(OutboxRelay::class)->relay();
}

/** @return list<array<string, mixed>> */
function hlHealth(): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from data_source_health order by created_at, id');
}

/** @return list<array<string, mixed>> */
function hlEvents(): array
{
    return Cluster::rows(Cluster::superuser(), "select * from outbox_events where type = 'ingestion.source_health.changed' order by occurred_at, subject_seq");
}

/** @return list<array<string, mixed>> */
function hlAudits(): array
{
    return Cluster::rows(Cluster::superuser(), "select * from audit_events where action = 'ingestion.source_health.changed' order by occurred_at");
}

/** @return list<array<string, mixed>> */
function hlProbeRuns(): array
{
    return Cluster::rows(Cluster::superuser(), "select * from sync_runs where kind = 'health_probe' order by started_at, id");
}

function hlProbe(string $workspace, string $source, bool $periodic = false): void
{
    dispatch_sync(new ProbeDataSourceJob($workspace, $source, $periodic));
}

/** A Data Source with a health row of its own, as the relay would have made it; no probe has run. */
function hlSource(string $workspace, string $name = 'Sales API', array $health = []): string
{
    $source = Cluster::seedDataSource($workspace, $name);
    Cluster::seedSourceHealth($workspace, ['data_source_id' => $source] + $health);

    return $source;
}

/** The health the API reports for a Data Source. */
function hlList(string $source): array
{
    $row = collect(test()->getJson('/api/v1/admin/data-sources', HL_HEADERS)->assertOk()->json('data'))->firstWhere('data_source_id', $source);

    return [$row['health'], $row['last_successful_call_at']];
}

function hlEvaluate(string $workspace, string $source): ?string
{
    return app(WorkspaceTransaction::class)->run($workspace, fn () => app(EvaluateSourceHealth::class)->evaluate($workspace, $source));
}

/** @param  list<array{0: string, 1?: string}>  $runs  status, and an outcome for a succeeded one; each a second after the one before, whatever the call, all inside a one-hour window */
function hlRuns(string $workspace, string $source, array $runs, string $kind = 'scheduled_fetch'): void
{
    static $tick = 0;

    foreach ($runs as $run) {
        Cluster::superuser()->prepare('INSERT INTO sync_runs (id, workspace_id, data_source_id, kind, url_template, status, outcome, started_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, now(), now())')
            ->execute([(string) Str::uuid7(), $workspace, $source, $kind, 'https://api.example.com/v1/x', $run[0], $run[1] ?? null, gmdate('Y-m-d H:i:sP', time() - 3000 + $tick++)]);
    }
}

/** A Data Source with a health row and one registered target for an Endpoint, ready for a run. @return array{0: string, 1: string} the Data Source and the target */
function hlTarget(string $workspace): array
{
    $source = hlSource($workspace);
    test()->postJson("/api/v1/admin/data-sources/{$source}/endpoints", [
        'method' => 'GET', 'path' => '/revenue', 'params' => [['name' => 'region', 'binding' => 'fixed', 'value' => 'emea']],
        'headers' => [], 'body_template' => null, 'read_only_query' => false, 'confirm_read_only' => false,
    ], HL_HEADERS)->assertCreated();
    hlRelay();
    $targets = Cluster::rows(Cluster::superuser(), 'select id from sync_targets where data_source_id = ? and retired_at is null', [$source]);
    expect($targets)->toHaveCount(1);

    return [$source, $targets[0]['id']];
}

/** Raises the fence the way the dispatcher does and runs the fetch job in place (the queue is `sync`). */
function hlFetch(string $workspace, string $target, int $seq): void
{
    Cluster::superuser()->prepare('update sync_targets set dispatch_seq = greatest(dispatch_seq, ?) where id = ?')->execute([$seq, $target]);
    dispatch(new FetchJob($workspace, $target, $seq));
}

function hlGovernor(): FakeGovernor
{
    $governor = new FakeGovernor;
    app()->instance(SourceGovernor::class, $governor);

    return $governor;
}

// ---- Save and probe ----

it('makes a Checking health row and queues a probe when a Data Source is saved and the relay delivers, and shows Checking… until the result', function () {
    [$workspace] = hlSetup();
    Queue::fake();
    $source = hlSave(['health_path' => '/health']);

    $created = Cluster::rows(Cluster::superuser(), "select * from outbox_events where type = 'connector.data_source.created'");
    expect($created)->toHaveCount(1)
        ->and($created[0]['subject'])->toBe('data_source:'.$source)
        ->and(json_decode($created[0]['data'], true))->toEqual(['data_source_id' => $source, 'revision' => 1])
        ->and($created[0]['data'])->not->toContain('health')->not->toContain('example');

    // Saving makes no call and no health row: the relay delivers within its minute.
    expect(hlHealth())->toBe([])->and(hlList($source))->toBe(['checking', null]);

    hlRelay();

    expect(hlHealth())->toHaveCount(1)
        ->and(hlHealth()[0])->toMatchArray(['workspace_id' => $workspace, 'data_source_id' => $source, 'status' => 'checking', 'status_seq' => 0, 'last_probe_at' => null, 'next_probe_at' => null])
        ->and(substr(hlHealth()[0]['id'], 14, 1))->toBe('7');
    Queue::assertPushed(ProbeDataSourceJob::class, fn (ProbeDataSourceJob $job): bool => $job->dataSourceId === $source && $job->workspaceId === $workspace && ! $job->periodic && $job->queue === 'fetch-scheduled' && $job->tries === 1);
    expect(hlList($source))->toBe(['checking', null])->and($this->curl->calls)->toBe([]);

    // The probe: one GET to the Base URL plus the health path, no JSON needed, the body dropped. 2xx is Healthy with no event (Checking to Healthy).
    $this->curl->queue = [FakeCurl::answer(200, '<html>up</html>', ['content-type' => ['text/html']])];
    // (The fake queue holds the job, so it runs by hand in the Workspace, as the signed job's middleware would.)
    $job = Queue::pushed(ProbeDataSourceJob::class)->first();
    app(WorkspaceTransaction::class)->run($workspace, fn () => $job->handle(app(RecordProbeResult::class)));

    expect($this->curl->calls)->toHaveCount(1)
        ->and($this->curl->calls[0][CURLOPT_URL])->toBe('https://api.example.com/v1/health')
        ->and(hlHealth()[0])->toMatchArray(['status' => 'healthy', 'status_seq' => 1, 'last_probe_ok' => true])
        ->and(hlEvents())->toBe([])->and(hlAudits())->toBe([]);

    [$health, $last] = hlList($source);
    expect($health)->toBe('healthy')->and($last)->toBe(gmdate('Y-m-d\TH:i:s\Z', strtotime(hlHealth()[0]['last_probe_success_at'])));

    $runs = hlProbeRuns();
    expect($runs)->toHaveCount(1)
        ->and($runs[0])->toMatchArray(['data_source_id' => $source, 'status' => 'succeeded', 'http_status' => 200, 'error_code' => null, 'url_template' => 'https://api.example.com/v1']);
});

it('probes the Base URL alone without a health path, and counts a 304 as ok', function () {
    [$workspace] = hlSetup();
    $source = hlSource($workspace);
    $this->curl->queue = [FakeCurl::answer(304, '', [])];

    hlProbe($workspace, $source);

    expect($this->curl->calls[0][CURLOPT_URL])->toBe('https://api.example.com')
        ->and(hlHealth()[0])->toMatchArray(['status' => 'healthy', 'last_probe_ok' => true])
        ->and(hlProbeRuns()[0])->toMatchArray(['status' => 'succeeded', 'http_status' => 304]);
});

it('sends the default headers and credentials with the probe, through the guard', function () {
    [$workspace] = hlSetup();
    $source = hlSource($workspace);
    Cluster::superuser()->prepare('UPDATE data_sources SET default_headers = ?::jsonb WHERE id = ?')->execute([json_encode([['name' => 'X-Team', 'value' => 'finance']]), $source]);
    $this->curl->queue = [FakeCurl::answer(200, '{}')];

    hlProbe($workspace, $source);

    expect($this->curl->calls[0][CURLOPT_HTTPHEADER])->toContain('X-Team: finance')
        ->and($this->curl->calls[0][CURLOPT_RESOLVE])->toContain('api.example.com:443:93.184.216.34');
});

it('is Unreachable with an event and an audit when the probe fails, and repeats nothing while it stays so', function () {
    [$workspace] = hlSetup();
    $source = hlSource($workspace);
    $this->curl->queue = [new EgressTransportFailed('timeout', 28), FakeCurl::answer(500, '{}')];

    hlProbe($workspace, $source);

    $row = hlHealth()[0];
    expect($row)->toMatchArray(['status' => 'unreachable', 'status_seq' => 1, 'last_probe_ok' => false, 'last_probe_success_at' => null])
        ->and(hlList($source))->toBe(['unreachable', null]);

    $events = hlEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0]['subject'])->toBe('data_source_health:'.$row['id'])
        ->and(json_decode($events[0]['data'], true))->toEqual(['data_source_id' => $source, 'from' => 'checking', 'to' => 'unreachable'])
        ->and($events[0]['actor'])->toBe('system');

    $audits = hlAudits();
    expect($audits)->toHaveCount(1)
        ->and($audits[0])->toMatchArray(['actor' => 'system', 'subject' => 'data_source_health:'.$row['id'], 'security' => false])
        ->and(json_decode($audits[0]['after_state'], true))->toEqual(['data_source_id' => $source, 'from' => 'checking', 'to' => 'unreachable']);

    // A second failing probe changes nothing: no row change, no second event.
    hlProbe($workspace, $source);

    expect(hlHealth()[0]['status_seq'])->toBe(1)->and(hlEvents())->toHaveCount(1)->and(hlProbeRuns())->toHaveCount(2)
        ->and(array_column(hlProbeRuns(), 'error_code'))->toBe(['fetch-failed', 'fetch-failed']);
});

it('recovers: a later ok probe returns it to Healthy with the last success moved, and the change is emitted once', function () {
    [$workspace] = hlSetup();
    $source = hlSource($workspace);
    $this->curl->queue = [FakeCurl::answer(503, '{}'), FakeCurl::answer(200, '{}')];

    hlProbe($workspace, $source);
    expect(hlList($source))->toBe(['unreachable', null]);

    hlProbe($workspace, $source);
    [$health, $last] = hlList($source);

    expect($health)->toBe('healthy')->and($last)->not->toBeNull()
        ->and(array_map(fn (array $e): string => json_decode($e['data'], true)['to'], hlEvents()))->toBe(['unreachable', 'healthy'])
        ->and(hlHealth()[0]['status_seq'])->toBe(2);
});

it('honours an open breaker: no call, no run, the previous result kept', function () {
    [$workspace] = hlSetup();
    $source = hlSource($workspace);
    hlSettings(['tunables.circuit_breaker.failure_count' => '1', 'tunables.circuit_breaker.cool_down' => '30']);
    $governor = hlGovernor();
    $this->curl->queue = [FakeCurl::answer(200, '{}')];
    hlProbe($workspace, $source);
    $before = hlHealth()[0];

    $limits = app(SyncSettings::class)->governorLimits();
    $governor->record($workspace, $source, CallOutcome::Failed, $limits);
    $this->curl->queue = [new EgressTransportFailed('timeout', 28)];

    hlProbe($workspace, $source);

    expect($this->curl->calls)->toHaveCount(1)->and(hlProbeRuns())->toHaveCount(1)
        ->and(hlHealth()[0])->toMatchArray(['status' => $before['status'], 'last_probe_at' => $before['last_probe_at'], 'last_probe_ok' => true, 'status_seq' => $before['status_seq']]);
});

it('takes no governor token and tells the breaker nothing', function () {
    [$workspace] = hlSetup();
    $source = hlSource($workspace);
    hlSettings(['tunables.circuit_breaker.failure_count' => '1', 'tunables.circuit_breaker.cool_down' => '30', 'tunables.budgets.max_fetch_rate_per_data_source' => '1']);
    $governor = hlGovernor();
    $this->curl->queue = [new EgressTransportFailed('timeout', 28), FakeCurl::answer(200, '{}')];

    hlProbe($workspace, $source);
    hlProbe($workspace, $source);

    expect($governor->admits)->toBe([])->and($governor->records)->toBe([])->and($this->curl->calls)->toHaveCount(2);
});

it('fails hard with no request when the probe job holds a Data Source of another Workspace', function () {
    [$workspace] = hlSetup();
    $other = Cluster::workspace('Other');
    $foreign = Cluster::seedDataSource($other, 'Theirs');

    expect(fn () => hlProbe($workspace, $foreign))->toThrow(WorkspaceMismatchException::class)
        ->and($this->curl->calls)->toBe([])->and(hlProbeRuns())->toBe([])
        ->and((string) file_get_contents($this->logFile))->toContain('security.tenancy.workspace_mismatch')
        ->and((new ProbeDataSourceJob($workspace, $foreign))->referencedIds())->toBe(['data_sources' => [$foreign]]);
});

// ---- Status from runs, thresholds and the breaker ----

it('is Healthy for nine succeeded runs, two of them 304s, and one failed, with a healthy threshold of 70', function () {
    [$workspace] = hlSetup();
    hlRulesOn();
    $source = hlSource($workspace);
    hlRuns($workspace, $source, [['succeeded'], ['succeeded', 'not_modified'], ['succeeded'], ['succeeded'], ['succeeded'], ['succeeded', 'not_modified'], ['succeeded'], ['succeeded'], ['succeeded'], ['failed']]);

    expect(hlEvaluate($workspace, $source))->toBe('healthy')
        ->and(hlHealth()[0]['status'])->toBe('healthy')
        // Checking to Healthy raises no event.
        ->and(hlEvents())->toBe([]);
});

it('counts only final scheduled runs: retrying, skipped, superseded and probe runs never count', function () {
    [$workspace] = hlSetup();
    hlRulesOn();
    $source = hlSource($workspace);
    hlRuns($workspace, $source, [['succeeded'], ['succeeded']]);
    hlRuns($workspace, $source, [['retrying'], ['skipped'], ['superseded']]);
    hlRuns($workspace, $source, [['failed'], ['failed'], ['failed']], 'health_probe');

    expect(hlEvaluate($workspace, $source))->toBe('healthy');
});

it('is Degraded at 60 percent success with the event healthy to degraded, once', function () {
    [$workspace] = hlSetup();
    hlRulesOn();
    $source = hlSource($workspace, 'Sales API', ['status' => 'healthy']);
    hlRuns($workspace, $source, [['succeeded'], ['failed'], ['succeeded'], ['failed'], ['succeeded']]);

    expect(hlEvaluate($workspace, $source))->toBe('degraded')
        ->and(hlEvaluate($workspace, $source))->toBeNull();

    expect(array_map(fn (array $e): array => json_decode($e['data'], true), hlEvents()))->toEqual([['data_source_id' => $source, 'from' => 'healthy', 'to' => 'degraded']])
        ->and(hlAudits())->toHaveCount(1)
        ->and(hlHealth()[0])->toMatchArray(['status' => 'degraded', 'status_seq' => 1]);
});

it('is Unreachable below the degraded threshold and after enough trailing failures', function () {
    [$workspace] = hlSetup();
    hlRulesOn();
    $below = hlSource($workspace, 'Below');
    hlRuns($workspace, $below, [['succeeded'], ['failed'], ['succeeded'], ['failed'], ['failed'], ['succeeded'], ['failed']]);
    $trailing = hlSource($workspace, 'Trailing');
    hlRuns($workspace, $trailing, [['succeeded'], ['succeeded'], ['succeeded'], ['succeeded'], ['succeeded'], ['succeeded'], ['succeeded'], ['succeeded'], ['failed'], ['failed'], ['failed']]);

    // 3 of 7 is 42 percent, which is Degraded; trailing 1 does not reach 3.
    expect(hlEvaluate($workspace, $below))->toBe('degraded');

    // 8 of 11 is 72 percent, which alone would be Healthy: the three trailing failures decide first.
    expect(hlEvaluate($workspace, $trailing))->toBe('unreachable');

    hlRuns($workspace, $below, [['failed'], ['failed']]);
    expect(hlEvaluate($workspace, $below))->toBe('unreachable');
});

it('recovers after a success: Healthy per the rules, one event for each change, the last success moved', function () {
    [$workspace] = hlSetup();
    hlRulesOn();
    hlSettings(['tunables.health.threshold_healthy' => '80']);
    $source = hlSource($workspace, 'Sales API', ['status' => 'healthy']);
    $target = Cluster::seedSyncTarget($workspace, ['data_source_id' => $source, 'last_success_at' => '2026-10-01 08:00:00+00']);
    hlRuns($workspace, $source, [['succeeded'], ['succeeded'], ['succeeded'], ['succeeded'], ['succeeded']]);
    $step = function (string $status) use ($workspace, $source): ?string {
        hlRuns($workspace, $source, [[$status]]);

        return hlEvaluate($workspace, $source);
    };

    expect($step('failed'))->toBeNull()                    // 5 of 6 is 83 percent, trailing 1: still Healthy
        ->and($step('failed'))->toBe('degraded')           // 5 of 7 is 71 percent
        ->and($step('failed'))->toBe('unreachable');       // three trailing failures
    expect(hlList($source)[1])->toBe('2026-10-01T08:00:00Z');

    Cluster::superuser()->prepare("UPDATE sync_targets SET last_success_at = '2026-10-09 10:42:00+00' WHERE id = ?")->execute([$target]);

    // The first success clears the trailing failures: 6 of 9 is 66 percent. Each further success lifts the percentage until 12 of 15 is 80.
    expect($step('succeeded'))->toBe('degraded');
    $changes = [];

    foreach (range(1, 6) as $ignored) {
        $changes[] = $step('succeeded');
    }

    expect($changes)->toBe([null, null, null, null, null, 'healthy'])
        ->and(hlList($source))->toBe(['healthy', '2026-10-09T10:42:00Z'])
        ->and(array_map(fn (array $e): string => json_decode($e['data'], true)['from'].'>'.json_decode($e['data'], true)['to'], hlEvents()))
        ->toBe(['healthy>degraded', 'degraded>unreachable', 'unreachable>degraded', 'degraded>healthy'])
        ->and(hlAudits())->toHaveCount(4)
        ->and(hlHealth()[0]['status_seq'])->toBe(4);
});

it('skips the run rules with unset thresholds: the probe decides and no run label is invented', function () {
    [$workspace] = hlSetup();
    $source = hlSource($workspace, 'Sales API', ['last_probe_at' => '2026-10-09 09:00:00+00', 'last_probe_ok' => true, 'last_probe_success_at' => '2026-10-09 09:00:00+00']);
    hlRuns($workspace, $source, [['succeeded']]);

    expect(hlEvaluate($workspace, $source))->toBe('healthy');

    // A failed probe then decides the other way.
    Cluster::superuser()->prepare('UPDATE data_source_health SET last_probe_ok = false WHERE data_source_id = ?')->execute([$source]);
    expect(hlEvaluate($workspace, $source))->toBe('unreachable');
});

it('lets final runs outrank a stale ok probe when no threshold is set: a failing latest run is Degraded', function () {
    [$workspace] = hlSetup();
    $source = hlSource($workspace, 'Sales API', ['status' => 'healthy', 'last_probe_at' => '2026-10-09 09:00:00+00', 'last_probe_ok' => true, 'last_probe_success_at' => '2026-10-09 09:00:00+00']);
    hlRuns($workspace, $source, [['failed'], ['failed']]);

    expect(hlEvaluate($workspace, $source))->toBe('degraded');

    hlRuns($workspace, $source, [['succeeded']]);
    expect(hlEvaluate($workspace, $source))->toBe('healthy');
});

it('is Checking and never Healthy with no probe result and no final run, and keeps a status it already had when nothing decides', function () {
    [$workspace] = hlSetup();
    hlRulesOn();
    $source = hlSource($workspace);

    expect(hlEvaluate($workspace, $source))->toBeNull()
        ->and(hlHealth()[0]['status'])->toBe('checking')
        ->and(hlList($source))->toBe(['checking', null]);

    // Runs exist but fell out of the window, and there is no probe: nothing decides, so the status stays.
    $kept = hlSource($workspace, 'Kept', ['status' => 'degraded']);
    Cluster::superuser()->prepare("INSERT INTO sync_runs (id, workspace_id, data_source_id, kind, url_template, status, started_at, created_at, updated_at) VALUES (?, ?, ?, 'scheduled_fetch', 'https://api.example.com/v1', 'succeeded', now() - interval '2 days', now(), now())")
        ->execute([(string) Str::uuid7(), $workspace, $kept]);

    expect(hlEvaluate($workspace, $kept))->toBeNull();
    expect(Cluster::rows(Cluster::superuser(), 'select status from data_source_health where data_source_id = ?', [$kept])[0]['status'])->toBe('degraded');
});

it('is Unreachable while the breaker is open and re-evaluates when it closes (Healthy per the rules), with an event each way', function () {
    [$workspace] = hlSetup();
    hlRulesOn();
    hlSettings(['tunables.circuit_breaker.failure_count' => '2', 'tunables.circuit_breaker.cool_down' => '30', 'tunables.health.threshold_unreachable' => '9']);
    $governor = hlGovernor();
    $source = hlSource($workspace, 'Sales API', ['status' => 'healthy']);
    hlRuns($workspace, $source, [['succeeded'], ['succeeded'], ['succeeded'], ['succeeded'], ['succeeded'], ['succeeded']]);
    $limits = app(SyncSettings::class)->governorLimits();

    expect(hlEvaluate($workspace, $source))->toBeNull();

    $governor->record($workspace, $source, CallOutcome::Failed, $limits);
    $governor->record($workspace, $source, CallOutcome::Failed, $limits);
    expect(hlEvaluate($workspace, $source))->toBe('unreachable');

    // Half-open is still Unreachable; the probe call that succeeds closes it.
    $governor->now += 31;
    expect(hlEvaluate($workspace, $source))->toBeNull();
    $governor->record($workspace, $source, CallOutcome::Responded, $limits, true);

    expect(hlEvaluate($workspace, $source))->toBe('healthy')
        ->and(array_map(fn (array $e): string => json_decode($e['data'], true)['to'], hlEvents()))->toBe(['unreachable', 'healthy']);
});

it('runs the evaluation after a final run and when the breaker opens, through the fetch job', function () {
    [$workspace] = hlSetup();
    hlRulesOn();
    hlSettings(['tunables.circuit_breaker.failure_count' => '2', 'tunables.circuit_breaker.cool_down' => '30', 'tunables.health.threshold_unreachable' => '9']);
    $governor = hlGovernor();
    [$source, $target] = hlTarget($workspace);

    $this->curl->queue = [FakeCurl::answer(200, '{"a":1}'), FakeCurl::answer(500, '{}'), FakeCurl::answer(500, '{}')];
    hlFetch($workspace, $target, 1);
    expect(hlList($source)[0])->toBe('healthy');

    hlFetch($workspace, $target, 2);
    // 1 of 2 is 50 percent: Degraded (healthy 70, degraded 40).
    expect(hlList($source)[0])->toBe('degraded');

    hlFetch($workspace, $target, 3);
    // The second failed call opened the breaker: Unreachable, though 1 of 3 is 33 percent either way.
    expect($governor->state($workspace, $source, app(SyncSettings::class)->governorLimits()))->toBe('open')
        ->and(hlList($source)[0])->toBe('unreachable')
        ->and(array_map(fn (array $e): string => json_decode($e['data'], true)['to'], hlEvents()))->toBe(['degraded', 'unreachable']);

    // Skipped runs while the breaker is open are no evidence: nothing is called and the status stays.
    hlFetch($workspace, $target, 4);
    expect($this->curl->calls)->toHaveCount(3)->and(hlList($source)[0])->toBe('unreachable')->and(hlEvents())->toHaveCount(2);

    // After the cool-down the probe call that answers closes the breaker and the status follows the runs again.
    $governor->now += 31;
    $this->curl->queue = [FakeCurl::answer(200, '{"a":2}')];
    hlFetch($workspace, $target, 5);
    // 2 of 4 is 50 percent: Degraded.
    expect(hlList($source)[0])->toBe('degraded')
        ->and(array_map(fn (array $e): string => json_decode($e['data'], true)['to'], hlEvents()))->toBe(['degraded', 'unreachable', 'degraded']);
});

it('records a killed or throwing job as a final failed run and evaluates', function () {
    [$workspace] = hlSetup();
    hlRulesOn();
    hlSettings(['tunables.health.threshold_unreachable' => '1']);
    [$source, $target] = hlTarget($workspace);
    Cluster::superuser()->prepare('update sync_targets set dispatch_seq = 1 where id = ?')->execute([$target]);

    (new FetchJob($workspace, $target, 1))->failed(new RuntimeException('killed'));

    expect(hlList($source)[0])->toBe('unreachable')
        ->and(Cluster::rows(Cluster::superuser(), "select error_code from sync_runs where kind = 'scheduled_fetch'")[0]['error_code'])->toBe('job-failed');
});

it('lets two evaluators reach the same result and change the row once, with one event', function () {
    [$workspace] = hlSetup();
    hlRulesOn();
    $source = hlSource($workspace, 'Sales API', ['status' => 'healthy']);
    hlRuns($workspace, $source, [['succeeded'], ['failed'], ['succeeded'], ['failed'], ['succeeded']]);

    $first = hlEvaluate($workspace, $source);
    $second = hlEvaluate($workspace, $source);

    expect([$first, $second])->toBe(['degraded', null])
        ->and(hlHealth()[0]['status_seq'])->toBe(1)->and(hlEvents())->toHaveCount(1)->and(hlAudits())->toHaveCount(1);
});

it('does not move or announce again a change another evaluator already committed', function () {
    [$workspace] = hlSetup();
    hlRulesOn();
    $source = hlSource($workspace, 'Sales API', ['status' => 'healthy']);
    hlRuns($workspace, $source, [['succeeded'], ['failed'], ['succeeded'], ['failed'], ['succeeded']]);

    // The racing evaluator committed the same result first; ours reads it under the row lock and the guarded UPDATE matches nothing.
    Cluster::superuser()->prepare("UPDATE data_source_health SET status = 'degraded', status_seq = status_seq + 1 WHERE data_source_id = ?")->execute([$source]);

    expect(hlEvaluate($workspace, $source))->toBeNull()
        ->and(hlHealth()[0])->toMatchArray(['status' => 'degraded', 'status_seq' => 1])->and(hlEvents())->toBe([]);
});

// ---- The last success and the list ----

it('takes the last success from the current targets, never from the probe, and from the probe only without targets', function () {
    [$workspace] = hlSetup();
    $withTargets = hlSource($workspace, 'With', ['status' => 'healthy', 'last_probe_at' => '2026-10-09 11:00:00+00', 'last_probe_ok' => true, 'last_probe_success_at' => '2026-10-09 11:00:00+00']);
    Cluster::seedSyncTarget($workspace, ['data_source_id' => $withTargets, 'last_success_at' => '2026-10-09 08:00:00+00']);
    Cluster::seedSyncTarget($workspace, ['data_source_id' => $withTargets, 'last_success_at' => '2026-10-09 09:30:00+00']);
    Cluster::seedSyncTarget($workspace, ['data_source_id' => $withTargets, 'last_success_at' => '2026-10-09 10:00:00+00', 'retired_at' => '2026-10-09 10:01:00+00']);
    $failing = hlSource($workspace, 'Failing', ['last_probe_at' => '2026-10-09 11:00:00+00', 'last_probe_ok' => true, 'last_probe_success_at' => '2026-10-09 11:00:00+00']);
    Cluster::seedSyncTarget($workspace, ['data_source_id' => $failing]);
    $bare = hlSource($workspace, 'Bare', ['status' => 'healthy', 'last_probe_at' => '2026-10-09 10:42:00+00', 'last_probe_ok' => true, 'last_probe_success_at' => '2026-10-09 10:42:00+00']);
    $retiredOnly = hlSource($workspace, 'Retired only', ['last_probe_success_at' => '2026-10-09 07:00:00+00']);
    Cluster::seedSyncTarget($workspace, ['data_source_id' => $retiredOnly, 'last_success_at' => '2026-10-09 06:00:00+00', 'retired_at' => '2026-10-09 06:01:00+00']);
    $unknown = Cluster::seedDataSource($workspace, 'No row');

    $healths = app(SourceHealths::class)->forDataSources($workspace, [$withTargets, $failing, $bare, $retiredOnly, $unknown, 'not-a-uuid']);

    expect(array_keys($healths))->toBe([$withTargets, $failing, $bare, $retiredOnly, $unknown])
        ->and($healths[$withTargets]->lastSuccessAt)->toBe('2026-10-09T09:30:00Z')
        ->and($healths[$failing]->lastSuccessAt)->toBeNull()
        ->and($healths[$bare]->lastSuccessAt)->toBe('2026-10-09T10:42:00Z')
        ->and($healths[$retiredOnly]->lastSuccessAt)->toBe('2026-10-09T07:00:00Z')
        ->and([$healths[$withTargets]->status, $healths[$unknown]->status])->toBe(['healthy', 'checking']);
});

it('serves the health and last success in the list, the one Data Source and the conflict body', function () {
    [$workspace] = hlSetup();
    $source = hlSource($workspace, 'Sales API', ['status' => 'degraded', 'last_probe_success_at' => '2026-10-09 10:42:00+00']);

    $row = $this->getJson('/api/v1/admin/data-sources', HL_HEADERS)->assertOk()->json('data.0');
    expect($row)->toMatchArray(['health' => 'degraded', 'last_successful_call_at' => '2026-10-09T10:42:00Z', 'health_path' => null]);

    $this->getJson('/api/v1/admin/data-sources/'.$source, HL_HEADERS)->assertOk()->assertJsonPath('data.health', 'degraded')->assertJsonPath('data.last_successful_call_at', '2026-10-09T10:42:00Z');
});

it('serves the Admin overview its sources: name, id, health and last success, only for this Workspace, never cached', function () {
    [$workspace] = hlSetup();
    $a = hlSource($workspace, 'Alpha', ['status' => 'unreachable']);
    $b = hlSource($workspace, 'Beta', ['status' => 'healthy', 'last_probe_success_at' => '2026-10-09 10:42:00+00']);
    $other = Cluster::workspace('Other');
    hlSource($other, 'Theirs');

    $response = $this->getJson('/api/v1/admin/data-source-health', HL_HEADERS)->assertOk();

    expect($response->json('data'))->toBe([
        ['data_source_id' => $a, 'name' => 'Alpha', 'health' => 'unreachable', 'last_successful_call_at' => null],
        ['data_source_id' => $b, 'name' => 'Beta', 'health' => 'healthy', 'last_successful_call_at' => '2026-10-09T10:42:00Z'],
    ])
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getContent())->not->toContain('Theirs');
});

it('answers 403 with an audit without data_sources.manage, and renders no panel data', function () {
    [$workspace] = hlSetup([]);
    hlSource($workspace, 'Secret source');
    $count = fn (): int => (int) Cluster::rows(Cluster::superuser(), "select count(*) as n from audit_events where action = 'access.admin.denied'")[0]['n'];
    $before = $count();

    $response = $this->getJson('/api/v1/admin/data-source-health', HL_HEADERS);

    $response->assertForbidden();
    expect($count())->toBe($before + 1)->and($response->getContent())->not->toContain('Secret source');

    // The page itself renders (an Admin area page), but the panel is the page's business, keyed on the permission the shell reports.
    $this->get(route('admin.overview'))->assertOk()->assertInertia(fn ($page) => $page->component('admin/Overview')->where('shell.can', fn ($can) => ($can['data_sources.manage'] ?? false) === false));
});

it('renders the Admin overview as its own page, with data_sources.manage in the shell', function () {
    hlSetup();

    $this->get(route('admin.overview'))->assertOk()->assertInertia(fn ($page) => $page->component('admin/Overview')->where('shell.can', fn ($can) => ($can['data_sources.manage'] ?? false) === true));
});

// ---- The periodic tick ----

it('re-probes due sources without current targets on the tick, advancing next_probe_at, only when the interval is valid', function () {
    [$workspace] = hlSetup();
    $due = hlSource($workspace, 'Due', ['next_probe_at' => '2026-10-01 00:00:00+00']);
    $later = hlSource($workspace, 'Later', ['next_probe_at' => '2099-01-01 00:00:00+00']);
    hlSource($workspace, 'Never');
    Queue::fake();

    // Unset: a probe on save only.
    expect(app()->call([new DispatchDueProbesJob, 'handle']))->toBe(0);
    Queue::assertNothingPushed();

    hlSettings(['tunables.health.probe_interval' => '600']);
    expect(app()->call([new DispatchDueProbesJob, 'handle']))->toBe(1);
    Queue::assertPushed(ProbeDataSourceJob::class, 1);
    Queue::assertPushed(ProbeDataSourceJob::class, fn (ProbeDataSourceJob $job): bool => $job->dataSourceId === $due && $job->periodic && $job->workspaceId === $workspace);

    $rows = collect(hlHealth())->keyBy('data_source_id');
    expect(strtotime($rows[$due]['next_probe_at']))->toBeGreaterThan(time() + 590)
        ->and($rows[$later]['next_probe_at'])->toBe('2099-01-01 00:00:00+00')
        ->and(array_values(array_filter($rows->all(), fn (array $r): bool => $r['next_probe_at'] === null)))->toHaveCount(1);

    // Not due any more: a second tick queues nothing.
    expect(app()->call([new DispatchDueProbesJob, 'handle']))->toBe(0);
});

it('plans the next periodic probe after a probe, and none when the interval is unset', function () {
    [$workspace] = hlSetup();
    $source = hlSource($workspace);
    $this->curl->queue = [FakeCurl::answer(200, '{}'), FakeCurl::answer(200, '{}')];

    hlProbe($workspace, $source);
    expect(hlHealth()[0]['next_probe_at'])->toBeNull();

    hlSettings(['tunables.health.probe_interval' => '600']);
    hlProbe($workspace, $source);
    expect(strtotime(hlHealth()[0]['next_probe_at']))->toBeGreaterThan(time() + 590);
});

it('skips a periodic probe of a source that has current sync targets, but not a save-time one', function () {
    [$workspace] = hlSetup();
    $source = hlSource($workspace);
    Cluster::seedSyncTarget($workspace, ['data_source_id' => $source]);
    $this->curl->queue = [FakeCurl::answer(200, '{}')];

    hlProbe($workspace, $source, true);
    expect($this->curl->calls)->toBe([])->and(hlProbeRuns())->toBe([]);

    hlProbe($workspace, $source);
    expect($this->curl->calls)->toHaveCount(1)->and(hlHealth()[0]['status'])->toBe('healthy');
});

it('with the demand rule on, skips a periodic probe only for a source with a hot scheduled target', function () {
    [$workspace] = hlSetup();
    hlSettings(['tunables.sync.hot_window' => '600']);
    $idle = hlSource($workspace, 'Idle');
    $hot = hlSource($workspace, 'Hot');
    $userScoped = hlSource($workspace, 'Per user');
    Cluster::seedSyncTarget($workspace, ['data_source_id' => $idle, 'next_due_at' => '2020-01-01', 'refresh_interval_seconds' => 60]);
    Cluster::seedSubscription($workspace, Cluster::seedSyncTarget($workspace, ['data_source_id' => $hot, 'next_due_at' => '2020-01-01', 'refresh_interval_seconds' => 60]));
    Cluster::seedSubscription($workspace, Cluster::seedSyncTarget($workspace, ['data_source_id' => $userScoped, 'user_scoped' => true]));
    $this->curl->queue = [FakeCurl::answer(200, '{}'), FakeCurl::answer(200, '{}')];

    hlProbe($workspace, $hot, true);
    expect($this->curl->calls)->toBe([]);

    hlProbe($workspace, $idle, true);
    hlProbe($workspace, $userScoped, true);
    expect($this->curl->calls)->toHaveCount(2);
});

it('gives role system only the probe columns, only rows that can be due, and one column to change', function () {
    [$workspace] = hlSetup();
    $due = hlSource($workspace, 'Due', ['next_probe_at' => '2026-10-01 00:00:00+00']);
    $notScheduled = hlSource($workspace, 'None');
    $system = Cluster::system();

    $rows = Cluster::rows($system, 'select data_source_id, next_probe_at from data_source_health');
    expect(array_column($rows, 'data_source_id'))->toBe([$due]);
    expect(fn () => $system->query('select status from data_source_health'))->toThrow(PDOException::class);
    expect(fn () => $system->query('select last_probe_ok from data_source_health'))->toThrow(PDOException::class);
    expect(fn () => $system->query("update data_source_health set status = 'healthy'"))->toThrow(PDOException::class);
    expect(fn () => $system->query('delete from data_source_health'))->toThrow(PDOException::class);

    $moved = $system->prepare("update data_source_health set next_probe_at = now() + interval '1 hour' where data_source_id = ?");
    $moved->execute([$due]);
    expect($moved->rowCount())->toBe(1);
    $notDue = $system->prepare('update data_source_health set next_probe_at = now() where data_source_id = ?');
    $notDue->execute([$notScheduled]);
    expect($notDue->rowCount())->toBe(0);
    // Not due now: the row it just moved cannot be moved again before it is due.
    $again = $system->prepare('update data_source_health set next_probe_at = now() where data_source_id = ?');
    $again->execute([$due]);
    expect($again->rowCount())->toBe(0);
});

it('keeps the app role from deleting health rows and keeps them under row-level security', function () {
    [$workspace] = hlSetup();
    hlSource($workspace);
    $other = Cluster::workspace('Other');
    hlSource($other, 'Theirs');
    $app = Cluster::pooledApp();

    expect(fn () => $app->query('delete from data_source_health'))->toThrow(PDOException::class);

    $seen = Cluster::inWorkspace($app, $workspace, fn () => Cluster::rows($app, 'select workspace_id from data_source_health'));
    expect(array_unique(array_column($seen, 'workspace_id')))->toBe([$workspace]);
});

// ---- The health path column ----

it('stores and returns the health path, validates it like an Endpoint path, and audits it as a hash', function () {
    [$workspace] = hlSetup();
    $source = hlSave(['health_path' => '/health/live']);

    $this->getJson('/api/v1/admin/data-sources/'.$source, HL_HEADERS)->assertOk()->assertJsonPath('data.health_path', '/health/live');
    expect(Cluster::rows(Cluster::superuser(), 'select health_path from data_sources')[0]['health_path'])->toBe('/health/live');

    $audit = Cluster::rows(Cluster::superuser(), "select after_state from audit_events where action = 'connector.data_source.created'")[0]['after_state'];
    expect($audit)->not->toContain('/health/live')->and(json_decode($audit, true))->toHaveKey('health_path');

    $this->putJson('/api/v1/admin/data-sources/'.$source, ['name' => 'Sales API', 'base_url' => 'https://api.example.com/v1', 'headers' => [], 'auth_type' => 'none', 'revision' => 1], HL_HEADERS)
        ->assertOk()->assertJsonPath('data.health_path', null);

    foreach (['health' => 'health-path-leading-slash', '/health?x=1' => 'health-path-query', '/a#b' => 'health-path-fragment', '/a/../b' => null, '/{id}' => 'health-path-placeholder', 'https://evil.test/x' => null, '/'.str_repeat('a', 255) => 'health-path-too-long'] as $bad => $reason) {
        $response = $this->postJson('/api/v1/admin/data-sources', ['name' => 'Other '.Str::random(4), 'base_url' => 'https://api.example.com/v1', 'headers' => [], 'auth_type' => 'none', 'health_path' => $bad], HL_HEADERS);

        $response->assertStatus(422)->assertJsonValidationErrors('health_path');
        $reason === null || $response->assertJsonPath('reasons.health_path', $reason);
    }

    expect(fn () => Cluster::superuser()->query("update data_sources set health_path = 'no-slash'"))->toThrow(PDOException::class)
        ->and(fn () => Cluster::superuser()->query("update data_sources set health_path = '/a?b'"))->toThrow(PDOException::class);
});

// ---- Secrets, metrics and the migration ----

it('keeps a canary in a header, the health path, a response and a failure out of runs, events, audit, logs and metrics', function () {
    [$workspace] = hlSetup();
    $seen = [];
    app()->instance(MetricEmitter::class, new class($seen) implements MetricEmitter
    {
        /** @param  list<string>  $seen */
        public function __construct(public array &$seen) {}

        public function increment(string $name, array $labels = [], int $by = 1): void
        {
            expect(array_keys($labels))->toBe(['workspace_id']);
            $this->seen[] = $name;
        }
    });
    $source = hlSave(['health_path' => '/p-'.HL_CANARY, 'headers' => [['name' => 'X-Key', 'value' => 'h-'.HL_CANARY]]]);
    $this->curl->queue = [FakeCurl::answer(200, '{"note":"b-'.HL_CANARY.'"}'), FakeCurl::answer(500, 'e-'.HL_CANARY, ['x-secret' => [HL_CANARY], 'content-type' => ['text/plain']]), new EgressTransportFailed('timeout '.HL_CANARY, 28)];

    // The default queue is `sync`: the relay runs the probe in place.
    hlRelay();
    hlProbe($workspace, $source);
    hlProbe($workspace, $source);

    expect($this->curl->calls[0][CURLOPT_URL])->toContain('/p-'.HL_CANARY)->and($this->curl->calls[0][CURLOPT_HTTPHEADER])->toContain('X-Key: h-'.HL_CANARY);

    $everything = json_encode([
        Cluster::rows(Cluster::superuser(), 'select * from sync_runs'),
        Cluster::rows(Cluster::superuser(), 'select * from audit_events'),
        Cluster::rows(Cluster::superuser(), 'select * from outbox_events'),
        Cluster::rows(Cluster::superuser(), 'select * from operations'),
        Cluster::rows(Cluster::superuser(), 'select * from data_source_health'),
        (string) file_get_contents($this->logFile),
        $seen,
    ], JSON_THROW_ON_ERROR);

    expect($everything)->not->toContain(HL_CANARY)
        ->and($seen)->toContain('dashflow.connector.health_probe')->toContain('dashflow.ingestion.health_changed');
});

it('adds the health table and the health path in one migration that rolls back and runs again', function () {
    $columns = fn (string $table, string $column): int => (int) Cluster::rows(Cluster::superuser(), "select count(*) as n from information_schema.columns where table_name = '{$table}' and column_name = '{$column}'")[0]['n'];

    expect($columns('data_sources', 'health_path'))->toBe(1)->and($columns('data_source_health', 'status'))->toBe(1);

    try {
        expect(Artisan::call('migrate:rollback', ['--database' => 'migrator', '--step' => 3, '--force' => true]))->toBe(0)
            ->and($columns('data_sources', 'health_path'))->toBe(0)->and($columns('data_source_health', 'status'))->toBe(0);
    } finally {
        Artisan::call('migrate', ['--database' => 'migrator', '--force' => true]);
    }

    expect($columns('data_sources', 'health_path'))->toBe(1)->and($columns('data_source_health', 'status'))->toBe(1);
});

it('probes a Data Source whose stored Base URL no longer parses as a failed probe, never an exception', function () {
    [$workspace] = hlSetup();
    $source = hlSource($workspace);
    Cluster::superuser()->prepare("UPDATE data_sources SET base_url = 'not a url' WHERE id = ?")->execute([$source]);

    hlProbe($workspace, $source);

    expect($this->curl->calls)->toBe([])
        ->and(hlHealth()[0])->toMatchArray(['status' => 'unreachable', 'last_probe_ok' => false])
        ->and(hlProbeRuns()[0])->toMatchArray(['status' => 'failed', 'error_code' => 'fetch-failed']);
});

it('seeds next_probe_at at registration and after a breaker-open early return when the interval is valid', function () {
    [$workspace] = hlSetup();
    hlSettings(['tunables.health.probe_interval' => '600']);
    $queue = app('queue');
    Queue::fake();
    $source = hlSave();
    hlRelay();

    expect(strtotime(hlHealth()[0]['next_probe_at']))->toBeGreaterThan(time() + 590);

    // An early return for an open breaker still plans the next probe; the probe job runs for real again.
    Queue::swap($queue);
    Cluster::superuser()->prepare('UPDATE data_source_health SET next_probe_at = NULL')->execute();
    hlSettings(['tunables.circuit_breaker.failure_count' => '1', 'tunables.circuit_breaker.cool_down' => '30']);
    $governor = hlGovernor();
    $governor->record($workspace, $source, CallOutcome::Failed, app(SyncSettings::class)->governorLimits());
    hlProbe($workspace, $source);

    expect($this->curl->calls)->toBe([])->and(strtotime(hlHealth()[0]['next_probe_at']))->toBeGreaterThan(time() + 590);
});

it('handles connector.data_source.updated: keeps an existing status, makes a missing row Checking, and queues a probe each time', function () {
    [$workspace] = hlSetup();
    $known = hlSource($workspace, 'Known', ['status' => 'degraded', 'status_seq' => 3]);
    $bare = Cluster::seedDataSource($workspace, 'Bare');
    $body = fn (string $name) => ['name' => $name, 'base_url' => 'https://api.example.com/v1', 'headers' => [], 'auth_type' => 'none', 'revision' => 1];
    $this->putJson('/api/v1/admin/data-sources/'.$known, $body('Known'), HL_HEADERS)->assertOk();
    $this->putJson('/api/v1/admin/data-sources/'.$bare, $body('Bare'), HL_HEADERS)->assertOk();
    Queue::fake();

    hlRelay();

    $rows = collect(hlHealth())->keyBy('data_source_id');
    expect($rows)->toHaveCount(2)
        ->and($rows[$known])->toMatchArray(['status' => 'degraded', 'status_seq' => 3])
        ->and($rows[$bare])->toMatchArray(['status' => 'checking', 'status_seq' => 0]);
    Queue::assertPushed(ProbeDataSourceJob::class, 2);
});

it('carries the health and last success in the 409 stale-revision and 423 lost-lock bodies', function () {
    [$workspace] = hlSetup();
    $source = hlSource($workspace, 'Sales API', ['status' => 'unreachable', 'last_probe_success_at' => '2026-10-09 10:42:00+00']);
    $body = ['name' => 'Sales API', 'base_url' => 'https://api.example.com/v1', 'headers' => [], 'auth_type' => 'none'];

    $this->putJson('/api/v1/admin/data-sources/'.$source, $body + ['revision' => 99], HL_HEADERS)->assertStatus(409)
        ->assertJsonPath('current.data.health', 'unreachable')->assertJsonPath('current.data.last_successful_call_at', '2026-10-09T10:42:00Z');

    $this->putJson('/api/v1/admin/data-sources/'.$source, $body + ['revision' => 1, 'lock_epoch' => 99], HL_HEADERS)->assertStatus(423)
        ->assertJsonPath('current.data.health', 'unreachable')->assertJsonPath('current.data.last_successful_call_at', '2026-10-09T10:42:00Z');
});

it('treats a 2xx answer over the size limit as reachable and a failing one as Unreachable', function () {
    [$workspace] = hlSetup();
    $source = hlSource($workspace);
    $this->curl->queue = [new ResponseLimitExceeded(2048, 1024, 200)];

    hlProbe($workspace, $source);

    expect(hlHealth()[0])->toMatchArray(['last_probe_ok' => true, 'status' => 'healthy']);

    $this->curl->queue = [new ResponseLimitExceeded(2048, 1024, 500)];

    hlProbe($workspace, $source);

    expect(hlHealth()[0])->toMatchArray(['last_probe_ok' => false, 'status' => 'unreachable']);
});
