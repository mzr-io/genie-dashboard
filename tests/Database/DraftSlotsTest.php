<?php

use App\Models\User;
use App\Modules\Blocks\Application\PreviewDraft;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use Tests\Database\Support\Cluster;

// Story 3.12a against the real PostgreSQL: GET /mapping (checklist), POST /mapping/auto-map, POST .../slots/{slot}/confirm, PUT .../slots/{slot} (override),
// and the `slot_map` config key. Helpers (bd*) come from DraftBlocksTest.
const DSL_CANARY = 'CANARY-slots-5d2a';

const DSL_SAMPLE = '{"total":284680.50,"previous_total":250000,"month":"2026-09","revenue":"284,680 USD","region":"EU","status":"CANARY-slots-5d2a"}';

beforeEach(function () {
    $this->withoutVite();
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
});

afterEach(function () {
    @unlink($this->keyFile);
    PreviewDraft::forget();
});

function dslDraft(?string $sample = DSL_SAMPLE): string
{
    bdAdmin(Cluster::workspace('Acme'));
    $id = bdCreate()->assertCreated()->json('data.draft_id');

    if ($sample !== null) {
        dslPaste($id, $sample);
    }

    return $id;
}

function dslPaste(string $id, string $text)
{
    return test()->putJson(BD_URL."/{$id}/sample", ['text' => $text, 'contains_personal_data' => true, 'lock_epoch' => 1], BD_HEADERS)->assertOk();
}

function dslGet(string $id)
{
    return test()->getJson(BD_URL."/{$id}/mapping", BD_HEADERS);
}

function dslRerun(string $id, array $extra = [])
{
    return test()->postJson(BD_URL."/{$id}/mapping/auto-map", $extra + ['lock_epoch' => 1], BD_HEADERS);
}

function dslConfirm(string $id, string $slot, array $extra = [])
{
    return test()->postJson(BD_URL."/{$id}/mapping/slots/{$slot}/confirm", $extra + ['lock_epoch' => 1], BD_HEADERS);
}

function dslOverride(string $id, string $slot, ?string $path, array $extra = [])
{
    return test()->putJson(BD_URL."/{$id}/mapping/slots/{$slot}", $extra + ['path' => $path, 'lock_epoch' => 1], BD_HEADERS);
}

/** The checklist row of a slot. */
function dslRow(array $data, string $slot): array
{
    foreach ($data['groups'] as $group) {
        foreach ($group['slots'] as $row) {
            if ($row['slot'] === $slot) {
                return $row;
            }
        }
    }

    throw new RuntimeException("no row {$slot}");
}

function dslConfig(): array
{
    return json_decode(bdRows()[0]['config'], true);
}

it('reads the checklist, read-only and repeatable, with groups, counts, blockers and the baseline', function () {
    $id = dslDraft();
    $before = bdRows();

    $first = dslGet($id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
    $second = dslGet($id)->assertOk()->json('data');

    expect($second)->toBe($first)
        ->and(array_column($first['groups'], 'group'))->toBe(['HEADER', 'KPI', 'META'])
        ->and(dslRow($first, 'headline_value'))->toMatchArray(['state' => 'auto_medium', 'confidence' => 'medium', 'path' => '$.total', 'reason' => '2 values; picked the one not named previous_*.'])
        ->and($first['baseline'])->toBe([['path' => '$.previous_total', 'display' => 'previous_total']])
        ->and($first['counts']['total'])->toBe(8)
        ->and(array_column($first['blockers'], 'reason', 'slot'))->toBe(['kpi_label' => 'required_missing'])
        ->and(dslRow($first, 'kpi_label')['suggestion'])->toBe(['path' => '$.month', 'display' => 'month'])
        ->and(bdRows())->toBe($before);
});

it('re-runs: writes the query plan from every mapped slot, the preview endpoint returns the headline, previous_total is not mapped', function () {
    $id = dslDraft();

    $data = dslRerun($id)->assertOk()->json('data');

    expect($data['query_plan']['extract']['fields'])->toMatchArray(['headline_value' => '$.total', 'status_badge' => '$.status'])
        ->and(array_values($data['query_plan']['extract']['fields']))->not->toContain('$.previous_total')
        ->and(dslConfig()['query_plan'])->toEqual($data['query_plan'])
        ->and($data['draft_revision'])->toBe(2);

    $preview = test()->postJson(BD_URL."/{$id}/preview", [], BD_HEADERS)->assertOk()->json('data');
    expect($preview['payload']['headline']['value'])->toBe('284680.50');

    // A re-run replaces the previous plan.
    dslConfirm($id, 'headline_value')->assertOk();
    dslOverride($id, 'headline_value', '$.previous_total')->assertOk();
    expect(dslRerun($id)->json('data.query_plan.extract.fields.headline_value'))->toBe('$.previous_total');
});

it('confirms a slot, stores the decision, and 404s an unknown slot; a slot with nothing mapped is a 422', function () {
    $id = dslDraft();

    $data = dslConfirm($id, 'headline_value')->assertOk()->json('data');

    expect(dslRow($data, 'headline_value'))->toMatchArray(['state' => 'confirmed', 'path' => '$.total'])
        ->and(dslConfig()['slot_map'])->toBe(['headline_value' => ['path' => '$.total', 'state' => 'confirmed']]);

    dslConfirm($id, 'nope')->assertNotFound();
    dslConfirm($id, 'icon')->assertNotFound();

    $bad = dslConfirm($id, 'kpi_label')->assertStatus(422)->assertJsonPath('error.code', PlatformErrorCode::ValidationFailed->value);
    expect(array_keys($bad->json('errors')))->toBe(['slot.kpi_label']);
});

it('overrides with a valid scalar, clears with null, and refuses an unfit field naming the slot', function () {
    $id = dslDraft();

    $data = dslOverride($id, 'kpi_label', '$.region')->assertOk()->json('data');
    expect(dslRow($data, 'kpi_label'))->toMatchArray(['state' => 'overridden', 'path' => '$.region'])
        ->and(dslConfig()['slot_map']['kpi_label'])->toBe(['path' => '$.region', 'state' => 'overridden']);

    $cleared = dslOverride($id, 'kpi_label', null)->assertOk()->json('data');
    expect(dslRow($cleared, 'kpi_label')['state'])->toBe('required_missing')
        ->and(dslConfig()['slot_map']['kpi_label'])->toBe(['path' => null, 'state' => 'overridden']);

    $revision = bdRows()[0]['revision'];

    foreach ([['kpi_label', '$.total'], ['headline_value', '$.region'], ['headline_value', '$.missing'], ['headline_value', 'a..b']] as [$slot, $path]) {
        $res = dslOverride($id, $slot, $path)->assertStatus(422);
        expect(array_keys($res->json('errors')))->toBe(["slot.{$slot}"]);
    }

    dslOverride($id, 'bogus', '$.region')->assertNotFound();
    expect(bdRows()[0]['revision'])->toBe($revision);
});

it('refuses an override of a field inside the rows', function () {
    $id = dslDraft('{"total":1,"data":[{"v":1},{"v":2}]}');

    dslOverride($id, 'headline_value', '$.data[*].v')->assertStatus(422)->assertJsonStructure(['errors' => ['slot.headline_value']]);
    expect(dslRow(dslGet($id)->json('data'), 'headline_value')['state'])->toBe('auto_high');
});

it('is type_mismatch when the numbers are only in the rows', function () {
    $id = dslDraft('{"data":[{"revenue":1},{"revenue":2}]}');

    $data = dslGet($id)->assertOk()->json('data');
    expect(dslRow($data, 'headline_value')['state'])->toBe('type_mismatch')
        ->and(array_column($data['blockers'], 'reason', 'slot')['headline_value'])->toBe('type_mismatch');
});

it('leaves a confirmed and an overridden slot untouched on re-run, and a stale confirmed slot reads type_mismatch after a new sample', function () {
    $id = dslDraft();
    dslConfirm($id, 'headline_value')->assertOk();
    dslOverride($id, 'kpi_label', '$.region')->assertOk();
    $map = dslConfig()['slot_map'];

    $data = dslRerun($id)->assertOk()->json('data');

    expect(dslConfig()['slot_map'])->toBe($map)
        ->and(dslRow($data, 'headline_value')['state'])->toBe('confirmed')
        ->and(dslRow($data, 'kpi_label')['state'])->toBe('overridden');

    dslPaste($id, '{"revenue":5,"region":"EU"}');
    $stale = dslGet($id)->assertOk()->json('data');
    expect(dslRow($stale, 'headline_value'))->toMatchArray(['state' => 'type_mismatch', 'path' => '$.total'])
        ->and(dslRow($stale, 'kpi_label')['state'])->toBe('overridden')
        ->and(dslConfig()['slot_map'])->toBe($map);
});

it('keeps slot_map over a save that omits it, clears it with null, and refuses a bad entry with the config key', function () {
    $id = dslDraft();
    dslConfirm($id, 'headline_value')->assertOk();
    $map = dslConfig()['slot_map'];

    bdSave($id, 2, 1, ['config' => ['description' => 'later']])->assertOk();
    expect(dslConfig()['slot_map'])->toBe($map);

    $bad = bdSave($id, 3, 1, ['config' => ['slot_map' => ['headline_value' => ['path' => 'a..b', 'state' => 'confirmed'], 'bogus' => ['path' => null, 'state' => 'overridden'], 'kpi_label' => ['path' => '$.x', 'state' => 'auto_high']]]])
        ->assertStatus(422)->assertJsonPath('error.code', 'blocks.invalid_config');
    expect(array_keys($bad->json('errors')))->toBe(['config.slot_map.headline_value', 'config.slot_map.bogus', 'config.slot_map.kpi_label']);

    bdSave($id, 3, 1, ['config' => ['slot_map' => null]])->assertOk();
    expect(dslConfig()['slot_map'])->toBe([]);
});

it('is 422 mapping.no_sample without a sample and 404 for a missing draft', function () {
    $id = dslDraft(null);

    dslGet($id)->assertStatus(422)->assertJsonPath('error.code', 'mapping.no_sample');
    dslRerun($id)->assertStatus(422)->assertJsonPath('error.code', 'mapping.no_sample');
    dslGet('018f0000-0000-7000-8000-000000000000')->assertNotFound();
    dslRerun('018f0000-0000-7000-8000-000000000000')->assertNotFound();
});

it('writes only with the edit-lock claim: a stale epoch or a token that does not hold the lock is a 423 and writes nothing, while a read is allowed', function () {
    $id = dslDraft();
    config(['dashflow.tunables.timeouts.edit_lock_ttl.value' => '60']);
    $lock = test()->postJson(BD_URL."/{$id}/lock", [], BD_HEADERS)->assertOk()->json('data');
    $before = bdRows();

    dslRerun($id)->assertStatus(423)->assertJsonPath('error.code', PlatformErrorCode::EditLockLost->value);
    dslConfirm($id, 'headline_value', ['lock_token' => str_repeat('a', 32)])->assertStatus(423);
    dslOverride($id, 'kpi_label', '$.region', ['lock_token' => $lock['token'], 'lock_epoch' => 7])->assertStatus(423);
    expect(bdRows())->toBe($before);
    dslGet($id)->assertOk();

    dslConfirm($id, 'headline_value', ['lock_token' => $lock['token']])->assertOk();
});

it('needs blocks.edit, and lets a member without the lock read but not write', function () {
    $id = dslDraft();
    $workspace = bdRows('workspace_memberships')[0]['workspace_id'];
    [$none] = bdMember($workspace, 'none@example.test', 'admin', []);
    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($none))->withSession(['workspace_id' => $workspace, 'area' => 'admin']);

    dslGet($id)->assertForbidden();
    dslRerun($id)->assertForbidden();
});

it('lets a blocks.edit member who does not hold the lock read the checklist (200) while a confirm is a 423', function () {
    $id = dslDraft();
    config(['dashflow.tunables.timeouts.edit_lock_ttl.value' => '60']);
    test()->postJson(BD_URL."/{$id}/lock", [], BD_HEADERS)->assertOk();
    $workspace = bdRows('workspace_memberships')[0]['workspace_id'];
    [$bob] = bdMember($workspace, 'bob@example.test');
    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($bob))->withSession(['workspace_id' => $workspace, 'area' => 'admin']);
    $before = bdRows();

    dslGet($id)->assertOk();
    dslConfirm($id, 'headline_value')->assertStatus(423)->assertJsonPath('error.code', PlatformErrorCode::EditLockLost->value);
    expect(bdRows())->toBe($before);
});

it('puts nothing of the sample in a refusal', function () {
    $id = dslDraft();

    $response = dslOverride($id, 'headline_value', '$.status')->assertStatus(422);

    expect($response->getContent())->not->toContain(DSL_CANARY);
});

it('is a 409 blocks.draft_published for a version past Draft, writing nothing, and a 404 for a discarded draft', function () {
    $id = dslDraft();
    $gone = bdCreate(['name' => 'Other'])->assertCreated()->json('data.draft_id');
    test()->deleteJson(BD_URL."/{$gone}", [], BD_HEADERS)->assertOk();
    dslRerun($gone)->assertNotFound();
    dslGet($gone)->assertNotFound();

    // The published state arrives with Story 3.19; until then the version is moved past Draft by hand, and the constraint put back.
    $db = Cluster::superuser();
    $db->exec('ALTER TABLE block_versions DROP CONSTRAINT block_versions_state_check');

    try {
        $db->prepare("UPDATE block_versions SET state = 'published' WHERE id = ?")->execute([$id]);
        $before = Cluster::rows($db, 'select config, revision from block_versions where id = ?', [$id]);

        dslRerun($id)->assertStatus(409)->assertJsonPath('error.code', 'blocks.draft_published');
        dslConfirm($id, 'headline_value')->assertStatus(409);
        expect(Cluster::rows($db, 'select config, revision from block_versions where id = ?', [$id]))->toBe($before);
    } finally {
        $db->prepare("UPDATE block_versions SET state = 'draft' WHERE id = ?")->execute([$id]);
        $db->exec("ALTER TABLE block_versions ADD CONSTRAINT block_versions_state_check CHECK (state IN ('draft', 'discarded'))");
    }
});

it('refuses an empty path on override with a 422; only an explicit null clears the slot', function () {
    $id = dslDraft();
    dslOverride($id, 'kpi_label', '$.region')->assertOk();
    $before = bdRows();

    dslOverride($id, 'kpi_label', '')->assertStatus(422)->assertJsonStructure(['errors' => ['path']]);
    expect(bdRows())->toBe($before);

    dslOverride($id, 'kpi_label', null)->assertOk();
    expect(dslConfig()['slot_map']['kpi_label'])->toBe(['path' => null, 'state' => 'overridden']);
});

it('writes no query plan when nothing is mapped, and keeps one already stored', function () {
    $id = dslDraft('{"region":"EU"}');
    $revision = bdRows()[0]['revision'];

    $data = dslRerun($id)->assertOk()->json('data');
    expect($data)->not->toHaveKey('query_plan')
        ->and(dslConfig())->not->toHaveKey('query_plan')
        ->and(bdRows()[0]['revision'])->toBe($revision);

    bdSave($id, $revision, 1, ['config' => ['query_plan' => ['v' => 1, 'extract' => ['fields' => ['r' => '$.region']], 'shape' => ['kpi_label' => 'r']]]])->assertOk();
    dslRerun($id)->assertOk();
    expect(dslConfig()['query_plan']['shape'])->toEqual(['kpi_label' => 'r']);
});

it('leaves an already decided slot, and a re-run that changes nothing, without a write or a revision bump', function () {
    $id = dslDraft();
    dslOverride($id, 'kpi_label', '$.region')->assertOk();
    dslRerun($id)->assertOk();
    $before = bdRows();

    $data = dslConfirm($id, 'kpi_label')->assertOk()->json('data');
    dslRerun($id)->assertOk();

    expect(dslRow($data, 'kpi_label'))->toMatchArray(['state' => 'overridden', 'path' => '$.region'])
        ->and(dslConfig()['slot_map']['kpi_label']['state'])->toBe('overridden')
        ->and(bdRows())->toBe($before);
});

it('clears the unconfirmed_low blocker when the auto_low slot is confirmed', function () {
    $id = dslDraft('{"total":10,"revenue":12,"label":"x"}');

    $first = dslGet($id)->assertOk()->json('data');
    expect(dslRow($first, 'headline_value')['state'])->toBe('auto_low')
        ->and($first['blockers'])->toContain(['slot' => 'headline_value', 'reason' => 'unconfirmed_low']);

    $after = dslConfirm($id, 'headline_value')->assertOk()->json('data');
    expect(dslRow($after, 'headline_value')['state'])->toBe('confirmed')
        ->and(array_column($after['blockers'], 'slot'))->not->toContain('headline_value');
});
