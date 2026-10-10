<?php

use App\Models\User;
use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftFieldRoles;
use App\Modules\Blocks\Contracts\DraftSamples;
use App\Modules\Blocks\Contracts\SampleCheck;
use App\Modules\Blocks\Contracts\SampleUnavailable;
use App\Modules\Blocks\Contracts\StoredSample;
use Tests\Database\Support\Cluster;

// Story 3.11a against the real PostgreSQL: GET /api/v1/admin/blocks/drafts/{draft}/sample/roles and the `record_path` / `field_roles` config keys.
// Helpers (bd*) come from DraftBlocksTest.
const DR_SAMPLE = '{"data":[{"id":1,"name":"A","revenue":10.50,"date":"2026-01-31"},{"id":2,"name":"B","revenue":3,"date":"2026-02-28"}],"meta":{"links":[{"h":"x"}]}}';

beforeEach(function () {
    $this->withoutVite();

    $this->keyFile = tempnam(sys_get_temp_dir(), 'dashflow-data-key');
    file_put_contents($this->keyFile, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    config([
        'dashflow.secrets.data_key_path.value' => $this->keyFile,
        'dashflow.tunables.guards.max_bytes.value' => '5000',
        'dashflow.tunables.guards.depth_limit.value' => '10',
        'dashflow.tunables.timeouts.draft_sample_ttl.value' => '600',
        'dashflow.tunables.timeouts.edit_lock_ttl.value' => null,
    ]);
});

afterEach(fn () => @unlink($this->keyFile));

function drDraft(bool $sample = true): string
{
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');

    if ($sample) {
        test()->putJson(BD_URL."/{$id}/sample", ['text' => DR_SAMPLE, 'contains_personal_data' => true, 'lock_epoch' => 1], BD_HEADERS)->assertOk();
    }

    return $id;
}

function drGet(string $id)
{
    return test()->getJson(BD_URL."/{$id}/sample/roles", BD_HEADERS);
}

/** A mapping write of the Draft (Story 3.13c), at its current revision unless one is given. */
function drWrite(string $id, string $what, array $body, ?int $revision = null)
{
    return test()->putJson(BD_URL."/{$id}/mapping/{$what}", $body + ['draft_revision' => $revision ?? (int) bdRows()[0]['revision'], 'lock_epoch' => 1], BD_HEADERS);
}

it('returns candidates, the chosen path and every field with its role, and is repeatable and read-only', function () {
    $id = drDraft();
    $before = bdRows();

    $first = drGet($id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
    $second = drGet($id)->assertOk()->json('data');

    expect($second)->toBe($first)
        ->and($first['draft_revision'])->toBe((int) bdRows()[0]['revision'])
        ->and($first['sample_revision'])->toBe(1)
        ->and($first['needs_choice'])->toBeTrue()
        ->and($first['record_path'])->toBe('$.data[*]')
        ->and(array_column($first['candidates'], 'pagination_like', 'path'))->toBe(['$.data[*]' => false, '$.meta.links[*]' => true])
        ->and(array_column($first['fields'], 'role', 'path')['$.data[*].revenue'])->toBe('measure')
        ->and(array_column($first['fields'], 'example', 'path')['$.data[*].revenue'])->toBe('10.50')
        ->and(bdRows())->toBe($before);
});

it('applies the record path and field role overrides written through the mapping endpoints, and keeps them over a save that omits them', function () {
    $id = drDraft();

    drWrite($id, 'record-path', ['record_path' => '$.meta.links[*]'])->assertOk();
    $written = drWrite($id, 'field-roles', ['path' => '$.data[*].revenue', 'role' => 'dimension'])->assertOk();
    bdSave($id, (int) $written->json('data.draft_revision'), 1, ['config' => ['description' => 'later']])->assertOk();

    $data = drGet($id)->assertOk()->json('data');
    $revenue = array_column($data['fields'], null, 'path')['$.data[*].revenue'];

    expect($data['record_path'])->toBe('$.meta.links[*]')
        ->and($data['draft_revision'])->toBe((int) bdRows()[0]['revision'])
        ->and($revenue)->toMatchArray(['role' => 'dimension', 'overridden' => true, 'confidence' => 'high', 'suggestion' => null]);

    // null clears the record path; the suggested one is used again.
    drWrite($id, 'record-path', ['record_path' => null])->assertOk();
    expect(drGet($id)->json('data.record_path'))->toBe('$.data[*]');
});

it('refuses a bad role or a malformed path from the mapping endpoints, and a generic save of a mapping key', function () {
    $id = drDraft();
    $before = bdRows();

    drWrite($id, 'field-roles', ['path' => '$.data[*].revenue', 'role' => 'bogus'])->assertStatus(422)->assertJsonPath('error.code', 'blocks.invalid_config')->assertJsonStructure(['errors' => ['role']]);
    drWrite($id, 'field-roles', ['path' => 'a..b', 'role' => 'measure'])->assertStatus(422)->assertJsonStructure(['errors' => ['path']]);
    drWrite($id, 'field-roles', ['path' => '$.data[*].nope', 'role' => 'measure'])->assertStatus(422)->assertJsonStructure(['errors' => ['path']]);
    drWrite($id, 'record-path', ['record_path' => 'a..b'])->assertStatus(422)->assertJsonStructure(['errors' => ['record_path']]);
    drWrite($id, 'record-path', ['record_path' => '$.data'])->assertStatus(422)->assertJsonStructure(['errors' => ['record_path']]);
    drWrite($id, 'record-path', ['record_path' => '$.nowhere[*]'])->assertStatus(422)->assertJsonStructure(['errors' => ['record_path']]);
    expect(bdRows())->toBe($before);

    foreach (['record_path' => '$.meta.links[*]', 'field_roles' => ['$.data[*].revenue' => 'dimension'], 'field_types' => ['$.data[*].revenue' => 'text'], 'slot_map' => null] as $key => $value) {
        $res = bdSave($id, 1, 1, ['config' => [$key => $value]])->assertStatus(422)->assertJsonPath('error.code', 'blocks.invalid_config');
        expect(array_keys($res->json('errors')))->toBe(["config.{$key}"])->and($res->json('errors')["config.{$key}"][0])->toContain('mapping endpoints');
    }

    expect(bdRows())->toBe($before);
});

it('is 422 mapping.no_sample without a sample and 404 for a missing draft', function () {
    $id = drDraft(false);

    drGet($id)->assertStatus(422)->assertJsonPath('error.code', 'mapping.no_sample');
    drGet('018f0000-0000-7000-8000-000000000000')->assertNotFound();
});

it('lets a read-only viewer ask while another member holds the lock, but not a member without blocks.edit', function () {
    $id = drDraft();
    $workspace = bdRows('workspace_memberships')[0]['workspace_id'];
    bdMember($workspace, 'viewer@example.test', 'admin', []);
    [$user] = bdMember($workspace, 'bob@example.test');
    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($user))->withSession(['workspace_id' => $workspace, 'area' => 'admin']);

    drGet($id)->assertOk();

    [$none] = bdMember($workspace, 'none@example.test', 'admin', []);
    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($none))->withSession(['workspace_id' => $workspace, 'area' => 'admin']);

    drGet($id)->assertForbidden();
});

it('clears a role override with null, refuses non-canonical keys, and keeps both over an unrelated save', function () {
    $id = drDraft();
    $roles = fn () => collect(drGet($id)->json('data.fields'))->where('overridden', true)->count();

    drWrite($id, 'field-roles', ['path' => '$.data[*].revenue', 'role' => 'dimension'])->assertOk();
    drWrite($id, 'record-path', ['record_path' => '$.meta.links[*]'])->assertOk();
    bdSave($id, (int) bdRows()[0]['revision'], 1, ['config' => ['description' => 'x']])->assertOk();
    expect($roles())->toBe(1)->and(drGet($id)->json('data.record_path'))->toBe('$.meta.links[*]');

    drWrite($id, 'field-roles', ['path' => '$.data[*].revenue', 'role' => null])->assertOk();
    expect($roles())->toBe(0)->and(bdRows()[0]['config'])->not->toContain('field_roles');

    foreach (["\$['data'][*].revenue", '$'] as $bad) {
        drWrite($id, 'field-roles', ['path' => $bad, 'role' => 'measure'])->assertStatus(422)->assertJsonStructure(['errors' => ['path']]);
    }
});

it('refuses a stale draft_revision on a mapping write with a 409 and the current draft, writing nothing', function () {
    $id = drDraft();
    drWrite($id, 'field-roles', ['path' => '$.data[*].revenue', 'role' => 'dimension'])->assertOk();
    $before = bdRows();

    $res = drWrite($id, 'field-roles', ['path' => '$.data[*].revenue', 'role' => 'measure'], 1)->assertStatus(409)->assertJsonPath('error.code', 'blocks.revision_conflict');
    expect($res->json('current.data.revision'))->toBe((int) $before[0]['revision'])->and(bdRows())->toBe($before);

    test()->putJson(BD_URL."/{$id}/mapping/field-roles", ['path' => '$.data[*].revenue', 'role' => null, 'lock_epoch' => 1], BD_HEADERS)->assertStatus(422)->assertJsonStructure(['errors' => ['draft_revision']]);
});

it('is 422 mapping.no_sample when the stored sample no longer passes the limits', function () {
    $id = drDraft();
    config(['dashflow.tunables.guards.max_bytes.value' => '10']);

    drGet($id)->assertStatus(422)->assertJsonPath('error.code', 'mapping.no_sample');
});

it('is 503 blocks.sample_unavailable without sample text when the sample cannot be opened', function () {
    $id = drDraft();
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
    app()->forgetInstance(DraftFieldRoles::class);

    $response = drGet($id)->assertStatus(503)->assertJsonPath('error.code', 'blocks.sample_unavailable');

    expect($response->getContent())->not->toContain('10.50');
});
