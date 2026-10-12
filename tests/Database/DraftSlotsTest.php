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

function dslGet(string $id, array $headers = [])
{
    return test()->getJson(BD_URL."/{$id}/mapping", $headers + BD_HEADERS);
}

/** The Draft's stored revision: every mapping write carries it (Story 3.13c). */
function dslRev(): int
{
    return (int) bdRows()[0]['revision'];
}

function dslRerun(string $id, array $extra = [])
{
    return test()->postJson(BD_URL."/{$id}/mapping/auto-map", $extra + ['draft_revision' => dslRev(), 'lock_epoch' => 1], BD_HEADERS);
}

function dslConfirm(string $id, string $slot, array $extra = [])
{
    return test()->postJson(BD_URL."/{$id}/mapping/slots/{$slot}/confirm", $extra + ['draft_revision' => dslRev(), 'lock_epoch' => 1], BD_HEADERS);
}

function dslOverride(string $id, string $slot, ?string $path, array $extra = [])
{
    return test()->putJson(BD_URL."/{$id}/mapping/slots/{$slot}", $extra + ['path' => $path, 'draft_revision' => dslRev(), 'lock_epoch' => 1], BD_HEADERS);
}

/** The mapping-scoped writes: `record-path`, `field-roles` and `field-types`. */
function dslWrite(string $id, string $what, array $body, array $extra = [])
{
    return test()->putJson(BD_URL."/{$id}/mapping/{$what}", $body + $extra + ['draft_revision' => dslRev(), 'lock_epoch' => 1], BD_HEADERS);
}

function dslRole(string $id, string $path, ?string $role)
{
    return dslWrite($id, 'field-roles', ['path' => $path, 'role' => $role])->assertOk();
}

function dslType(string $id, string $path, ?string $type)
{
    return dslWrite($id, 'field-types', ['path' => $path, 'type' => $type])->assertOk();
}

/** The checklist of the one read. */
function dslList(string $id): array
{
    return dslGet($id)->assertOk()->json('data.checklist');
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

    $first = dslGet($id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data.checklist');
    $second = dslList($id);

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
    expect(dslRow(dslGet($id)->json('data.checklist'), 'headline_value')['state'])->toBe('auto_high');
});

it('is type_mismatch when the numbers are only in the rows', function () {
    $id = dslDraft('{"data":[{"revenue":1},{"revenue":2}]}');

    $data = dslList($id);
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
    $stale = dslList($id);
    expect(dslRow($stale, 'headline_value'))->toMatchArray(['state' => 'required_missing', 'path' => '$.total'])
        ->and(dslRow($stale, 'kpi_label')['state'])->toBe('overridden')
        ->and(dslConfig()['slot_map'])->toBe($map);
});

it('keeps slot_map over a save that omits it, and refuses a save that carries it, naming the mapping endpoints', function () {
    $id = dslDraft();
    dslConfirm($id, 'headline_value')->assertOk();
    $map = dslConfig()['slot_map'];

    bdSave($id, dslRev(), 1, ['config' => ['description' => 'later']])->assertOk();
    expect(dslConfig()['slot_map'])->toBe($map);

    $before = bdRows();
    $bad = bdSave($id, dslRev(), 1, ['config' => ['slot_map' => ['headline_value' => ['path' => 'a..b', 'state' => 'confirmed']]]])->assertStatus(422)->assertJsonPath('error.code', 'blocks.invalid_config');
    expect(array_keys($bad->json('errors')))->toBe(['config.slot_map'])->and($bad->json('errors')['config.slot_map'][0])->toContain('mapping endpoints');
    bdSave($id, dslRev(), 1, ['config' => ['slot_map' => null]])->assertStatus(422);
    expect(bdRows())->toBe($before);
});

it('reads a draft without a sample as 200 expired, writes are 422 mapping.no_sample, and a missing draft is 404', function () {
    $id = dslDraft(null);

    dslGet($id)->assertOk()->assertJsonPath('data.sample', 'expired')->assertJsonPath('data.checklist', null);
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

    // A version that was published meanwhile (Story 3.19): the guard trigger is lifted for the test so the same row can be put back afterwards.
    $db = Cluster::superuser();
    $db->exec('ALTER TABLE block_versions DISABLE TRIGGER block_versions_guard');

    try {
        $db->prepare("UPDATE block_versions SET state = 'published', prepared_at = now(), published_at = now() WHERE id = ?")->execute([$id]);
        $before = Cluster::rows($db, 'select config, revision from block_versions where id = ?', [$id]);

        dslRerun($id)->assertStatus(409)->assertJsonPath('error.code', 'blocks.draft_published');
        dslConfirm($id, 'headline_value')->assertStatus(409);
        expect(Cluster::rows($db, 'select config, revision from block_versions where id = ?', [$id]))->toBe($before);
    } finally {
        $db->prepare("UPDATE block_versions SET state = 'draft', prepared_at = NULL, published_at = NULL WHERE id = ?")->execute([$id]);
        $db->exec('ALTER TABLE block_versions ENABLE TRIGGER block_versions_guard');
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

it('removes the stored plan and its stamp when nothing is mapped; a re-run with the same mapping changes nothing', function () {
    $id = dslDraft('{"region":"EU"}');
    $revision = bdRows()[0]['revision'];

    $data = dslRerun($id)->assertOk()->json('data');
    expect($data['query_plan'])->toBeNull()
        ->and(dslConfig())->not->toHaveKey('query_plan')->and(dslConfig())->not->toHaveKey('mapped_sample_revision')
        ->and(bdRows()[0]['revision'])->toBe($revision);

    // A plan saved by hand (a generic save still accepts one) is replaced by the mapping's on the next write: here, removed.
    bdSave($id, dslRev(), 1, ['config' => ['query_plan' => ['v' => 1, 'extract' => ['fields' => ['r' => '$.region']], 'shape' => ['kpi_label' => 'r']]]])->assertOk();
    expect(dslConfig()['query_plan']['shape'])->toEqual(['kpi_label' => 'r']);

    $after = dslRerun($id)->assertOk()->json('data');
    expect($after['query_plan'])->toBeNull()->and(dslConfig())->not->toHaveKey('query_plan');
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

    $first = dslList($id);
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
    return test()->postJson(BD_URL."/{$id}/mapping/undo", $extra + ['undo' => $undo, 'draft_revision' => dslRev(), 'lock_epoch' => 1], BD_HEADERS);
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
    dslRole($id, '$.revenue', 'dimension');
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
    expect(array_diff_key(dslConfig(), ['slot_map' => 1, 'query_plan' => 1, 'mapped_sample_revision' => 1]))->toBe(array_diff_key($before, ['slot_map' => 1, 'query_plan' => 1, 'mapped_sample_revision' => 1]));
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
    dslRole($id, '$.revenue', 'dimension');
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
    dslUndo($id, $over)->assertStatus(409)->assertJsonPath('error.code', 'blocks.slot_changed')->assertJsonStructure(['current' => ['data' => ['revision']]]);
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
    dslType($id, '$.revenue', 'number');
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

    dslType($id, '$.total', 'number');
    dslType($id, '$.currency', 'number');
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

    // A type changed after the decision turns the decided slot into a mismatch: it keeps its field.
    dslType($id, '$.total', 'boolean');
    $row = dslRow(dslList($id), 'headline_value');
    expect($row)->toMatchArray(['state' => 'type_mismatch', 'path' => '$.total', 'reason' => 'total is boolean. Headline value needs a number.']);
});

it('writes field types through the endpoint: refuses a bad one, keeps them over a save that omits them, refuses a generic save, and clears with null', function () {
    $id = dslDraft();
    dslType($id, '$.total', 'text');
    $before = bdRows();

    dslWrite($id, 'field-types', ['path' => '$.total', 'type' => 'money'])->assertStatus(422)->assertJsonPath('error.code', 'blocks.invalid_config')->assertJsonStructure(['errors' => ['type']]);
    dslWrite($id, 'field-types', ['path' => 'a..b', 'type' => 'number'])->assertStatus(422)->assertJsonStructure(['errors' => ['path']]);
    dslWrite($id, 'field-types', ['path' => '$.nope', 'type' => 'number'])->assertStatus(422)->assertJsonStructure(['errors' => ['path']]);
    bdSave($id, dslRev(), 1, ['config' => ['field_types' => ['$.total' => 'number']]])->assertStatus(422)->assertJsonStructure(['errors' => ['config.field_types']]);
    expect(bdRows())->toBe($before);

    bdSave($id, dslRev(), 1, ['config' => ['description' => 'later']])->assertOk();
    expect(dslConfig()['field_types'])->toBe(['$.total' => 'text']);

    dslType($id, '$.total', null);
    expect(dslConfig())->not->toHaveKey('field_types');
});

it('reports api_changed with a match: the slot reads required_missing, the blocker is listed and nothing is applied', function () {
    $id = dslDraft('{"total":10,"region":"EU"}');
    dslConfirm($id, 'headline_value')->assertOk();
    dslPaste($id, '{"grand_total":10,"region":"EU"}');
    $before = dslConfig();

    $data = dslList($id);

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

    $data = dslList($id);

    expect(dslRow($data, 'status_badge')['state'])->toBe('optional_empty')
        ->and($data['api_changed'][0])->toMatchArray(['slot' => 'status_badge', 'state' => 'optional_empty', 'match' => null])
        ->and($data['blockers'])->toContain(['slot' => 'status_badge', 'reason' => 'api_changed']);
});

// Story 3.13c: the plan follows every write, one consistent read, revisions on every write, per-slot preview values.
function dslPreview(string $id)
{
    return test()->postJson(BD_URL."/{$id}/preview", [], BD_HEADERS);
}

it('recomputes the plan on every write, so a following preview reflects it without a re-run, and the revisions in the response equal the stored ones', function () {
    $id = dslDraft();
    $stamp = fn () => dslConfig()['mapped_sample_revision'] ?? null;

    // A write that is not a re-run: a confirm of the auto slot writes the plan too.
    $confirmed = dslConfirm($id, 'headline_value')->assertOk()->json('data');
    expect($confirmed['query_plan']['extract']['fields']['headline_value'])->toBe('$.total')
        ->and($confirmed['draft_revision'])->toBe(dslRev())->and($confirmed['sample_revision'])->toBe(1)
        ->and(dslConfig()['query_plan'])->toEqual($confirmed['query_plan'])->and($stamp())->toBe(1);
    expect(dslPreview($id)->assertOk()->json('data.payload.headline.value'))->toBe('284680.50');

    // Override, then preview: the new plan is used.
    $over = dslOverride($id, 'headline_value', '$.previous_total')->assertOk()->json('data');
    expect($over['query_plan']['extract']['fields']['headline_value'])->toBe('$.previous_total')
        ->and($over['draft_revision'])->toBe(dslRev());
    expect(dslPreview($id)->assertOk()->json('data.payload.headline.value'))->toBe('250000');

    // Undo, and the three new writes, carry the same fields.
    $undone = dslUndo($id, $over['undo'])->assertOk()->json('data');
    expect($undone['draft_revision'])->toBe(dslRev())->and($undone['sample_revision'])->toBe(1)->and($undone['query_plan'])->toEqual(dslConfig()['query_plan']);

    foreach ([['field-roles', ['path' => '$.region', 'role' => 'filter']], ['field-types', ['path' => '$.region', 'type' => 'text']]] as [$what, $body]) {
        $res = dslWrite($id, $what, $body)->assertOk()->json('data');
        expect($res['draft_revision'])->toBe(dslRev())->and($res['query_plan'])->toEqual(dslConfig()['query_plan'])->and($res['sample_revision'])->toBe(1);
    }
});

it('removes the plan and its stamp when the last mapped slot is cleared, and the preview is 422 mapping.no_plan', function () {
    $id = dslDraft('{"total":10}');
    dslRerun($id)->assertOk();
    expect(dslConfig())->toHaveKey('query_plan');

    $data = dslOverride($id, 'headline_value', null)->assertOk()->json('data');

    expect($data['query_plan'])->toBeNull()
        ->and(dslConfig())->not->toHaveKey('query_plan')->and(dslConfig())->not->toHaveKey('mapped_sample_revision')
        ->and(dslConfig()['slot_map']['headline_value'])->toBe(['path' => null, 'state' => 'overridden']);
    dslPreview($id)->assertStatus(422)->assertJsonPath('error.code', 'mapping.no_plan');
});

it('requires draft_revision on every mapping write: a stale one is a 409 blocks.revision_conflict with current and writes nothing', function () {
    $id = dslDraft();
    dslRerun($id)->assertOk();
    $over = dslOverride($id, 'kpi_label', '$.region')->assertOk()->json('data.undo');
    $before = bdRows();
    $stale = ['draft_revision' => 1];

    $calls = [
        fn () => dslRerun($id, $stale),
        fn () => dslConfirm($id, 'headline_value', $stale),
        fn () => dslOverride($id, 'kpi_label', '$.month', $stale),
        fn () => dslUndo($id, $over, $stale),
        fn () => dslWrite($id, 'record-path', ['record_path' => null], $stale),
        fn () => dslWrite($id, 'field-roles', ['path' => '$.region', 'role' => 'filter'], $stale),
        fn () => dslWrite($id, 'field-types', ['path' => '$.region', 'type' => 'text'], $stale),
    ];

    foreach ($calls as $call) {
        $res = $call()->assertStatus(409)->assertJsonPath('error.code', 'blocks.revision_conflict');
        expect($res->json('current.data.revision'))->toBe((int) $before[0]['revision']);
    }

    expect(bdRows())->toBe($before);

    // Missing is a validation error, not a silent pass.
    test()->postJson(BD_URL."/{$id}/mapping/auto-map", ['lock_epoch' => 1], BD_HEADERS)->assertStatus(422)->assertJsonStructure(['errors' => ['draft_revision']]);
    expect(bdRows())->toBe($before);
});

it('reads the mapping in one pass with an ETag of both revisions and a 304 for a matching If-None-Match', function () {
    $id = dslDraft();
    dslRerun($id)->assertOk();
    dslOverride($id, 'kpi_label', '$.region')->assertOk();

    $res = dslGet($id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $data = $res->json('data');
    $etag = '"d'.dslRev().'-s1"';

    expect($res->headers->get('ETag'))->toBe($etag)
        ->and(array_keys($data))->toEqualCanonicalizing(['checklist', 'roles', 'slot_map', 'query_plan', 'draft_revision', 'sample_revision', 'plan_stale', 'sample', 'dataset_suggestion', 'presentation', 'transforms'])
        ->and($data['sample'])->toBe('ok')->and($data['plan_stale'])->toBeFalse()
        ->and($data['draft_revision'])->toBe(dslRev())->and($data['sample_revision'])->toBe(1)
        ->and($data['query_plan'])->toEqual(dslConfig()['query_plan'])
        ->and($data['roles']['record_path'])->toBeNull()
        ->and(array_column($data['roles']['fields'], 'path'))->toContain('$.total')
        ->and($data['slot_map']['kpi_label'])->toBe(['path' => '$.region', 'state' => 'overridden']);

    $same = dslGet($id, ['If-None-Match' => $etag])->assertStatus(304);
    expect($same->getContent())->toBe('')->and($same->headers->get('ETag'))->toBe($etag);
    dslGet($id, ['If-None-Match' => 'W/'.$etag])->assertStatus(304);
    dslGet($id, ['If-None-Match' => '*'])->assertStatus(304);
    dslGet($id, ['If-None-Match' => '"x", '.$etag])->assertStatus(304);
    dslGet($id, ['If-None-Match' => '"d1-s1"'])->assertOk();

    // A write or a new sample changes the validator.
    dslConfirm($id, 'headline_value')->assertOk();
    dslGet($id, ['If-None-Match' => $etag])->assertOk();
});

it('marks the plan stale when the sample was replaced, and a re-run builds it again for the new sample', function () {
    $id = dslDraft();
    dslRerun($id)->assertOk();
    expect(dslGet($id)->json('data.plan_stale'))->toBeFalse();

    dslPaste($id, '{"total":12,"region":"EU"}');
    $data = dslGet($id)->assertOk()->json('data');
    expect($data['plan_stale'])->toBeTrue()->and($data['sample_revision'])->toBe(2)->and($data['query_plan'])->not->toBeNull();

    dslRerun($id)->assertOk();
    $after = dslGet($id)->json('data');
    expect($after['plan_stale'])->toBeFalse()->and(dslConfig()['mapped_sample_revision'])->toBe(2);
});

it('reads an expired sample as 200 with sample expired, the stored decisions and plan, and no computed checklist; writes are 422 mapping.no_sample', function () {
    $id = dslDraft();
    dslConfirm($id, 'headline_value')->assertOk();
    dslOverride($id, 'kpi_label', '$.region')->assertOk();
    $stored = dslConfig();
    Cluster::superuser()->exec("UPDATE draft_samples SET expires_at = now() - interval '1 minute'");

    $res = dslGet($id)->assertOk();
    $data = $res->json('data');

    expect($data['sample'])->toBe('expired')->and($data['checklist'])->toBeNull()->and($data['roles'])->toBeNull()->and($data['sample_revision'])->toBeNull()
        ->and($data['slot_map'])->toBe($stored['slot_map'])->and($data['query_plan'])->toEqual($stored['query_plan'])
        ->and($data['draft_revision'])->toBe(dslRev())->and($data['plan_stale'])->toBeTrue()
        ->and($res->headers->get('ETag'))->toBe('"d'.dslRev().'-s0"');

    dslRerun($id)->assertStatus(422)->assertJsonPath('error.code', 'mapping.no_sample');
    dslWrite($id, 'field-roles', ['path' => '$.region', 'role' => 'filter'])->assertStatus(422)->assertJsonPath('error.code', 'mapping.no_sample');
    expect(dslConfig())->toBe($stored);
});

it('gives the preview a value per mapped slot, the draft revision, and the checklist an example per row', function () {
    $id = dslDraft();
    dslRerun($id)->assertOk();

    $preview = dslPreview($id)->assertOk()->json('data');

    expect($preview['draft_revision'])->toBe(dslRev())->and($preview['sample_revision'])->toBe(1)->and($preview['plan_stale'])->toBeFalse()
        ->and($preview['slots']['headline_value'])->toBe(['value' => '284680.50', 'path' => '$.total'])
        ->and($preview['slots']['status_badge'])->toBe(['value' => DSL_CANARY, 'path' => '$.status'])
        ->and($preview['slots'])->not->toHaveKey('subtitle');

    $rows = dslList($id);
    expect(dslRow($rows, 'headline_value')['example'])->toBe('284680.50')
        ->and(dslRow($rows, 'status_badge')['example'])->toBe(DSL_CANARY)
        ->and(dslRow($rows, 'subtitle')['example'])->toBeNull();

    // A slot the sample cannot fill says why, with a null value.
    dslOverride($id, 'kpi_label', '$.region')->assertOk();
    dslPaste($id, '{"total":1}');
    $goneData = dslPreview($id)->assertOk()->json('data');
    $gone = $goneData['slots'];
    expect($goneData['plan_stale'])->toBeTrue()->and($gone['kpi_label'])->toBe(['value' => null, 'path' => '$.region', 'unavailable' => 'missing']);
});

it('sets and clears the record path through its endpoint, re-maps, and keeps a decided slot whose path vanished as api_changed', function () {
    $id = dslDraft('{"total":10,"data":[{"v":1},{"v":2}],"meta":{"links":[{"h":"x"}]}}');
    dslOverride($id, 'headline_value', '$.total')->assertOk();
    $before = bdRows();

    foreach (['$.nowhere[*]', '$.data', 'a..b'] as $bad) {
        dslWrite($id, 'record-path', ['record_path' => $bad])->assertStatus(422);
    }
    expect(bdRows())->toBe($before);

    $data = dslWrite($id, 'record-path', ['record_path' => '$.meta.links[*]'])->assertOk()->json('data');
    expect(dslConfig()['record_path'])->toBe('$.meta.links[*]')->and($data['draft_revision'])->toBe(dslRev())
        ->and(dslGet($id)->json('data.roles.record_path'))->toBe('$.meta.links[*]');

    // The same value again is a no-op: no write, no revision.
    $revision = dslRev();
    dslWrite($id, 'record-path', ['record_path' => '$.meta.links[*]'])->assertOk();
    expect(dslRev())->toBe($revision);

    $cleared = dslWrite($id, 'record-path', ['record_path' => null])->assertOk()->json('data');
    expect(dslConfig())->not->toHaveKey('record_path')->and($cleared['query_plan']['extract']['fields']['headline_value'])->toBe('$.total');

    // A decided slot whose path left the sample stays stored and reads api_changed (here: a role/record change cannot remove a field, a new sample does).
    dslPaste($id, '{"grand_total":10}');
    dslWrite($id, 'record-path', ['record_path' => null])->assertOk();
    $list = dslList($id);
    expect(dslConfig()['slot_map']['headline_value']['path'])->toBe('$.total')->and($list['api_changed'][0]['slot'])->toBe('headline_value');
});

it('sets and clears a field role through its endpoint and re-maps: a role change moves the auto headline', function () {
    $id = dslDraft('{"total":10,"revenue":5}');
    expect(dslRow(dslList($id), 'headline_value')['state'])->toBe('auto_low');

    $data = dslWrite($id, 'field-roles', ['path' => '$.revenue', 'role' => 'dimension'])->assertOk()->json('data');
    expect(dslRow($data, 'headline_value'))->toMatchArray(['state' => 'auto_high', 'path' => '$.total'])
        ->and(dslConfig()['field_roles'])->toBe(['$.revenue' => 'dimension'])
        ->and($data['query_plan']['extract']['fields']['headline_value'])->toBe('$.total');

    dslWrite($id, 'field-roles', ['path' => '$.revenue', 'role' => null])->assertOk();
    expect(dslConfig())->not->toHaveKey('field_roles')->and(dslRow(dslList($id), 'headline_value')['state'])->toBe('auto_low');
    dslWrite($id, 'field-roles', ['path' => '$.revenue', 'role' => 'bogus'])->assertStatus(422);
    dslWrite($id, 'field-roles', ['path' => '$.revenue'])->assertStatus(422)->assertJsonStructure(['errors' => ['role']]);
});

it('writes the mapping keys only with the edit-lock claim: the new endpoints are a 423 without it', function () {
    $id = dslDraft();
    config(['dashflow.tunables.timeouts.edit_lock_ttl.value' => '60']);
    test()->postJson(BD_URL."/{$id}/lock", [], BD_HEADERS)->assertOk();
    $before = bdRows();

    dslWrite($id, 'record-path', ['record_path' => null])->assertStatus(423);
    dslWrite($id, 'field-roles', ['path' => '$.region', 'role' => 'filter'])->assertStatus(423);
    dslWrite($id, 'field-types', ['path' => '$.region', 'type' => 'text'])->assertStatus(423);
    expect(bdRows())->toBe($before);
});

it('counts an unstamped (hand-saved) plan as stale, and a generic save that carries a plan drops the stamp', function () {
    $id = dslDraft();
    dslRerun($id)->assertOk();
    expect(dslConfig())->toHaveKey('mapped_sample_revision');

    bdSave($id, dslRev(), 1, ['config' => ['query_plan' => ['v' => 1, 'extract' => ['fields' => ['r' => '$.region']], 'shape' => ['kpi_label' => 'r']]]])->assertOk();
    expect(dslConfig())->not->toHaveKey('mapped_sample_revision')->and(dslGet($id)->json('data.plan_stale'))->toBeTrue()
        ->and(dslPreview($id)->assertOk()->json('data.plan_stale'))->toBeTrue();

    // A save that omits the plan leaves both as they are.
    dslRerun($id)->assertOk();
    bdSave($id, dslRev(), 1, ['config' => ['description' => 'x']])->assertOk();
    expect(dslConfig())->toHaveKey('mapped_sample_revision')->and(dslGet($id)->json('data.plan_stale'))->toBeFalse();
});

it('reads a stored sample that no longer passes the limits as sample invalid, not expired', function () {
    $id = dslDraft();
    dslConfirm($id, 'headline_value')->assertOk();
    config(['dashflow.tunables.guards.max_bytes.value' => '10']);
    // The decoded shape of a revision is kept in this process; a new worker reads the limits afresh.
    PreviewDraft::forget();

    $data = dslGet($id)->assertOk()->json('data');

    expect($data['sample'])->toBe('invalid')->and($data['checklist'])->toBeNull()->and($data['slot_map'])->toHaveKey('headline_value')->and($data['plan_stale'])->toBeTrue();
    dslRerun($id)->assertStatus(422)->assertJsonPath('error.code', 'mapping.no_sample');
});

it('rejects an empty string for record_path, role and type with a 422; only an explicit null clears', function () {
    $id = dslDraft();
    dslRole($id, '$.region', 'filter');
    $before = bdRows();

    dslWrite($id, 'record-path', ['record_path' => ''])->assertStatus(422)->assertJsonStructure(['errors' => ['record_path']]);
    dslWrite($id, 'field-roles', ['path' => '$.region', 'role' => ''])->assertStatus(422)->assertJsonStructure(['errors' => ['role']]);
    dslWrite($id, 'field-types', ['path' => '$.region', 'type' => ''])->assertStatus(422)->assertJsonStructure(['errors' => ['type']]);
    expect(bdRows())->toBe($before);

    dslRole($id, '$.region', null);
    expect(dslConfig())->not->toHaveKey('field_roles');
});

it('bounds a long text slot value in the preview to 120 characters and an ellipsis', function () {
    $long = str_repeat('x', 300);
    $id = dslDraft('{"total":1,"label":"'.$long.'"}');
    dslOverride($id, 'kpi_label', '$.label')->assertOk();

    $value = dslPreview($id)->assertOk()->json('data.slots.kpi_label.value');

    expect($value)->toBe(str_repeat('x', 120).'…');
});

// Story 3.15a: presentation rules (`config.presentation`), written through PUT .../mapping/presentation.
function dslPresentation(array $overrides = []): array
{
    return array_replace_recursive([
        'slots' => [
            'headline_value' => ['format' => ['kind' => 'currency', 'currencyCode' => 'usd', 'decimalPlaces' => 0], 'emphasis' => ['role' => 'headline', 'size' => 'lg', 'weight' => 'bold'], 'label' => ['prefix' => '~', 'suffix' => '*']],
            'comparison' => ['format' => ['kind' => 'percent', 'decimalPlaces' => 1], 'direction' => 'lower_is_better'],
        ],
        'bands' => [
            ['op' => '>=', 'value' => '100000', 'color' => 'success', 'icon' => 'trending-up', 'label' => 'On target'],
            ['op' => '>=', 'value' => '50', 'color' => 'warning', 'icon' => 'gauge', 'label' => 'Watch'],
        ],
        'empty_display' => '—',
    ], $overrides);
}

function dslPresent(string $id, ?array $presentation, array $extra = [])
{
    return test()->putJson(BD_URL."/{$id}/mapping/presentation", $extra + ['presentation' => $presentation, 'draft_revision' => dslRev(), 'lock_epoch' => 1], BD_HEADERS);
}

it('stores the presentation, returns it with the revision and plan, reads it back and the preview carries the descriptors, emphasis and the matching band', function () {
    $id = dslDraft();
    $saved = dslPresent($id, dslPresentation())->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');

    expect($saved['presentation']['slots']['headline_value']['format'])->toEqual(['kind' => 'currency', 'currencyCode' => 'USD', 'decimalPlaces' => 0])
        ->and($saved['draft_revision'])->toBe(dslRev())
        ->and($saved['query_plan']['extract']['fields']['headline_value'])->toBe('$.total')
        ->and(dslConfig()['presentation'])->toEqual($saved['presentation'])
        ->and(dslConfig()['presentation']['bands'])->toBeList();

    expect(dslGet($id)->assertOk()->json('data.presentation'))->toEqual($saved['presentation']);

    $payload = dslPreview($id)->assertOk()->json('data.payload');
    expect($payload['headline']['format']['kind'])->toBe('currency')
        ->and($payload['headline']['format']['currencyCode'])->toBe('USD')
        ->and($payload['headline']['format']['decimalPlaces'])->toBe(0)
        ->and($payload['headline']['emphasis'])->toBe(['role' => 'headline', 'size' => 'lg', 'weight' => 'bold'])
        ->and($payload['prefix'])->toBe('~')->and($payload['suffix'])->toBe('*')
        ->and($payload['statusBadge'])->toBe(['text' => 'On target', 'color' => 'success', 'icon' => 'trending-up'])
        ->and($payload['emptyDisplay'])->toBe('—');
});

it('picks the first matching band top to bottom, and no band when the headline is below every limit', function () {
    $id = dslDraft();
    $bands = [
        ['op' => '>=', 'value' => '50', 'color' => 'warning', 'icon' => 'gauge', 'label' => 'Watch'],
        ['op' => '>=', 'value' => '100000', 'color' => 'success', 'icon' => 'trending-up', 'label' => 'On target'],
    ];
    dslPresent($id, ['bands' => $bands])->assertOk();
    expect(dslPreview($id)->json('data.payload.statusBadge.text'))->toBe('Watch');

    dslPresent($id, ['bands' => [['op' => '<=', 'value' => '10', 'color' => 'error', 'icon' => 'zap', 'label' => 'Low']]])->assertOk();
    // No band matched: the status_badge slot's own text (the sample's status field) shows, never a band.
    expect(dslPreview($id)->json('data.payload.statusBadge'))->toBe(DSL_CANARY);
});

it('puts lower_is_better in the payload and leaves it out for higher_is_better', function () {
    $id = dslDraft();
    dslOverride($id, 'comparison', '$.previous_total')->assertOk();
    dslPresent($id, ['slots' => ['comparison' => ['direction' => 'lower_is_better']]])->assertOk();
    expect(dslPreview($id)->json('data.payload.comparison.lowerIsBetter'))->toBeTrue();

    dslPresent($id, ['slots' => ['comparison' => ['direction' => 'higher_is_better']]])->assertOk();
    expect(dslPreview($id)->json('data.payload.comparison.lowerIsBetter'))->toBeFalse();
});

it('refuses an invalid presentation naming each path, and stores nothing', function () {
    $id = dslDraft();
    dslPresent($id, dslPresentation())->assertOk();
    $before = bdRows();

    $bad = [
        'slots' => [
            'headline_value' => ['format' => ['kind' => 'currency', 'currencyCode' => 'XX1', 'decimalPlaces' => -1]],
            'comparison' => ['format' => ['kind' => 'number', 'decimalPlaces' => 21], 'direction' => 'sideways'],
            'subtitle' => ['direction' => 'lower_is_better', 'format' => ['kind' => 'weird']],
            'nope' => ['format' => ['kind' => 'number']],
        ],
        'bands' => [
            ['op' => '>=', 'value' => '1', 'color' => 'accent', 'icon' => 'gauge', 'label' => 'x'],
            ['op' => '>=', 'value' => '1', 'color' => 'success', 'label' => 'No icon'],
            ['op' => '>=', 'value' => '1', 'color' => 'success', 'icon' => 'gauge'],
            ['op' => 'between', 'value' => '9', 'to' => '1', 'color' => 'info', 'icon' => 'gauge', 'label' => 'Backwards'],
            ['op' => '>=', 'value' => 'abc', 'color' => 'info', 'icon' => 'gauge', 'label' => 'Not a number'],
        ],
        'empty_display' => str_repeat('x', 200),
    ];
    $res = dslPresent($id, $bad)->assertStatus(422)->assertJsonPath('error.code', 'blocks.invalid_config');
    $paths = array_keys($res->json('errors'));

    foreach ([
        'config.presentation.slots.headline_value.format.currencyCode',
        'config.presentation.slots.headline_value.format.decimalPlaces',
        'config.presentation.slots.comparison.format.decimalPlaces',
        'config.presentation.slots.comparison.direction',
        'config.presentation.slots.subtitle.direction',
        'config.presentation.slots.subtitle.format.kind',
        'config.presentation.slots.nope',
        'config.presentation.bands.0.color',
        'config.presentation.bands.1.icon',
        'config.presentation.bands.2.label',
        'config.presentation.bands.3.to',
        'config.presentation.bands.4.value',
        'config.presentation.empty_display',
    ] as $path) {
        expect($paths)->toContain($path);
    }

    expect(bdRows())->toBe($before);
});

it('refuses a second headline with the reason, on the second slot', function () {
    $id = dslDraft();
    $before = bdRows();
    $res = dslPresent($id, ['slots' => [
        'headline_value' => ['emphasis' => ['role' => 'headline']],
        'comparison' => ['emphasis' => ['role' => 'headline']],
    ]])->assertStatus(422)->assertJsonPath('error.code', 'blocks.invalid_config');

    expect(array_keys($res->json('errors')))->toBe(['config.presentation.slots.comparison.emphasis'])
        ->and($res->json('errors')['config.presentation.slots.comparison.emphasis'][0])->toContain('headline_value')
        ->and(bdRows())->toBe($before);
});

it('refuses text over the limits and a non-object presentation, without echoing the input', function () {
    $id = dslDraft();

    $res = dslPresent($id, ['slots' => ['headline_value' => ['label' => ['prefix' => 'CANARY-'.str_repeat('z', 40)]]]])->assertStatus(422);
    expect(array_keys($res->json('errors')))->toBe(['config.presentation.slots.headline_value.label.prefix'])
        ->and($res->getContent())->not->toContain('CANARY-zzzz');

    test()->putJson(BD_URL."/{$id}/mapping/presentation", ['presentation' => 'nope', 'draft_revision' => dslRev(), 'lock_epoch' => 1], BD_HEADERS)->assertStatus(422);
    test()->putJson(BD_URL."/{$id}/mapping/presentation", ['draft_revision' => dslRev(), 'lock_epoch' => 1], BD_HEADERS)->assertStatus(422);
});

it('refuses a generic save that carries presentation, keeps it over a save that omits it, and clears it with null or an empty object', function () {
    $id = dslDraft();
    dslPresent($id, dslPresentation())->assertOk();
    $stored = dslConfig()['presentation'];
    $before = bdRows();

    bdSave($id, dslRev(), 1, ['config' => ['presentation' => dslPresentation()]])->assertStatus(422)->assertJsonPath('error.code', 'blocks.invalid_config')->assertJsonStructure(['errors' => ['config.presentation']]);
    expect(bdRows())->toBe($before);

    bdSave($id, dslRev(), 1, ['config' => ['description' => 'later']])->assertOk();
    expect(dslConfig()['presentation'])->toBe($stored);

    dslPresent($id, null)->assertOk()->assertJsonPath('data.presentation', []);
    expect(dslConfig())->not->toHaveKey('presentation');

    dslPresent($id, dslPresentation())->assertOk();
    dslPresent($id, [])->assertOk();
    expect(dslConfig())->not->toHaveKey('presentation');
});

it('leaves the revision alone for a presentation write that changes nothing, and counts a real one', function () {
    $id = dslDraft();
    dslPresent($id, dslPresentation())->assertOk();
    $revision = dslRev();

    expect(dslPresent($id, dslPresentation())->assertOk()->json('data.draft_revision'))->toBe($revision)->and(dslRev())->toBe($revision);

    dslPresent($id, dslPresentation(['empty_display' => 'n/a']))->assertOk();
    expect(dslRev())->toBe($revision + 1);
});

it('refuses a presentation write on a stale revision (409, nothing written) and without the lock claim (423)', function () {
    $id = dslDraft();
    dslPresent($id, dslPresentation())->assertOk();
    $before = bdRows();

    $res = dslPresent($id, dslPresentation(['empty_display' => 'x']), ['draft_revision' => 1])->assertStatus(409)->assertJsonPath('error.code', 'blocks.revision_conflict');
    expect($res->json('current.data.revision'))->toBe((int) $before[0]['revision'])->and(bdRows())->toBe($before);

    config(['dashflow.tunables.timeouts.edit_lock_ttl.value' => '60']);
    test()->postJson(BD_URL."/{$id}/lock", [], BD_HEADERS)->assertOk();
    $before = bdRows();
    dslPresent($id, dslPresentation(['empty_display' => 'y']))->assertStatus(423);
    expect(bdRows())->toBe($before);
});

it('stores lexeme band limits as given and compares by value: a headline of 1E2 matches between 50 and 150', function () {
    $id = dslDraft('{"total":1E2}');
    dslPresent($id, ['bands' => [['op' => 'between', 'value' => '50', 'to' => '1.5E2', 'color' => 'info', 'icon' => 'gauge', 'label' => 'Middle']]])->assertOk();

    expect(dslConfig()['presentation']['bands'][0]['to'])->toBe('1.5E2')
        ->and(dslPreview($id)->json('data.payload.statusBadge.text'))->toBe('Middle');
});

// Story 5.1: the Filter, Sort and Top-N steps (`config.transforms`), written through PUT .../mapping/transforms and carried into the plan.
function dslRows(int $count = 36): string
{
    $rows = [];

    for ($i = 1; $i <= $count; $i++) {
        $rows[] = ['status' => $i % 3 === 0 ? 'Rejected' : 'Approved', 'revenue' => $i * 10, 'region' => 'EU'];
    }

    return json_encode(['data' => $rows]);
}

/** A Draft over `dslRows()` with the record path chosen, so the row fields are mapped. */
function dslRowsDraft(): string
{
    $id = dslDraft(dslRows());
    dslWrite($id, 'record-path', ['record_path' => '$.data[*]'])->assertOk();

    return $id;
}

function dslTransforms(string $id, ?array $steps, array $extra = [])
{
    return test()->putJson(BD_URL."/{$id}/mapping/transforms", $extra + ['transforms' => $steps, 'draft_revision' => dslRev(), 'lock_epoch' => 1], BD_HEADERS);
}

it('stores the steps in the fixed stage order, carries them into the plan and counts the rows in the preview', function () {
    $id = dslRowsDraft();
    $revision = dslRev();

    $saved = dslTransforms($id, [
        ['type' => 'top_n', 'count' => 5],
        ['type' => 'sort', 'field' => '$.data[*].revenue', 'direction' => 'desc'],
        ['type' => 'filter', 'field' => '$.data[*].status', 'operator' => 'is', 'value' => 'Approved'],
    ])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');

    expect(array_column($saved['transforms'], 'type'))->toBe(['filter', 'sort', 'top_n'])
        ->and($saved['draft_revision'])->toBe($revision + 1)
        ->and($saved['query_plan']['filter'])->toBe([['field' => '$.data[*].status', 'operator' => 'is', 'value' => 'Approved']])
        ->and($saved['query_plan']['sort'])->toBe([['field' => '$.data[*].revenue', 'direction' => 'desc']])
        ->and($saved['query_plan']['top_n'])->toBe(['count' => 5])
        ->and($saved['transform_faults'])->toBe([])
        ->and(dslConfig()['transforms'])->toEqual($saved['transforms'])
        ->and(dslGet($id)->assertOk()->json('data.transforms'))->toEqual($saved['transforms']);

    $rows = dslPreview($id)->assertOk()->json('data.rows');

    // 36 rows, 24 Approved, sorted by revenue, 5 kept.
    expect($rows)->toBe(['input' => 36, 'steps' => [24, 24, 5]]);
});

it('clears the steps and takes the stages out of the plan', function () {
    $id = dslRowsDraft();
    dslTransforms($id, [['type' => 'top_n', 'count' => 3]])->assertOk();

    $cleared = dslTransforms($id, [])->assertOk()->json('data');

    expect($cleared['transforms'])->toBe([])
        ->and($cleared['query_plan'])->not->toHaveKey('top_n')
        ->and(dslConfig())->not->toHaveKey('transforms');
});

it('refuses a malformed step naming each path, and stores nothing', function () {
    $id = dslRowsDraft();
    $revision = dslRev();

    $errors = dslTransforms($id, [
        ['type' => 'filter', 'field' => '$.data[*].status', 'operator' => 'matches', 'value' => 'x'],
        ['type' => 'top_n', 'count' => 0],
        ['type' => 'script', 'code' => 'x'],
    ])->assertStatus(422)->json('errors');

    expect(dslRev())->toBe($revision)
        ->and(dslConfig())->not->toHaveKey('transforms');

    $text = json_encode(array_keys($errors));

    expect($text)->toContain('config.transforms.0.operator')->toContain('config.transforms.1.count')->toContain('config.transforms.2');
});

it('reports a step the sample cannot satisfy as a fault and keeps it', function () {
    $id = dslRowsDraft();

    $saved = dslTransforms($id, [
        ['type' => 'filter', 'field' => '$.data[*].gone', 'operator' => 'is', 'value' => 'x'],
        ['type' => 'filter', 'field' => '$.data[*].status', 'operator' => 'gt', 'value' => '3'],
        ['type' => 'filter', 'field' => '$.data[*].revenue', 'operator' => 'gt', 'value' => 'abc'],
    ])->assertOk()->json('data');

    expect(array_column($saved['transform_faults'], 'reason'))->toBe(['field_missing', 'not_numeric', 'not_numeric'])
        ->and(array_column($saved['transform_faults'], 'index'))->toBe([0, 1, 2])
        ->and(dslGet($id)->json('data.checklist.transform_faults'))->toEqual($saved['transform_faults']);
});

it('refuses a stale revision and a Draft save that carries the steps', function () {
    $id = dslRowsDraft();
    $revision = dslRev();

    dslTransforms($id, [['type' => 'top_n', 'count' => 3]], ['draft_revision' => $revision + 7])->assertStatus(409);
    expect(dslConfig())->not->toHaveKey('transforms');

    test()->putJson(BD_URL."/{$id}", ['config' => ['transforms' => [['type' => 'top_n', 'count' => 3]]], 'draft_revision' => dslRev(), 'lock_epoch' => 1], BD_HEADERS)
        ->assertStatus(422);
});
