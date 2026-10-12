<?php

use App\Modules\Blocks\Application\PreviewDraft;
use App\Modules\Blocks\Application\PurgeDiscardedDrafts;
use App\Modules\Blocks\Application\RunDraftValidation;
use App\Modules\Blocks\Contracts\ErrorCode as BlocksErrorCode;
use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\Connector\Application\RunSampleFetch;
use App\Modules\Connector\Contracts\EndpointValidationRun;
use App\Modules\Connector\Contracts\EndpointValidations;
use App\Modules\Connector\Contracts\HostResolver;
use App\Modules\Connector\Contracts\PreparedEndpointValidation;
use App\Modules\Connector\Infrastructure\CurlClient;
use App\Modules\Mapping\Contracts\PlanGuards;
use App\Modules\Mapping\Contracts\PlanResult;
use App\Modules\Mapping\Contracts\QueryPlans;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use App\Platform\Operations\Operation;
use App\Platform\Operations\OperationKinds;
use App\Platform\Operations\OperationOutcome;
use App\Platform\Operations\Operations;
use App\Platform\Operations\OperationStatus;
use App\Platform\Operations\RunOperation;
use App\Platform\Outbox\Outbox;
use App\Platform\Tenancy\TenantKey;
use App\Platform\Tenancy\WorkspaceTransaction;
use App\Support\Observability\RequestContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;
use Tests\Unit\Support\FakeCurl;
use Tests\Unit\Support\FakeResolver;

// Story 3.18 against the real PostgreSQL: POST/GET /api/v1/admin/blocks/drafts/{draft}/validation. The `publish_validation` Operation fetches the Draft's
// real Endpoint through the Connector, runs the Draft's plan over the live response with the Query Plan engine and stores a ValidationReport bound to the
// Draft's revision. Helpers (bd*, dp*, sf*, fu*) come from DraftBlocksTest, DraftPreviewTest, SampleFetchTest and FetchAsUserTest, which load first.
const PV_CANARY = 'CANARY-pv-body-3f9a';
const PV_BODY = '{"data":{"revenue":12345.50,"label":"Revenue CANARY-pv-body-3f9a"}}';

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
    PreviewDraft::forget();

    $this->keyFile = tempnam(sys_get_temp_dir(), 'dashflow-data-key');
    $this->digestFile = tempnam(sys_get_temp_dir(), 'dashflow-digest-key');
    file_put_contents($this->keyFile, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    file_put_contents($this->digestFile, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    config([
        'dashflow.secrets.data_key_path.value' => $this->keyFile,
        'dashflow.secrets.digest_key_path.value' => $this->digestFile,
        'dashflow.tunables.guards.max_bytes.value' => '5000',
        'dashflow.tunables.guards.depth_limit.value' => '10',
        'dashflow.tunables.guards.platform_timeout_ceiling.value' => '300',
        'dashflow.tunables.timeouts.draft_sample_ttl.value' => '600',
        'dashflow.tunables.timeouts.edit_lock_ttl.value' => null,
        'dashflow.sample_fetch.membership_limit.value' => '100',
        'dashflow.sample_fetch.workspace_limit.value' => null,
        'dashflow.sample_fetch.window.value' => '60',
        'dashflow.preview.membership_limit.value' => '100',
        'dashflow.preview.window_seconds.value' => '60',
        'dashflow.preview.max_extracted_values.value' => '1000',
        'dashflow.preview.max_evaluations.value' => '1000',
        'dashflow.preview.max_wall_ms.value' => '5000',
    ]);

    $this->logFile = tempnam(sys_get_temp_dir(), 'dashflow-log');
    config(['logging.default' => 'single', 'logging.channels.single.path' => $this->logFile]);

    $this->curl = new FakeCurl;
    app()->instance(HostResolver::class, new FakeResolver(['api.example.com' => ['93.184.216.34'], 'other.example.com' => ['93.184.216.40']]));
    app()->instance(CurlClient::class, $this->curl);
});

afterEach(function () {
    @unlink($this->keyFile);
    @unlink($this->digestFile);
    @unlink($this->logFile);
    PreviewDraft::forget();
});

/**
 * An Admin who builds blocks and manages Data Sources (signed in), a Data Source, a Draft whose config names that Endpoint and carries a plan over
 * `$.data.revenue` and `$.data.label`, at revision 2. Returns [workspace, membership, data source, endpoint, draft].
 *
 * @param  array<string, mixed>  $plan
 * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
 */
function pvWorld(?array $plan = null, bool $withSource = true, string $host = 'api.example.com'): array
{
    $workspace = Cluster::workspace('Acme');
    [, $membership] = sfAdmin($workspace, ['data_sources.manage', 'blocks.edit']);
    $source = Cluster::seedDataSource($workspace, 'Sales API', $host);
    $endpoint = sfEndpoint($source, ['path' => '/revenue', 'params' => [['name' => 'region', 'binding' => 'fixed', 'value' => 'EU']], 'headers' => []]);
    $draft = bdCreate()->assertCreated()->json('data.draft_id');

    pvConfigure($draft, $plan ?? dpPlan(['rev' => '$.data.revenue', 'name' => '$.data.label'], ['headline_value' => 'rev', 'kpi_label' => 'name']), $withSource ? $source : null, $endpoint);

    return [$workspace, $membership, $source, $endpoint, $draft];
}

/** Saves the plan and, when given, the source on the Draft (revision 1 to 2). */
function pvConfigure(string $draft, array $plan, ?string $source, ?string $endpoint, int $revision = 1): void
{
    $config = ['query_plan' => $plan] + ($source === null ? [] : ['source' => ['data_source_id' => $source, 'endpoint_id' => $endpoint]]);
    bdSave($draft, $revision, 1, ['config' => $config])->assertOk();
}

function pvUrl(string $draft): string
{
    return BD_URL."/{$draft}/validation";
}

function pvStart(string $draft, array $overrides = [])
{
    return test()->postJson(pvUrl($draft), $overrides + ['lock_epoch' => 1], BD_HEADERS);
}

/** Starts a validation that is accepted and returns the Operation's ID (the queue is `sync`: it has run when this returns). */
function pvRun(string $draft, array $overrides = []): string
{
    return (string) pvStart($draft, $overrides)->assertStatus(202)->assertHeader('Cache-Control', 'no-store, private')->json('data.operation_id');
}

function pvReport(string $draft)
{
    return test()->getJson(pvUrl($draft), BD_HEADERS);
}

function pvReports(): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from validation_reports order by created_at, id');
}

/** Everything a validation could have leaked into, as one string. */
function pvEverything(): string
{
    return json_encode([
        Cluster::rows(Cluster::superuser(), 'select * from operations'),
        Cluster::rows(Cluster::superuser(), 'select * from sync_runs'),
        Cluster::rows(Cluster::superuser(), 'select * from audit_events'),
        Cluster::rows(Cluster::superuser(), 'select * from outbox_events'),
        Cluster::rows(Cluster::superuser(), 'select * from validation_reports'),
        Cluster::rows(Cluster::superuser(), 'select id, config from block_versions'),
        (string) file_get_contents(test()->logFile),
    ], JSON_THROW_ON_ERROR);
}

it('validates against the live response: a passed report bound to the Draft revision, audited, with the version to create and nothing of the body kept', function () {
    [$workspace, $membership, , $endpoint, $draft] = pvWorld();
    $this->curl->queue = [FakeCurl::answer(200, PV_BODY)];

    $id = pvRun($draft);
    $operation = Cluster::rows(Cluster::superuser(), 'select * from operations where id = ?', [$id])[0];

    expect($operation)->toMatchArray(['kind' => 'publish_validation', 'status' => 'succeeded', 'subject_type' => 'draft', 'subject_id' => $draft, 'subject_revision' => 2, 'requester_membership_id' => $membership])
        ->and($this->curl->calls)->toHaveCount(1)
        ->and($this->curl->calls[0][CURLOPT_URL])->toBe('https://api.example.com/revenue?region=EU');

    $summary = sfPoll($id)->assertOk()->json('data');
    expect($summary['result'])->toMatchArray(['ok' => true, 'status' => 'passed', 'mismatches' => 0, 'draft_revision' => 2]);

    $rows = pvReports();
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['workspace_id' => $workspace, 'block_version_id' => $draft, 'operation_id' => $id, 'subject_revision' => 2, 'status' => 'passed', 'endpoint_id' => $endpoint, 'created_by_membership_id' => $membership])
        ->and(json_decode($rows[0]['mismatches'], true))->toBe([]);

    $report = pvReport($draft)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect($report->json('data'))->toMatchArray(['status' => 'passed', 'stored_status' => 'passed', 'subject_revision' => 2, 'draft_revision' => 2, 'version' => '1.0', 'can_publish' => true, 'mismatches' => []]);

    // One audit event "validated" on the pass, with IDs and the revision only.
    $audits = bdAudits('blocks.draft.validated');
    expect($audits)->toHaveCount(1)
        ->and($audits[0]['actor'])->toBe($membership)
        ->and(json_decode($audits[0]['after_state'], true))->toMatchArray(['draft_id' => $draft, 'revision' => 2, 'report_id' => $rows[0]['id']]);

    // The live body went through the sealed blob only and is dropped; no table, log or response holds it.
    expect(Cache::get(TenantKey::cache($workspace, 'sample:'.$id)))->toBeNull()
        ->and(pvEverything())->not->toContain(PV_CANARY)->not->toContain('12345.50')
        ->and(sfCount('draft_samples'))->toBe(0)
        ->and(sfCount('raw_bodies'))->toBe(0);
});

it('reads a passed report as stale after any later autosave, and Publish is not allowed again', function () {
    [, , , , $draft] = pvWorld();
    $this->curl->queue = [FakeCurl::answer(200, PV_BODY)];
    pvRun($draft);

    expect(pvReport($draft)->json('data.can_publish'))->toBeTrue();

    bdSave($draft, 2, 1, ['name' => 'Renamed'])->assertOk();

    $report = pvReport($draft)->assertOk()->json('data');
    expect($report)->toMatchArray(['status' => 'stale', 'stored_status' => 'passed', 'subject_revision' => 2, 'draft_revision' => 3, 'can_publish' => false]);
});

it('fails a response that lacks a mapped path or has the wrong type, lists the paths and types, and Save draft stays available', function (string $body, array $expected) {
    [, , , , $draft] = pvWorld();
    $this->curl->queue = [FakeCurl::answer(200, $body)];

    $id = pvRun($draft);
    $report = pvReport($draft)->assertOk()->json('data');

    expect(sfPoll($id)->json('data.status'))->toBe('failed')
        ->and($report)->toMatchArray(['status' => 'failed', 'reason' => 'shape_mismatch', 'can_publish' => false])
        ->and($report['mismatches'])->toBe($expected)
        ->and(pvEverything())->not->toContain(PV_CANARY);

    // Save draft is a normal save and works.
    bdSave($draft, 2, 1, ['name' => 'Still editable'])->assertOk();
})->with([
    'a mapped path is missing' => ['{"data":{"revenue":12.5,"title":"CANARY-pv-body-3f9a"}}', [['path' => '$.data.label', 'slot' => 'kpi_label', 'type' => 'missing', 'expected' => 'text']]],
    'a number slot gets an object' => ['{"data":{"revenue":{"amount":"CANARY-pv-body-3f9a"},"label":"x"}}', [['path' => '$.data.revenue', 'slot' => 'headline_value', 'type' => 'wrong_type', 'expected' => 'number']]],
    'a number slot gets text' => ['{"data":{"revenue":"CANARY-pv-body-3f9a","label":"x"}}', [['path' => '$.data.revenue', 'slot' => 'headline_value', 'type' => 'wrong_type', 'expected' => 'number']]],
]);

it('reports unreachable when the live API cannot be fetched, and Publish is blocked', function (string $host, array $curl, string $reason) {
    [, , , , $draft] = pvWorld(host: $host);
    $this->curl->queue = $curl;

    $id = pvRun($draft);
    $report = pvReport($draft)->assertOk()->json('data');

    expect(sfPoll($id)->json('data.status'))->toBe('failed')
        ->and($report)->toMatchArray(['status' => 'unreachable', 'reason' => $reason, 'can_publish' => false, 'mismatches' => []])
        ->and(bdAudits('blocks.draft.validated'))->toBe([])
        ->and(pvEverything())->not->toContain(PV_CANARY);
})->with([
    'a server error' => ['api.example.com', [FakeCurl::answer(500, '{"error":"CANARY-pv-body-3f9a"}')], 'fetch-failed'],
    'not JSON' => ['api.example.com', [FakeCurl::answer(200, '<html>CANARY-pv-body-3f9a</html>', ['content-type' => ['text/html']])], 'not-json'],
    'a host that is not allowlisted' => ['other.example.com', [], 'host-not-allowlisted'],
]);

it('refuses a Draft with no real Data Source and Endpoint: a pasted sample is never proof, nothing is queued or fetched', function () {
    Queue::fake();
    [, , , , $draft] = pvWorld(withSource: false);
    dpPaste($draft, PV_BODY);

    $response = pvStart($draft)->assertStatus(422);

    expect($response->json('error.code'))->toBe(BlocksErrorCode::ValidationRefused->value)
        ->and($response->json('reason'))->toBe('no_real_source')
        ->and(sfCount('operations'))->toBe(0)
        ->and($this->curl->calls)->toBe([])
        ->and(pvReports())->toBe([]);
    Queue::assertNothingPushed();
    pvReport($draft)->assertOk()->assertJsonPath('data', null);
});

it('fails fast with the strict-schema list and makes no request when a required slot is unmapped', function () {
    [, , , , $draft] = pvWorld(dpPlan(['rev' => '$.data.revenue'], ['headline_value' => 'rev']));

    $id = pvRun($draft);
    $report = pvReport($draft)->assertOk()->json('data');

    expect($this->curl->calls)->toBe([])
        ->and(sfPoll($id)->json('data.status'))->toBe('failed')
        ->and($report)->toMatchArray(['status' => 'failed', 'reason' => 'strict_schema', 'can_publish' => false])
        ->and($report['mismatches'])->toBe([['path' => null, 'slot' => 'kpi_label', 'type' => 'required_slot_unmapped', 'expected' => 'text']])
        ->and(sfRuns())->toBe([]);
});

it('fails fast on a Draft that has lost its plan', function () {
    [, , $source, $endpoint, $draft] = pvWorld();
    // A plan that is gone: the config as stored by an older save, set directly.
    Cluster::superuser()->prepare("UPDATE block_versions SET config = config - 'query_plan' WHERE id = ?")->execute([$draft]);

    pvRun($draft);
    $report = pvReport($draft)->json('data');

    expect($report)->toMatchArray(['status' => 'failed', 'reason' => 'strict_schema'])
        ->and($report['mismatches'][0])->toMatchArray(['path' => 'config.query_plan', 'type' => 'no_plan'])
        ->and($this->curl->calls)->toBe([]);
});

it('stores a report stale, with nothing fetched, when the Draft moved on before the worker ran', function () {
    Queue::fake();
    [, , , , $draft] = pvWorld();
    $this->curl->queue = [FakeCurl::answer(200, PV_BODY)];

    $id = pvRun($draft);
    bdSave($draft, 2, 1, ['name' => 'Edited while queued'])->assertOk();
    app(WorkspaceTransaction::class)->runJob(Queue::pushed(RunOperation::class)->first(), fn ($job) => $job->handle(app(Operations::class)));

    $rows = pvReports();
    expect(sfPoll($id)->json('data.status'))->toBe('stale')
        ->and($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['status' => 'stale', 'reason' => 'revision_moved', 'subject_revision' => 2])
        ->and($this->curl->calls)->toBe([])
        ->and(bdAudits('blocks.draft.validated'))->toBe([])
        ->and(pvReport($draft)->json('data'))->toMatchArray(['status' => 'stale', 'can_publish' => false]);
});

it('stores stale, never passed, when the revision moves while the live request is in flight', function () {
    [, , , , $draft] = pvWorld();
    $this->curl->queue = [FakeCurl::answer(200, PV_BODY)];
    // The Admin autosaves between the fetch and the report.
    $real = app(EndpointValidations::class);
    app()->instance(EndpointValidations::class, new class($real, $draft) implements EndpointValidations
    {
        public function __construct(private EndpointValidations $inner, private string $draft) {}

        public function prepare($actor, string $dataSourceId, string $endpointId, array $bindings, ?array $range, ?array $period): PreparedEndpointValidation
        {
            return $this->inner->prepare($actor, $dataSourceId, $endpointId, $bindings, $range, $period);
        }

        public function release($actor): void
        {
            $this->inner->release($actor);
        }

        public function run(Operation $operation, string $dataSourceId, string $endpointId, int $endpointRevision, array $values, bool $userBound): EndpointValidationRun
        {
            $run = $this->inner->run($operation, $dataSourceId, $endpointId, $endpointRevision, $values, $userBound);
            // The Draft's revision moves in this very transaction, as an autosave that committed meanwhile would.
            DB::update('update block_versions set revision = revision + 1 where id = ?', [$this->draft]);

            return $run;
        }

        public function take(Operation $operation, string $endpointId, int $endpointRevision): ?string
        {
            return $this->inner->take($operation, $endpointId, $endpointRevision);
        }

        public function discard(Operation $operation): void
        {
            $this->inner->discard($operation);
        }
    });

    $id = pvRun($draft);

    expect(sfPoll($id)->json('data.status'))->toBe('stale')
        ->and(pvReports()[0])->toMatchArray(['status' => 'stale', 'reason' => 'revision_moved', 'subject_revision' => 2])
        ->and(bdAudits('blocks.draft.validated'))->toBe([])
        ->and(pvReport($draft)->json('data.can_publish'))->toBeFalse();
});

it('answers 429 with retry_after over the per-membership limit, counts fetches only, and queues nothing', function () {
    Queue::fake();
    [, , , , $draft] = pvWorld();
    config(['dashflow.sample_fetch.membership_limit.value' => '1', 'dashflow.sample_fetch.window.value' => '60']);

    pvStart($draft)->assertStatus(202);
    $second = pvStart($draft)->assertStatus(429)->assertHeader('Retry-After');

    expect($second->json('error.code'))->toBe(PlatformErrorCode::TooManyRequests->value)
        ->and($second->json('error.retry_after'))->toBeBetween(1, 60)
        ->and(sfCount('operations'))->toBe(1);
    Queue::assertPushed(RunOperation::class, 1);
});

it('refuses naming the setting when a limit, the window, the ceiling or a preview guard is unset: nothing queued, nothing fetched', function (string $path, mixed $value, string $setting) {
    Queue::fake();
    [, , , , $draft] = pvWorld();
    config([$path => $value]);

    $response = pvStart($draft)->assertStatus(422);

    expect($response->json('error.code'))->toBe(BlocksErrorCode::ValidationRefused->value)
        ->and($response->json('reason'))->toBe('setting_not_configured')
        ->and($response->json('setting'))->toBe($setting)
        ->and(sfCount('operations'))->toBe(0)
        ->and($this->curl->calls)->toBe([]);
    Queue::assertNothingPushed();
})->with([
    'membership limit' => ['dashflow.sample_fetch.membership_limit.value', null, 'DASHFLOW_SAMPLE_FETCH_MEMBERSHIP_LIMIT'],
    'window' => ['dashflow.sample_fetch.window.value', null, 'DASHFLOW_SAMPLE_FETCH_WINDOW_SECONDS'],
    'timeout ceiling' => ['dashflow.tunables.guards.platform_timeout_ceiling.value', null, 'DASHFLOW_GUARD_PLATFORM_TIMEOUT_CEILING'],
    'a preview guard' => ['dashflow.preview.max_evaluations.value', null, 'DASHFLOW_PREVIEW_MAX_EVALUATIONS'],
]);

it('needs the edit-lock claim of a save: a stale epoch is a 423 and nothing is queued', function () {
    Queue::fake();
    [, , , , $draft] = pvWorld();

    $response = pvStart($draft, ['lock_epoch' => 7])->assertStatus(423);

    expect($response->json('error.code'))->toBe(PlatformErrorCode::EditLockLost->value)
        ->and(sfCount('operations'))->toBe(0);
});

it('never takes dates from the client: a range or a period in the request is a 422 and nothing is queued', function (array $dates, string $field) {
    Queue::fake();
    [, , , , $draft] = pvWorld();

    pvStart($draft, $dates)->assertStatus(422)->assertJsonValidationErrors([$field], 'errors');

    expect(sfCount('operations'))->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'range' => [['range' => ['from' => '2026-01-01', 'to' => '2026-01-31']], 'range'],
    'period' => [['period' => ['start' => '2026-01-01', 'end' => '2026-01-31']], 'period'],
]);

it('is the Workspace\'s alone: another Workspace reads a 404', function () {
    [, , , , $draft] = pvWorld();
    $this->curl->queue = [FakeCurl::answer(200, PV_BODY)];
    pvRun($draft);

    $other = Cluster::workspace('Beta');
    bdAdmin($other, ['blocks.edit'], 'bea@example.test');

    pvReport($draft)->assertNotFound();
});

it('keeps the history of reports, reads the newest, and the purge deletes a discarded Draft\'s reports', function () {
    [$workspace, , , , $draft] = pvWorld();
    $this->curl->queue = [FakeCurl::answer(200, PV_BODY), FakeCurl::answer(200, '{"data":{"revenue":1}}')];

    pvRun($draft);
    pvRun($draft);

    expect(pvReports())->toHaveCount(2)
        ->and(pvReport($draft)->json('data.status'))->toBe('failed')
        ->and(pvReport($draft)->json('data.mismatches.0.type'))->toBe('missing');

    $this->deleteJson(BD_URL."/{$draft}", [], BD_HEADERS)->assertSuccessful();
    app(PurgeDiscardedDrafts::class)->run($workspace);

    expect(pvReports())->toBe([]);
});

// The Endpoint is bound to the member (user id, an attribute and a group). The Admin is the member the live check runs as.
function pvBoundWorld(bool $attribute = true): array
{
    $workspace = Cluster::workspace('Acme');
    [$adminUser, $admin] = fuAdmin($workspace, [...FU_ALL, 'blocks.edit']);
    Cluster::seedAttributeKey($workspace, 'region');

    if ($attribute) {
        fuGroup($workspace, $admin, 'Finance');
        // Another administrator sets the Admin's attribute: nobody edits their own.
        [$boss] = fuMember($workspace, 'boss@example.test', 'admin', FU_ALL);
        fuSignIn($boss, $workspace);
        fuAttribute($admin, ['region' => 'CANARY-pv-attr-77aa']);
        fuSignIn($adminUser, $workspace);
    }

    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = fuEndpoint($source);
    $draft = bdCreate()->assertCreated()->json('data.draft_id');
    pvConfigure($draft, dpPlan(['rev' => '$.data.revenue', 'name' => '$.data.label'], ['headline_value' => 'rev', 'kpi_label' => 'name']), $source, $endpoint);

    return [$workspace, $admin, $source, $endpoint, $draft];
}

it('checks a user-context Endpoint unbound first: refused by the platform with access.context_missing and no request, then proves the shape as the requester', function () {
    [, , , , $draft] = pvBoundWorld();
    $this->curl->queue = [FakeCurl::answer(200, PV_BODY)];

    $id = pvRun($draft);
    $report = pvReport($draft)->assertOk()->json('data');

    // Only the as-requester request was sent; the unbound attempt was refused before any request.
    expect($this->curl->calls)->toHaveCount(1)
        ->and($this->curl->calls[0][CURLOPT_URL])->toContain('region=CANARY-pv-attr-77aa')
        ->and($report)->toMatchArray(['status' => 'passed', 'can_publish' => true])
        ->and(sfPoll($id)->json('data.status'))->toBe('succeeded');

    $runs = Cluster::rows(Cluster::superuser(), 'select status, http_status, error_code from sync_runs order by started_at, id');
    expect($runs)->toHaveCount(2)
        ->and($runs[0])->toMatchArray(['status' => 'failed', 'error_code' => 'access.context_missing'])
        ->and($runs[0]['http_status'])->toBeNull()
        ->and($runs[1])->toMatchArray(['status' => 'succeeded', 'http_status' => 200])
        // The attribute value and the body are in no response, log, audit or report.
        ->and(pvEverything())->not->toContain('CANARY-pv-attr-77aa')->not->toContain(PV_CANARY);
});

it('fails a user-context Endpoint whose live check cannot be made as the requester, with no request', function () {
    [, , , , $draft] = pvBoundWorld(attribute: false);

    pvRun($draft);
    $report = pvReport($draft)->assertOk()->json('data');

    expect($this->curl->calls)->toBe([])
        ->and($report)->toMatchArray(['status' => 'failed', 'reason' => 'context_missing', 'can_publish' => false]);
});

it('fails an Endpoint that could be published unbound: the negative check was not refused', function () {
    [, , , , $draft] = pvWorld();
    app()->instance(EndpointValidations::class, new class implements EndpointValidations
    {
        public function prepare($actor, string $dataSourceId, string $endpointId, array $bindings, ?array $range, ?array $period): PreparedEndpointValidation
        {
            return new PreparedEndpointValidation($dataSourceId, $endpointId, 1, [], true);
        }

        public function release($actor): void {}

        public function run(Operation $operation, string $dataSourceId, string $endpointId, int $endpointRevision, array $values, bool $userBound): EndpointValidationRun
        {
            // A request went out without the member's attribute.
            return new EndpointValidationRun(new OperationOutcome(true, ['ok' => true, 'status' => 200]), false);
        }

        public function take(Operation $operation, string $endpointId, int $endpointRevision): ?string
        {
            return PV_BODY;
        }

        public function discard(Operation $operation): void {}
    });

    pvRun($draft);

    expect(pvReport($draft)->json('data'))->toMatchArray(['status' => 'failed', 'reason' => 'unbound_publishable', 'can_publish' => false])
        ->and(bdAudits('blocks.draft.validated'))->toBe([]);
});

it('reads a passed report as stale after its Endpoint is edited', function () {
    [, , $source, $endpoint, $draft] = pvWorld();
    $this->curl->queue = [FakeCurl::answer(200, PV_BODY)];
    pvRun($draft);

    expect(pvReport($draft)->json('data'))->toMatchArray(['status' => 'passed', 'can_publish' => true]);

    epUpdate($source, $endpoint, 1, ['path' => '/revenue-v2', 'params' => [['name' => 'region', 'binding' => 'fixed', 'value' => 'EU']], 'headers' => []])->assertOk();

    // The Draft did not move, the Endpoint did.
    expect(pvReport($draft)->json('data'))->toMatchArray(['status' => 'stale', 'stored_status' => 'passed', 'draft_revision' => 2, 'can_publish' => false]);
});

it('prefers the report of the current revision over a later-finishing older one', function () {
    [$workspace, $membership, , $endpoint, $draft] = pvWorld();
    $this->curl->queue = [FakeCurl::answer(200, PV_BODY)];
    pvRun($draft);
    // An older run lands after the current one.
    Cluster::superuser()->prepare("INSERT INTO validation_reports (id, workspace_id, block_version_id, operation_id, subject_revision, status, created_by_membership_id, created_at) VALUES (?, ?, ?, ?, 1, 'failed', ?, now() + interval '1 minute')")
        ->execute([(string) Str::uuid7(), $workspace, $draft, (string) Str::uuid7(), $membership]);

    expect(pvReport($draft)->json('data'))->toMatchArray(['status' => 'passed', 'subject_revision' => 2]);
});

it('fails a response whose record path is absent or has no records', function (string $body) {
    [, , $source, $endpoint, $draft] = pvWorld();
    // The Draft reads rows under data.rows: set the record path as the mapping endpoint stores it.
    Cluster::superuser()->prepare("UPDATE block_versions SET config = jsonb_set(config, '{record_path}', '\"$.data.rows[*]\"') WHERE id = ?")->execute([$draft]);
    $this->curl->queue = [FakeCurl::answer(200, $body)];

    pvRun($draft);
    $report = pvReport($draft)->json('data');

    expect($report)->toMatchArray(['status' => 'failed', 'reason' => 'shape_mismatch'])
        ->and($report['mismatches'])->toContain(['path' => '$.data.rows[*]', 'slot' => null, 'type' => 'missing', 'expected' => null]);
})->with([
    'no such path' => ['{"data":{"revenue":1,"label":"x"}}'],
    'zero records' => ['{"data":{"rows":[],"revenue":1,"label":"x"}}'],
]);

it('stores a failed report with engine_error when the engine throws something unexpected', function () {
    [, , , , $draft] = pvWorld();
    $this->curl->queue = [FakeCurl::answer(200, PV_BODY)];
    app()->instance(QueryPlans::class, new class implements QueryPlans
    {
        public function guards(): PlanGuards
        {
            return new PlanGuards(100, 100, 1000);
        }

        public function run(BlockType $type, array $plan, mixed $document, PlanGuards $guards, array $presentation = []): PlanResult
        {
            throw new RuntimeException('boom CANARY-pv-body-3f9a');
        }
    });

    pvRun($draft);

    expect(pvReport($draft)->json('data'))->toMatchArray(['status' => 'failed', 'reason' => 'engine_error'])
        ->and(pvEverything())->not->toContain('boom');
});

it('is idempotent: a repeated run of the same Operation returns its report and stores one row', function () {
    Queue::fake();
    [, , , , $draft] = pvWorld();
    $this->curl->queue = [FakeCurl::answer(200, PV_BODY), FakeCurl::answer(200, PV_BODY)];

    $id = pvRun($draft);
    $job = Queue::pushed(RunOperation::class)->first();
    app(WorkspaceTransaction::class)->runJob($job, fn ($j) => $j->handle(app(Operations::class)));
    // The runner skips an Operation that has ended; the handler itself is idempotent on its own.
    $operation = app(Operations::class)->status(pvReports()[0]['workspace_id'], $id, pvReports()[0]['created_by_membership_id']);
    $handler = app(RunDraftValidation::class);
    $again = app(WorkspaceTransaction::class)->run($operation->workspaceId, fn () => $handler->handle($operation, ['data_source_id' => '', 'endpoint_id' => '']));

    expect(pvReports())->toHaveCount(1)
        ->and($again->summary['report_id'])->toBe(pvReports()[0]['id']);
});

it('truncates a long reason to the column width', function () {
    [, , , , $draft] = pvWorld();
    $this->curl->queue = [FakeCurl::answer(200, PV_BODY)];
    app()->instance(EndpointValidations::class, new class implements EndpointValidations
    {
        public function prepare($actor, string $dataSourceId, string $endpointId, array $bindings, ?array $range, ?array $period): PreparedEndpointValidation
        {
            return new PreparedEndpointValidation($dataSourceId, $endpointId, 1, [], false);
        }

        public function release($actor): void {}

        public function run(Operation $operation, string $dataSourceId, string $endpointId, int $endpointRevision, array $values, bool $userBound): EndpointValidationRun
        {
            return new EndpointValidationRun(new OperationOutcome(false, ['ok' => false, 'code' => str_repeat('x', 150)]));
        }

        public function take(Operation $operation, string $endpointId, int $endpointRevision): ?string
        {
            return null;
        }

        public function discard(Operation $operation): void {}
    });

    pvRun($draft);

    expect(strlen(pvReports()[0]['reason']))->toBe(64)->and(pvReports()[0]['status'])->toBe('unreachable');
});

it('needs the lock holder when the edit lock is on: a non-holder token is a 423 with nothing queued, the holder gets 202', function () {
    [, , , , $draft] = pvWorld();
    config(['dashflow.tunables.timeouts.edit_lock_ttl.value' => '60', 'dashflow.edit_lock.flush_timeout_seconds.value' => '30']);
    $token = $this->postJson(BD_URL."/{$draft}/lock", [], BD_HEADERS)->assertOk()->json('data.token');
    $this->curl->queue = [FakeCurl::answer(200, PV_BODY)];

    pvStart($draft, ['lock_token' => str_repeat('a', 32)])->assertStatus(423);
    pvStart($draft)->assertStatus(423);
    expect(sfCount('operations'))->toBe(0);

    pvStart($draft, ['lock_token' => $token])->assertStatus(202);
    expect(sfCount('operations'))->toBe(1);
});

it('spends no fetch slot on a fail-fast run, and gives the slot back when queueing throws', function () {
    config(['dashflow.sample_fetch.membership_limit.value' => '1', 'dashflow.sample_fetch.window.value' => '60']);
    [, , $source, $endpoint, $draft] = pvWorld(dpPlan(['rev' => '$.data.revenue'], ['headline_value' => 'rev']));

    // Two fail-fast runs, no slot spent.
    pvStart($draft)->assertStatus(202);
    pvStart($draft)->assertStatus(202);

    // Fix the mapping; the single slot is still there. Queueing throws first: the slot is given back.
    pvConfigure($draft, dpPlan(['rev' => '$.data.revenue', 'name' => '$.data.label'], ['headline_value' => 'rev', 'kpi_label' => 'name']), $source, $endpoint, 2);
    // The route keeps its controller (and so its dependencies) between requests in one test: drop it so the swap is seen.
    $route = app('router')->getRoutes()->getByName('api.admin.blocks.drafts.validation.store');
    $real = app(Operations::class);
    $route->flushController();
    app()->instance(Operations::class, new Operations(app(WorkspaceTransaction::class), new OperationKinds, app(Outbox::class), app(RequestContext::class)));
    pvStart($draft)->assertStatus(500);
    app()->instance(Operations::class, $real);
    $route->flushController();
    $this->curl->queue = [FakeCurl::answer(200, PV_BODY)];

    pvStart($draft)->assertStatus(202);
    pvStart($draft)->assertStatus(429);
});

it('answers 409 for a published Draft and 404 for a discarded one, on GET and POST', function () {
    [, , , , $draft] = pvWorld();
    $other = bdCreate(['name' => 'Other'])->assertCreated()->json('data.draft_id');
    $this->deleteJson(BD_URL."/{$other}", [], BD_HEADERS)->assertOk();

    pvReport($other)->assertNotFound();
    pvStart($other)->assertNotFound();

    // A Draft that was frozen meanwhile (Story 3.19): the guard trigger is lifted for the test so the same row can be put back afterwards.
    $superuser = Cluster::superuser();
    $superuser->exec('ALTER TABLE block_versions DISABLE TRIGGER block_versions_guard');

    try {
        $superuser->prepare("UPDATE block_versions SET state = 'prepared', prepared_at = now() WHERE id = ?")->execute([$draft]);

        pvReport($draft)->assertStatus(409)->assertJsonPath('error.code', 'blocks.draft_published');
        pvStart($draft)->assertStatus(409)->assertJsonPath('error.code', 'blocks.draft_published');
    } finally {
        $superuser->prepare("UPDATE block_versions SET state = 'draft', prepared_at = NULL WHERE id = ?")->execute([$draft]);
        $superuser->exec('ALTER TABLE block_versions ENABLE TRIGGER block_versions_guard');
    }
});

it('keeps the pre-existing callers\' unbound refusal of a user-context Endpoint as fetch-failed', function () {
    [, , $source, $endpoint] = [null, null, null, null];
    [$workspace, , , $endpointId, $draft] = pvBoundWorld();
    $this->curl->queue = [];

    // The Endpoint's own sample route refuses it up front, with the Test endpoint's wording, and the backstop code stays fetch-failed.
    $operation = new Operation('00000000-0000-7000-8000-000000000001', $workspace, 'sample_fetch', (string) Str::uuid7(), 'endpoint', $endpointId, 1, OperationStatus::Queued, now()->addMinutes(5)->toIso8601String(), null, null);
    $dataSource = Cluster::rows(Cluster::superuser(), 'select data_source_id from endpoints where id = ?', [$endpointId])[0]['data_source_id'];
    $outcome = app(WorkspaceTransaction::class)->run($workspace, fn () => app(RunSampleFetch::class)->handle($operation, ['data_source_id' => $dataSource, 'values' => []]));

    expect($this->curl->calls)->toBe([])
        ->and($outcome->summary['code'])->toBe('fetch-failed')
        ->and($outcome->summary['reason'])->toBe('user_context_required');
});
