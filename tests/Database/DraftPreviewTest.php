<?php

use App\Models\User;
use App\Modules\Blocks\Application\PreviewDraft;
use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftSamples;
use App\Modules\Blocks\Contracts\SampleCheck;
use App\Modules\Blocks\Contracts\SampleUnavailable;
use App\Modules\Blocks\Contracts\StoredSample;
use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Contracts\BlockTypeRegistry;
use App\Modules\BlockTypes\Contracts\UnknownBlockType;
use App\Support\Observability\MetricEmitter;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;
use Tests\Database\Support\Cluster;

// Story 3.10 against the real PostgreSQL: POST /api/v1/admin/blocks/drafts/{draft}/preview runs the Draft's Query Plan over its stored sample with
// the same engine production runs. Every `pending_input` setting is configured here by the test; none has a default. Helpers (bd*) come from DraftBlocksTest.
const DP_CANARY = 'CANARY-preview-7c1e';

const DP_SAMPLE = <<<'JSON'
{"data":{"revenue":12345678901234567890.10,"label":"Net DP_CANARY","delta":-2.50,"rows":[{"v":1},{"v":2}],"nested":{"a":1}}}
JSON;

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
    PreviewDraft::forget();

    $this->keyFile = tempnam(sys_get_temp_dir(), 'dashflow-data-key');
    file_put_contents($this->keyFile, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    config([
        'dashflow.secrets.data_key_path.value' => $this->keyFile,
        'dashflow.tunables.guards.max_bytes.value' => '5000',
        'dashflow.tunables.guards.depth_limit.value' => '10',
        'dashflow.tunables.timeouts.draft_sample_ttl.value' => '600',
        'dashflow.tunables.timeouts.edit_lock_ttl.value' => null,
        'dashflow.preview.membership_limit.value' => '100',
        'dashflow.preview.window_seconds.value' => '60',
        'dashflow.preview.max_extracted_values.value' => '1000',
        'dashflow.preview.max_evaluations.value' => '1000',
        'dashflow.preview.max_wall_ms.value' => '5000',
    ]);

    $this->logFile = tempnam(sys_get_temp_dir(), 'dashflow-log');
    config(['logging.default' => 'single', 'logging.channels.single.path' => $this->logFile]);
});

afterEach(function () {
    @unlink($this->keyFile);
    @unlink($this->logFile);
    PreviewDraft::forget();
});

function dpPlan(array $fields = ['rev' => '$.data.revenue', 'name' => '$.data.label', 'cmp' => '$.data.delta'], array $shape = ['headline_value' => 'rev', 'kpi_label' => 'name', 'comparison' => 'cmp']): array
{
    return ['v' => 1, 'extract' => ['fields' => $fields], 'shape' => $shape];
}

function dpUrl(string $id): string
{
    return BD_URL."/{$id}/preview";
}

function dpPreview(string $id)
{
    return test()->postJson(dpUrl($id), [], BD_HEADERS);
}

function dpPaste(string $id, string $text = DP_SAMPLE)
{
    return test()->putJson(BD_URL."/{$id}/sample", ['text' => $text, 'contains_personal_data' => true, 'lock_epoch' => 1], BD_HEADERS)->assertOk();
}

/** A signed-in Admin with a Draft that has a sample (and a plan unless `$plan` is null); returns [workspace, draft ID]. */
function dpDraft(?array $plan = null, bool $sample = true): array
{
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');

    if ($sample) {
        dpPaste($id);
    }

    if ($plan !== null) {
        bdSave($id, 1, 1, ['config' => ['query_plan' => $plan]])->assertOk();
    }

    return [$workspace, $id];
}

it('previews the plan over the stored sample: the Shaper payload, exact numbers and nothing unavailable', function () {
    [, $id] = dpDraft(dpPlan());

    $body = dpPreview($id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');

    expect($body['payload']['headline']['value'])->toBe('12345678901234567890.10')
        ->and($body['payload']['kpiLabel'])->toBe('Net DP_CANARY')
        ->and($body['payload']['comparison']['value'])->toBe('-2.50')
        ->and($body['payload']['state'])->toBe('normal')
        ->and(array_keys($body['unavailable']))->not->toContain('headline_value', 'kpi_label', 'comparison')
        ->and($body['unavailable']['title'])->toBe('missing')
        ->and($body['sample_revision'])->toBe(1);

    // It wrote nothing: the Draft's revision is unchanged.
    expect(bdRows()[0])->toMatchArray(['revision' => 2]);
});

it('returns the period the platform resolver gives the Block default, in half-open Workspace dates, and null while none is chosen', function () {
    [, $id] = dpDraft(dpPlan());

    expect(dpPreview($id)->assertOk()->json('data.period'))->toBeNull();

    bdSave($id, 2, 1, ['config' => ['source' => ['default_date_range' => ['preset' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31']]]])->assertOk();

    expect(dpPreview($id)->assertOk()->json('data.period'))->toBe(['token' => 'custom', 'start' => '2026-01-01', 'end' => '2026-02-01']);
});

it('returns the same payload twice and parses the sample once in this worker', function () {
    [, $id] = dpDraft(dpPlan());
    $opened = 0;
    $real = app(DraftSamples::class);
    app()->instance(DraftSamples::class, new class($real, $opened) implements DraftSamples
    {
        public function __construct(private DraftSamples $inner, public int &$finds) {}

        public function check(string $text): SampleCheck
        {
            return $this->inner->check($text);
        }

        public function store(DraftActor $actor, string $draftId, string $text, bool $containsPersonalData, int $lockEpoch, ?Closure $holdsLock = null): StoredSample
        {
            return $this->inner->store($actor, $draftId, $text, $containsPersonalData, $lockEpoch, $holdsLock);
        }

        public function storeFetched(DraftActor $actor, string $draftId, string $text, int $lockEpoch, int $status, int $latencyMs, string $requestKey): StoredSample
        {
            return $this->inner->storeFetched($actor, $draftId, $text, $lockEpoch, $status, $latencyMs, $requestKey);
        }

        public function find(string $workspaceId, string $draftId): ?StoredSample
        {
            $this->finds++;

            return $this->inner->find($workspaceId, $draftId);
        }

        public function revision(string $workspaceId, string $draftId): ?int
        {
            return $this->inner->revision($workspaceId, $draftId);
        }
    });
    app()->forgetInstance(PreviewDraft::class);

    $first = dpPreview($id)->assertOk()->json('data');
    $second = dpPreview($id)->assertOk()->json('data');

    expect($second)->toBe($first)->and($opened)->toBe(1);

    // A new paste is a new revision: the cached tree is not used.
    dpPaste($id, '{"data":{"revenue":7.0,"label":"Changed","delta":1}}');
    $third = dpPreview($id)->assertOk()->json('data');

    expect($opened)->toBe(2)->and($third['sample_revision'])->toBe(2)->and($third['payload']['headline']['value'])->toBe('7.0');
});

it('does not put the sample in the shared cache', function () {
    [, $id] = dpDraft(dpPlan());
    dpPreview($id)->assertOk();

    $dump = json_encode(Cache::getStore() instanceof ArrayStore ? (new ReflectionProperty(Cache::getStore(), 'storage'))->getValue(Cache::getStore()) : []);

    expect($dump)->not->toContain(DP_CANARY);
});

it('marks a missing slot, a wrong type and several values unavailable, and still renders the others', function () {
    [, $id] = dpDraft(dpPlan(
        ['gone' => '$.data.absent', 'rows' => '$.data.rows[*].v', 'obj' => '$.data.nested', 'name' => '$.data.label'],
        ['headline_value' => 'gone', 'comparison' => 'rows', 'title' => 'obj', 'kpi_label' => 'name'],
    ));

    $body = dpPreview($id)->assertOk()->json('data');

    expect($body['unavailable'])->toEqualCanonicalizing([
        'headline_value' => 'missing', 'comparison' => 'not_single_value', 'title' => 'wrong_type',
        'subtitle' => 'missing', 'icon' => 'missing', 'footer_action' => 'missing', 'comparison_label' => 'missing', 'secondary_note' => 'missing', 'status_badge' => 'missing',
    ])
        ->and($body['payload']['headline']['available'])->toBeFalse()
        ->and($body['payload']['comparison'])->toBeNull()
        ->and($body['payload']['title'])->toBeNull()
        ->and($body['payload']['kpiLabel'])->toBe('Net DP_CANARY');
});

it('refuses with mapping.no_sample and mapping.no_plan and runs nothing', function () {
    [, $noSample] = dpDraft(dpPlan(), sample: false);
    dpPreview($noSample)->assertStatus(422)->assertJsonPath('error.code', 'mapping.no_sample');

    // A new Block with a sample and no plan.
    $noPlan = bdCreate(['name' => 'Second'])->json('data.draft_id');
    dpPaste($noPlan);
    dpPreview($noPlan)->assertStatus(422)->assertJsonPath('error.code', 'mapping.no_plan');
});

it('refuses a plan of version 2 when the config is saved, naming config.query_plan.v', function () {
    [, $id] = dpDraft();

    bdSave($id, 1, 1, ['config' => ['query_plan' => ['v' => 2] + dpPlan()]])->assertStatus(422)
        ->assertJsonPath('error.code', 'blocks.invalid_config')->assertJsonStructure(['errors' => ['config.query_plan.v']]);
    bdSave($id, 1, 1, ['config' => ['query_plan' => dpPlan(['a' => '$..x'], [])]])->assertStatus(422)->assertJsonStructure(['errors' => ['config.query_plan.extract.fields.a']]);
    expect(json_encode(bdRows()[0]['config'] ?? ''))->not->toContain('query_plan');
});

it('aborts with mapping.guard_exceeded and no payload when a guard is passed, and still emits the metric', function () {
    [, $id] = dpDraft(dpPlan(['rows' => '$.data.rows[*].v', 'name' => '$.data.label'], ['kpi_label' => 'name']));
    config(['dashflow.preview.max_extracted_values.value' => '2']);

    $seen = [];
    app()->instance(MetricEmitter::class, new class($seen) implements MetricEmitter
    {
        public function __construct(public array &$seen) {}

        public function increment(string $name, array $labels = [], int $by = 1): void {}

        public function record(string $name, float|int $value, array $labels = []): void
        {
            $this->seen[] = [$name, $labels['outcome'] ?? null, $value >= 0];
        }
    });
    app()->forgetInstance(PreviewDraft::class);

    $response = dpPreview($id)->assertStatus(422)->assertJsonPath('error.code', 'mapping.guard_exceeded')->assertJsonPath('guard', 'max_extracted_values');

    expect($response->json())->not->toHaveKey('data')
        ->and($seen)->toBe([['dashflow.mapping.preview_duration', 'guard_exceeded', true]])
        ->and($response->getContent())->not->toContain(DP_CANARY);
});

it('emits the preview duration histogram on a normal run', function () {
    [, $id] = dpDraft(dpPlan());
    $seen = [];
    app()->instance(MetricEmitter::class, new class($seen) implements MetricEmitter
    {
        public function __construct(public array &$seen) {}

        public function increment(string $name, array $labels = [], int $by = 1): void {}

        public function record(string $name, float|int $value, array $labels = []): void
        {
            $this->seen[] = [$name, $labels['outcome'] ?? null];
        }
    });
    app()->forgetInstance(PreviewDraft::class);

    dpPreview($id)->assertOk();

    expect($seen)->toBe([['dashflow.mapping.preview_duration', 'ok']]);
});

it('answers 429 with retry_after over the per-membership limit', function () {
    [, $id] = dpDraft(dpPlan());
    config(['dashflow.preview.membership_limit.value' => '2']);

    dpPreview($id)->assertOk();
    dpPreview($id)->assertOk();
    $over = dpPreview($id)->assertStatus(429)->assertHeader('Retry-After');

    expect($over->json('error.retry_after'))->toBeInt()->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(60)
        ->and($over->getContent())->not->toContain(DP_CANARY);
});

it('refuses naming the variable while any preview setting is unset, and counts nothing', function () {
    [, $id] = dpDraft(dpPlan());

    foreach ([
        'membership_limit' => 'DASHFLOW_PREVIEW_MEMBERSHIP_LIMIT',
        'window_seconds' => 'DASHFLOW_PREVIEW_WINDOW_SECONDS',
        'max_extracted_values' => 'DASHFLOW_PREVIEW_MAX_EXTRACTED_VALUES',
        'max_evaluations' => 'DASHFLOW_PREVIEW_MAX_EVALUATIONS',
        'max_wall_ms' => 'DASHFLOW_PREVIEW_MAX_WALL_MS',
    ] as $setting => $env) {
        $was = config("dashflow.preview.{$setting}.value");
        config(["dashflow.preview.{$setting}.value" => null]);

        $response = dpPreview($id)->assertStatus(422)->assertJsonPath('error.code', 'mapping.setting_not_configured')->assertJsonPath('setting', $env);
        expect($response->json('error.message'))->toContain($env);

        config(["dashflow.preview.{$setting}.value" => $was]);
    }

    dpPreview($id)->assertOk();
});

it('lets a read-only viewer preview while another Admin holds the lock, and writes nothing', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');
    dpPaste($id);
    bdSave($id, 1, 1, ['config' => ['query_plan' => dpPlan()]])->assertOk();
    config(['dashflow.tunables.timeouts.edit_lock_ttl.value' => '60', 'dashflow.edit_lock.flush_timeout_seconds.value' => '30']);
    test()->postJson(BD_URL."/{$id}/lock", [], BD_HEADERS)->assertOk()->assertJsonPath('data.status', 'granted');

    [$bob] = bdMember($workspace, 'bob@example.test');
    test()->flushSession();
    auth()->forgetGuards();
    test()->actingAs(User::query()->findOrFail($bob))->withSession(['workspace_id' => $workspace, 'area' => 'admin']);
    test()->postJson(BD_URL."/{$id}/lock", [], BD_HEADERS)->assertOk()->assertJsonPath('data.status', 'held');

    $before = bdRows();
    dpPreview($id)->assertOk()->assertJsonPath('data.payload.state', 'normal');

    expect(bdRows())->toBe($before);
});

it('is a 404 for a Draft that is gone or discarded; a User-area session and a member without blocks.edit are denied', function () {
    [$workspace, $id] = dpDraft(dpPlan());

    dpPreview('018f0000-0000-7000-8000-0000000000ff')->assertNotFound();

    // A published version answers 409 `blocks.draft_published` (PreviewDraft::isPublished); `block_versions.state` allows only draft and discarded until Story 3.19.
    test()->deleteJson(BD_URL."/{$id}", ['lock_epoch' => 1], BD_HEADERS)->assertSuccessful();
    dpPreview($id)->assertNotFound();

    test()->flushSession();
    [$noPermission] = bdMember($workspace, 'carol@example.test', 'admin', []);
    test()->actingAs(User::query()->findOrFail($noPermission))->withSession(['workspace_id' => $workspace, 'area' => 'admin']);
    dpPreview($id)->assertForbidden();

    test()->flushSession();
    test()->withSession(['workspace_id' => $workspace, 'area' => 'user']);
    dpPreview($id)->assertForbidden();
});

it('never leaks the sample in a log line', function () {
    [, $id] = dpDraft(dpPlan());
    config(['dashflow.preview.max_wall_ms.value' => null]);

    dpPreview($id)->assertStatus(422);
    config(['dashflow.preview.max_wall_ms.value' => '5000']);
    dpPreview($id)->assertOk();

    expect(file_get_contents($this->logFile))->not->toContain(DP_CANARY);
});

it('counts nothing for a refused request: with a limit of one, refusals before the run leave the one preview free', function () {
    [, $valid] = dpDraft(dpPlan());
    $noSample = bdCreate(['name' => 'No sample'])->json('data.draft_id');
    bdSave($noSample, 1, 1, ['config' => ['query_plan' => dpPlan()]])->assertOk();
    $noPlan = bdCreate(['name' => 'No plan'])->json('data.draft_id');
    dpPaste($noPlan);
    config(['dashflow.preview.membership_limit.value' => '1']);

    dpPreview($noSample)->assertStatus(422)->assertJsonPath('error.code', 'mapping.no_sample');
    dpPreview($noPlan)->assertStatus(422)->assertJsonPath('error.code', 'mapping.no_plan');
    config(['dashflow.preview.max_wall_ms.value' => null]);
    dpPreview($valid)->assertStatus(422)->assertJsonPath('error.code', 'mapping.setting_not_configured');
    config(['dashflow.preview.max_wall_ms.value' => '5000']);
    dpPreview($noSample)->assertStatus(422);

    dpPreview($valid)->assertOk();
    dpPreview($valid)->assertStatus(429);
});

it('answers 503 with no sample text when the sample cannot be opened', function () {
    [, $id] = dpDraft(dpPlan());
    $real = app(DraftSamples::class);
    app()->instance(DraftSamples::class, new class($real) implements DraftSamples
    {
        public function __construct(private DraftSamples $inner) {}

        public function check(string $text): SampleCheck
        {
            return $this->inner->check($text);
        }

        public function store(DraftActor $actor, string $draftId, string $text, bool $containsPersonalData, int $lockEpoch, ?Closure $holdsLock = null): StoredSample
        {
            return $this->inner->store($actor, $draftId, $text, $containsPersonalData, $lockEpoch, $holdsLock);
        }

        public function storeFetched(DraftActor $actor, string $draftId, string $text, int $lockEpoch, int $status, int $latencyMs, string $requestKey): StoredSample
        {
            return $this->inner->storeFetched($actor, $draftId, $text, $lockEpoch, $status, $latencyMs, $requestKey);
        }

        public function find(string $workspaceId, string $draftId): ?StoredSample
        {
            throw new SampleUnavailable;
        }

        public function revision(string $workspaceId, string $draftId): ?int
        {
            return $this->inner->revision($workspaceId, $draftId);
        }
    });
    app()->forgetInstance(PreviewDraft::class);

    $response = dpPreview($id)->assertStatus(503)->assertJsonPath('error.code', 'blocks.sample_unavailable');

    expect($response->getContent())->not->toContain(DP_CANARY);
});

it('refuses a stored plan of version 2 under config.query_plan.v', function () {
    [, $id] = dpDraft(dpPlan());
    $stored = json_encode(['schema_version' => 1, 'query_plan' => ['v' => 2] + dpPlan()]);
    Cluster::superuser()->prepare('update block_versions set config = ?::jsonb')->execute([$stored]);

    dpPreview($id)->assertStatus(422)->assertJsonPath('error.code', 'blocks.invalid_config')->assertJsonStructure(['errors' => ['config.query_plan.v']]);
});

it('answers 422 mapping.block_type_unavailable when the Block Type has no registered package', function () {
    [, $id] = dpDraft(dpPlan());
    app()->instance(BlockTypeRegistry::class, new class implements BlockTypeRegistry
    {
        public function all(): array
        {
            return [];
        }

        public function resolve(string $key, int $contractVersion): BlockType
        {
            throw UnknownBlockType::pair($key, $contractVersion);
        }
    });
    app()->forgetInstance(PreviewDraft::class);

    dpPreview($id)->assertStatus(422)->assertJsonPath('error.code', 'mapping.block_type_unavailable');
});
