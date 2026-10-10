<?php

use App\Models\User;
use App\Modules\Blocks\Application\PreviewDraft;
use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftSamples;
use App\Modules\Blocks\Contracts\DraftSlots;
use App\Modules\Blocks\Contracts\SampleCheck;
use App\Modules\Blocks\Contracts\SampleUnavailable;
use App\Modules\Blocks\Contracts\StoredSample;
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

it('leaves a confirmed and an overridden slot untouched on re-run, and a stale confirmed slot reads required_missing (api changed) after a new sample', function () {
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
    expect(dslRow($stale, 'headline_value'))->toMatchArray(['state' => 'required_missing', 'path' => '$.total'])
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

// Story 3.13a: the picker, the role change and undo, "Treat as", and api_changed.
function dslFields(string $id, string $slot)
{
    return test()->getJson(BD_URL."/{$id}/mapping/slots/{$slot}/fields", BD_HEADERS);
}

function dslUndo(string $id, array|string $undo, array $extra = [])
{
    return test()->postJson(BD_URL."/{$id}/mapping/undo", $extra + ['undo' => $undo, 'lock_epoch' => 1], BD_HEADERS);
}

function dslSaveConfig(string $id, array $config)
{
    return bdSave($id, (int) bdRows()[0]['revision'], 1, ['config' => $config])->assertOk();
}

it('lists the field picker of a slot, read-only, compatible first with reasons, and 404s an unknown or config-only slot', function () {
    $id = dslDraft('{"total":10,"currency":"USD","data":[{"v":1},{"v":2}]}');
    $before = bdRows();

    $res = dslFields($id, 'headline_value')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $fields = $res->json('data.fields');

    expect(array_column($fields, 'path'))->toBe(['$.total', '$.currency', '$.data[*].v'])
        ->and(array_column($fields, 'compatible'))->toBe([true, false, false])
        ->and($fields[1]['reason'])->toBe("Text field can't be a numeric headline. Use it as the currency format below.")
        ->and($fields[2]['reason'])->toBe('Needs aggregation (a later release).')
        ->and(array_column(dslFields($id, 'subtitle')->json('data.fields'), 'kind'))->toContain('static_text')
        ->and(bdRows())->toBe($before);

    dslFields($id, 'icon')->assertNotFound();
    dslFields($id, 'footer_action')->assertNotFound();
    dslFields($id, 'bogus')->assertNotFound();
    dslFields('018f0000-0000-7000-8000-000000000000', 'headline_value')->assertNotFound();
    dslFields(bdCreate(['name' => 'Empty'])->assertCreated()->json('data.draft_id'), 'headline_value')->assertStatus(422)->assertJsonPath('error.code', 'mapping.no_sample');
});

it('lets a member who does not hold the lock read the picker', function () {
    $id = dslDraft();
    config(['dashflow.tunables.timeouts.edit_lock_ttl.value' => '60']);
    test()->postJson(BD_URL."/{$id}/lock", [], BD_HEADERS)->assertOk();
    $workspace = bdRows('workspace_memberships')[0]['workspace_id'];
    [$bob] = bdMember($workspace, 'bob2@example.test');
    test()->flushSession();
    test()->actingAs(User::query()->findOrFail($bob))->withSession(['workspace_id' => $workspace, 'area' => 'admin']);

    dslFields($id, 'headline_value')->assertOk();
    dslUndo($id, ['slot' => 'headline_value', 'slot_entry' => null, 'role' => null, 'applied' => ['path' => '$.total', 'state' => 'overridden']])->assertStatus(423);
});

it('overrides a dimension to the headline: the role becomes measure, role_change and undo come back, and undo restores the config', function () {
    $id = dslDraft('{"total":10,"revenue":5}');
    dslSaveConfig($id, ['field_roles' => ['$.revenue' => 'dimension']]);
    dslConfirm($id, 'headline_value')->assertOk();
    $before = dslConfig();
    $revision = bdRows()[0]['revision'];

    $data = dslOverride($id, 'headline_value', '$.revenue')->assertOk()->json('data');

    expect($data['role_change'])->toBe(['path' => '$.revenue', 'from' => 'dimension', 'to' => 'measure'])
        ->and($data['undo'])->toBe(['slot' => 'headline_value', 'slot_entry' => ['path' => '$.total', 'state' => 'confirmed'], 'role' => ['path' => '$.revenue', 'previous' => 'dimension'], 'applied' => ['path' => '$.revenue', 'state' => 'overridden']])
        ->and(dslRow($data, 'headline_value'))->toMatchArray(['state' => 'overridden', 'path' => '$.revenue'])
        ->and(dslConfig()['field_roles'])->toBe(['$.revenue' => 'measure'])
        ->and(dslConfig()['slot_map']['headline_value'])->toBe(['path' => '$.revenue', 'state' => 'overridden']);

    $undone = dslUndo($id, $data['undo'])->assertOk()->json('data');
    $after = dslConfig();
    unset($after['query_plan'], $before['query_plan']);

    expect($after['slot_map'])->toBe($before['slot_map'])
        ->and($after['field_roles'])->toBe($before['field_roles'])
        ->and(dslRow($undone, 'headline_value'))->toMatchArray(['state' => 'confirmed', 'path' => '$.total'])
        ->and(bdRows()[0]['revision'])->toBeGreaterThan($revision);
});

it('does not change a role that already fits, and undoes an override that had no previous entry', function () {
    $id = dslDraft('{"total":10,"revenue":"x"}');
    $before = dslConfig();

    $data = dslOverride($id, 'headline_value', '$.total')->assertOk()->json('data');
    expect($data)->not->toHaveKey('role_change')
        ->and($data['undo'])->toBe(['slot' => 'headline_value', 'slot_entry' => null, 'role' => null, 'applied' => ['path' => '$.total', 'state' => 'overridden']])
        ->and(dslConfig())->not->toHaveKey('field_roles');

    // A text slot never changes roles.
    $text = dslOverride($id, 'kpi_label', '$.revenue')->assertOk()->json('data');
    expect($text)->not->toHaveKey('role_change')->and(dslConfig())->not->toHaveKey('field_roles');

    dslUndo($id, $data['undo'])->assertOk();
    expect(dslConfig()['slot_map'])->not->toHaveKey('headline_value')->and(dslConfig()['slot_map'])->toHaveKey('kpi_label');
    expect(array_diff_key(dslConfig(), ['slot_map' => 1, 'query_plan' => 1]))->toBe(array_diff_key($before, ['slot_map' => 1, 'query_plan' => 1]));
});

it('re-fills unconfirmed slots on undo and leaves decided slots as they are', function () {
    $id = dslDraft('{"total":10,"region":"EU","month":"Jan"}');
    dslOverride($id, 'kpi_label', '$.region')->assertOk();
    $over = dslOverride($id, 'headline_value', '$.total')->assertOk()->json('data');

    $data = dslUndo($id, $over['undo'])->assertOk()->json('data');

    expect(dslRow($data, 'headline_value')['state'])->toBe('auto_high')
        ->and(dslRow($data, 'kpi_label'))->toMatchArray(['state' => 'overridden', 'path' => '$.region'])
        ->and($data['query_plan']['extract']['fields'])->toMatchArray(['headline_value' => '$.total', 'kpi_label' => '$.region'])
        ->and(dslConfig()['query_plan'])->toEqual($data['query_plan']);
});

it('refuses a malformed or unknown undo with a 422 and writes nothing', function () {
    $id = dslDraft();
    dslOverride($id, 'headline_value', '$.total')->assertOk();
    $applied = ['path' => '$.total', 'state' => 'overridden'];
    $before = bdRows();

    dslUndo($id, ['slot' => 'bogus', 'slot_entry' => null, 'role' => null, 'applied' => $applied])->assertStatus(422);
    expect(array_keys(dslUndo($id, ['slot' => 'bogus', 'slot_entry' => null, 'role' => null, 'applied' => $applied])->json('errors')))->toBe(['slot.bogus']);
    dslUndo($id, ['slot' => 'icon', 'slot_entry' => null, 'role' => null, 'applied' => $applied])->assertStatus(422);
    dslUndo($id, ['slot' => 'Head-line', 'slot_entry' => null, 'role' => null, 'applied' => $applied])->assertStatus(422);
    dslUndo($id, ['slot' => 'headline_value'])->assertStatus(422);
    dslUndo($id, ['slot' => 'headline_value', 'slot_entry' => [], 'role' => null, 'applied' => $applied])->assertStatus(422);
    dslUndo($id, ['slot' => 'headline_value', 'slot_entry' => null, 'role' => [], 'applied' => $applied])->assertStatus(422);
    dslUndo($id, ['slot' => 'headline_value', 'slot_entry' => ['path' => '$.total', 'state' => 'auto_high'], 'role' => null, 'applied' => $applied])->assertStatus(422);
    dslUndo($id, ['slot' => 'headline_value', 'slot_entry' => null, 'role' => ['path' => '$.total', 'previous' => 'bogus'], 'applied' => $applied])->assertStatus(422);
    dslUndo($id, 'nope')->assertStatus(422);
    expect(bdRows())->toBe($before);
});

it('refuses a stale undo with a 409 and a forged role path or restored path with a 422, writing nothing', function () {
    $id = dslDraft('{"total":10,"revenue":5,"region":"EU"}');
    dslSaveConfig($id, ['field_roles' => ['$.revenue' => 'dimension']]);
    $over = dslOverride($id, 'headline_value', '$.revenue')->assertOk()->json('data.undo');
    $before = bdRows();

    // Forged role path: a path that is neither the restored one nor the applied one.
    dslUndo($id, ['role' => ['path' => '$.total', 'previous' => 'filter']] + $over)->assertStatus(422)->assertJsonPath('error.code', 'blocks.invalid_config');
    // Forged restored path: not a field the slot accepts (text for a number slot, and a path not in the sample).
    dslUndo($id, ['slot_entry' => ['path' => '$.region', 'state' => 'confirmed']] + $over)->assertStatus(422);
    expect(array_keys(dslUndo($id, ['slot_entry' => ['path' => '$.nope', 'state' => 'confirmed']] + $over)->json('errors')))->toBe(['slot.headline_value']);
    expect(bdRows())->toBe($before);

    // A later decision on the slot makes the undo stale.
    dslOverride($id, 'headline_value', '$.total')->assertOk();
    $later = bdRows();
    dslUndo($id, $over)->assertStatus(409)->assertJsonPath('error.code', 'blocks.revision_conflict');
    expect(bdRows())->toBe($later);

    // So does an undo that was already used.
    $fresh = dslOverride($id, 'kpi_label', '$.region')->assertOk()->json('data.undo');
    dslUndo($id, $fresh)->assertOk();
    dslUndo($id, $fresh)->assertStatus(409);
});

it('undoes the override of a cleared slot: the cleared entry comes back exactly', function () {
    $id = dslDraft();
    dslOverride($id, 'kpi_label', null)->assertOk();
    $undo = dslOverride($id, 'kpi_label', '$.region')->assertOk()->json('data.undo');

    expect($undo['slot_entry'])->toBe(['path' => null, 'state' => 'overridden']);
    dslUndo($id, $undo)->assertOk();
    expect(dslConfig()['slot_map']['kpi_label'])->toBe(['path' => null, 'state' => 'overridden']);

    // Clearing a slot is an override too: its undo restores what was there.
    $clear = dslOverride($id, 'kpi_label', null)->assertOk()->json('data.undo');
    expect($clear['applied'])->toBe(['path' => null, 'state' => 'overridden']);
    dslUndo($id, $clear)->assertOk();
});

it('removes the first field_roles entry on undo, leaving no field_roles key', function () {
    $id = dslDraft('{"total":10,"revenue":"5"}');
    dslSaveConfig($id, ['field_types' => ['$.revenue' => 'number']]);
    expect(dslConfig())->not->toHaveKey('field_roles');

    $data = dslOverride($id, 'headline_value', '$.revenue')->assertOk()->json('data');
    expect($data['role_change']['to'])->toBe('measure')->and(dslConfig()['field_roles'])->toBe(['$.revenue' => 'measure']);

    dslUndo($id, $data['undo'])->assertOk();
    expect(dslConfig())->not->toHaveKey('field_roles');
});

it('answers 503 on the picker, as the other reads do, when the sample cannot be opened', function () {
    $id = dslDraft();
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
    app()->forgetInstance(DraftSlots::class);

    $response = dslFields($id, 'headline_value')->assertStatus(503)->assertJsonPath('error.code', 'blocks.sample_unavailable');
    expect($response->getContent())->not->toContain(DSL_CANARY);
});

it('refuses a text field for the headline with the reason and the compatible fields, and no sample text', function () {
    $id = dslDraft('{"total":10,"data":{"currency":"CANARY-slots-5d2a"}}');
    $before = bdRows();

    $res = dslOverride($id, 'headline_value', '$.data.currency')->assertStatus(422);

    expect(array_keys($res->json('errors')))->toBe(['slot.headline_value'])
        ->and($res->json('errors')['slot.headline_value'][0])->toBe("Text field can't be a numeric headline. Use it as the currency format below.")
        ->and($res->json('compatible'))->toBe([['path' => '$.total', 'display' => 'total']])
        ->and($res->getContent())->not->toContain(DSL_CANARY)
        ->and(bdRows())->toBe($before);
});

it('treats a decimal string as a number: it fits the headline and the preview shows the exact string; a bad treat-as is refused', function () {
    $id = dslDraft('{"total":"284680","currency":"USD"}');

    dslOverride($id, 'headline_value', '$.total')->assertStatus(422);

    dslSaveConfig($id, ['field_types' => ['$.total' => 'number', '$.currency' => 'number']]);
    expect(dslConfig()['field_types'])->toBe(['$.total' => 'number', '$.currency' => 'number']);

    $data = dslOverride($id, 'headline_value', '$.total')->assertOk()->json('data');
    // The role of a string is a label; picking it for a number slot makes it a measure.
    expect($data['role_change'])->toBe(['path' => '$.total', 'from' => 'label', 'to' => 'measure']);

    dslRerun($id)->assertOk();
    $preview = test()->postJson(BD_URL."/{$id}/preview", [], BD_HEADERS)->assertOk()->json('data');
    expect($preview['payload']['headline']['value'])->toBe('284680');

    // Any other forced mismatch stays a mismatch with its reason; the field is never discarded from the slot.
    $res = dslOverride($id, 'headline_value', '$.currency')->assertStatus(422);
    expect($res->json('errors')['slot.headline_value'][0])->toBe('currency is number. Headline value needs a number.');

    dslSaveConfig($id, ['slot_map' => ['headline_value' => ['path' => '$.currency', 'state' => 'overridden']]]);
    $row = dslRow(dslGet($id)->json('data'), 'headline_value');
    expect($row)->toMatchArray(['state' => 'type_mismatch', 'path' => '$.currency', 'reason' => 'currency is number. Headline value needs a number.']);
});

it('refuses a bad field_types save, keeps it over a save that omits it, and clears it with null', function () {
    $id = dslDraft();
    dslSaveConfig($id, ['field_types' => ['$.total' => 'text']]);

    $bad = bdSave($id, (int) bdRows()[0]['revision'], 1, ['config' => ['field_types' => ['$.total' => 'money', 'a..b' => 'number', '$.region' => null]]])
        ->assertStatus(422)->assertJsonPath('error.code', 'blocks.invalid_config');
    expect(array_keys($bad->json('errors')))->toBe(['config.field_types.$.total', 'config.field_types.a..b', 'config.field_types.$.region']);

    dslSaveConfig($id, ['description' => 'later']);
    expect(dslConfig()['field_types'])->toBe(['$.total' => 'text']);

    dslSaveConfig($id, ['field_types' => null]);
    expect(dslConfig()['field_types'])->toBe([]);
});

it('reports api_changed with a match: the slot reads required_missing, the blocker is listed and nothing is applied', function () {
    $id = dslDraft('{"total":10,"region":"EU"}');
    dslConfirm($id, 'headline_value')->assertOk();
    dslPaste($id, '{"grand_total":10,"region":"EU"}');
    $before = dslConfig();

    $data = dslGet($id)->assertOk()->json('data');

    expect(dslRow($data, 'headline_value'))->toMatchArray(['state' => 'required_missing', 'path' => '$.total'])
        ->and($data['api_changed'])->toBe([['slot' => 'headline_value', 'path' => '$.total', 'display' => 'total', 'state' => 'required_missing', 'match' => ['path' => '$.grand_total', 'display' => 'grand_total']]])
        ->and($data['blockers'])->toContain(['slot' => 'headline_value', 'reason' => 'required_missing'])
        ->and(dslConfig())->toBe($before);

    // A re-run does not apply the match either.
    dslRerun($id)->assertOk();
    expect(dslConfig()['slot_map']['headline_value']['path'])->toBe('$.total');
});

it('reports an optional slot that lost its field as optional_empty and an api_changed blocker', function () {
    $id = dslDraft();
    dslOverride($id, 'status_badge', '$.status')->assertOk();
    dslPaste($id, '{"total":10,"month":"x"}');

    $data = dslGet($id)->assertOk()->json('data');

    expect(dslRow($data, 'status_badge')['state'])->toBe('optional_empty')
        ->and($data['api_changed'][0])->toMatchArray(['slot' => 'status_badge', 'state' => 'optional_empty', 'match' => null])
        ->and($data['blockers'])->toContain(['slot' => 'status_badge', 'reason' => 'api_changed']);
});
