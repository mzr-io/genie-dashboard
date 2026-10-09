<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Blocks\Contracts\BlockCategories;
use App\Modules\Blocks\Contracts\ErrorCode as BlocksErrorCode;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 3.4 against the real PostgreSQL: the Configure Block step's config is saved on the Draft (lenient, schema_version 1) and checked
// against the Block Type's registry limits; the wizard reads one Draft; block categories are tenant data and "General" is seeded lazily
// and idempotently; the step is kept in `draft_ui_state`. Helpers come from DraftBlocksTest.
beforeEach(fn () => $this->withoutVite());

function bwDisplay(array $overrides = []): array
{
    return $overrides + [
        'width' => 3, 'height' => 'small', 'show_header' => true, 'show_subtitle' => true, 'allow_resize' => true, 'allow_minimize' => false,
        'allow_refresh' => true, 'show_footer_action' => false, 'min_width' => 2, 'max_width' => 6, 'min_height' => 'small', 'max_height' => 'medium',
        'period' => 'follow_dashboard',
    ];
}

function bwCategories(): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from block_categories order by id');
}

it('reads one Draft with its config and seeds General the first time, selected on the Block', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');

    $shown = $this->getJson(BD_URL."/{$id}", BD_HEADERS)->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    expect($shown->json('data'))->toMatchArray(['draft_id' => $id, 'category' => 'General', 'step' => 1, 'revision' => 1])
        ->and($shown->json('data.config'))->toBe(['schema_version' => 1])
        ->and(bwCategories())->toHaveCount(1)
        ->and(bwCategories()[0])->toMatchArray(['name' => 'General', 'workspace_id' => $workspace, 'archived' => false])
        ->and($shown->json('data.category_id'))->toBe(bwCategories()[0]['id']);
});

it('answers 404 for an unknown, malformed, discarded or other Workspace Draft', function () {
    $other = Cluster::workspace('Other');
    $foreign = Cluster::seedBlockVersion($other);
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');

    $this->getJson(BD_URL.'/'.Str::uuid7(), BD_HEADERS)->assertNotFound();
    $this->getJson(BD_URL."/{$foreign}", BD_HEADERS)->assertNotFound();
    $this->getJson(BD_URL.'/not-an-id', BD_HEADERS)->assertNotFound();

    $this->deleteJson(BD_URL."/{$id}", [], BD_HEADERS)->assertOk();
    $this->getJson(BD_URL."/{$id}", BD_HEADERS)->assertNotFound();
});

it('creates General once per Workspace however often categories are read, and gives it to Blocks that had none', function () {
    $workspace = Cluster::workspace('Acme');
    $old = Cluster::seedBlock($workspace);
    bdAdmin($workspace);

    foreach ([1, 2, 3] as $_) {
        $this->getJson('/api/v1/admin/blocks/categories', BD_HEADERS)->assertOk()->assertJsonPath('data.0.name', 'General')->assertJsonCount(1, 'data');
    }

    $rows = bwCategories();
    expect($rows)->toHaveCount(1)
        ->and(Cluster::rows(Cluster::superuser(), 'select category_id from blocks where id = ?', [$old])[0]['category_id'])->toBe($rows[0]['id']);

    // Another Workspace gets its own.
    $other = Cluster::workspace('Other');
    app(WorkspaceTransaction::class)->run($other, fn () => app(BlockCategories::class)->all($other));
    expect(bwCategories())->toHaveCount(2);
});

it('refuses a duplicate category name, ignoring case, in one Workspace only', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    Cluster::seedBlockCategory($a, 'Sales');

    expect(fn () => Cluster::seedBlockCategory($a, 'sales'))->toThrow(PDOException::class, 'block_categories_name_unique');
    expect(Cluster::seedBlockCategory($b, 'sales'))->toBeString();
});

it('saves the Configure Block fields in config, the category and the step, and reads them back', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');
    $category = Cluster::seedBlockCategory($workspace, 'Finance');
    $config = ['schema_version' => 1, 'description' => 'Revenue so far today.', 'icon' => 'trending-up', 'display' => bwDisplay(['width' => 4, 'height' => 'medium', 'allow_minimize' => true])];

    $saved = bdSave($id, 1, 1, ['config' => $config, 'category_id' => $category, 'step' => 2])->assertOk();

    expect($saved->json('data'))->toMatchArray(['revision' => 2, 'step' => 2, 'category' => 'Finance', 'category_id' => $category])
        ->and($saved->json('data.config'))->toEqual($config)
        ->and(json_decode(bdRows()[0]['config'], true))->toEqual($config)
        ->and((int) bdRows('draft_ui_state')[0]['step'])->toBe(2)
        ->and(bdAudits())->toHaveCount(1);

    $this->getJson(BD_URL."/{$id}", BD_HEADERS)->assertOk()->assertJsonPath('data.step', 2)->assertJsonPath('data.config.display.width', 4);

    // A save without a config keeps the stored one.
    bdSave($id, 2, 1, ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.config.description', 'Revenue so far today.');
});

it('adds the Footer action slot as optional and empty exactly when Show footer action is on', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');

    $on = bdSave($id, 1, 1, ['config' => ['display' => bwDisplay(['show_footer_action' => true])]])->assertOk();
    expect($on->json('data.config.slots.footer_action'))->toEqual(['required' => false, 'mapping' => null]);

    $off = bdSave($id, 2, 1, ['config' => ['display' => bwDisplay(['show_footer_action' => false])]])->assertOk();
    expect($off->json('data.config'))->not->toHaveKey('slots');
});

it('rejects sizes outside the registry limits with 422 and the allowed range, writing nothing', function (array $display, string $field, string $message) {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');
    $before = bdRows();

    $response = bdSave($id, 1, 1, ['config' => ['display' => bwDisplay($display)]])->assertStatus(422)
        ->assertJsonPath('error.code', BlocksErrorCode::InvalidConfig->value);

    // The error keys are dotted field paths, so the message is read from the array, not through a JSON path.
    expect($response->json('errors')["config.display.{$field}"][0])->toContain($message);

    expect(bdRows())->toBe($before);
})->with([
    'width over' => [['width' => 8], 'width', 'Use 2 to 6 columns'],
    'width under' => [['width' => 1], 'width', 'Use 2 to 6 columns'],
    'max width over' => [['max_width' => 12], 'max_width', 'Use 2 to 6 columns'],
    'height large' => [['height' => 'large'], 'height', 'small to medium'],
    'max height large' => [['max_height' => 'large'], 'max_height', 'small to medium'],
    'default outside own limits' => [['width' => 5, 'max_width' => 4], 'width', 'between the minimum and maximum'],
    'minimum above maximum' => [['min_width' => 5, 'max_width' => 3, 'width' => 4], 'min_width', 'cannot be more'],
    'width not a number' => [['width' => '3'], 'width', 'Use 2 to 6 columns'],
    'unknown height' => [['height' => 'huge'], 'height', 'small to medium'],
]);

it('rejects a wrong-typed option, an unknown period, a stray schema version and an unknown category', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');

    bdSave($id, 1, 1, ['config' => ['display' => bwDisplay(['show_header' => 'yes', 'period' => 'whenever'])]])->assertStatus(422)
        ->assertJsonValidationErrors(['config.display.show_header', 'config.display.period'], 'errors');
    bdSave($id, 1, 1, ['config' => ['schema_version' => 2]])->assertStatus(422)->assertJsonValidationErrors(['config.schema_version'], 'errors');
    bdSave($id, 1, 1, ['category_id' => (string) Str::uuid7()])->assertStatus(422)->assertJsonValidationErrors(['category_id'], 'errors');
    bdSave($id, 1, 1, ['step' => 9])->assertStatus(422);

    expect(bdRows()[0]['revision'])->toBe(1);
});

it('refuses another Workspace category and an archived one', function () {
    $other = Cluster::workspace('Other');
    $foreign = Cluster::seedBlockCategory($other, 'Foreign');
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');
    $archived = Cluster::seedBlockCategory($workspace, 'Old');
    Cluster::superuser()->prepare('UPDATE block_categories SET archived = true WHERE id = ?')->execute([$archived]);

    bdSave($id, 1, 1, ['category_id' => $foreign])->assertStatus(422)->assertJsonValidationErrors(['category_id'], 'errors');
    bdSave($id, 1, 1, ['category_id' => $archived])->assertStatus(422)->assertJsonValidationErrors(['category_id'], 'errors');
});

it('refuses a secret-valued field in config with 422 and stores nothing', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');

    bdSave($id, 1, 1, ['config' => ['display' => bwDisplay(['api_token' => 'abc123'])]])->assertStatus(422)
        ->assertJsonPath('error.code', BlocksErrorCode::SecretValuesRefused->value);

    expect(bdRows()[0]['revision'])->toBe(1)->and(json_encode(bdRows()))->not->toContain('abc123');
});

it('hides block categories of another Workspace and gives app no DELETE on them', function () {
    $a = Cluster::workspace('A');
    $b = Cluster::workspace('B');
    Cluster::seedBlockCategory($a, 'Mine');
    Cluster::seedBlockCategory($b, 'Theirs');

    app(WorkspaceTransaction::class)->run($a, function () use ($a) {
        $names = array_column(DB::select('select name from block_categories'), 'name');
        expect($names)->toBe(['Mine'])->and(app(BlockCategories::class)->all($a))->toHaveCount(2);
    });

    expect(Cluster::rows(Cluster::superuser(), "select has_table_privilege('app', 'public.block_categories', 'DELETE') as p")[0]['p'])->toBeFalse();
    expect(fn () => app(WorkspaceTransaction::class)->run($a, fn () => DB::delete('delete from block_categories')))->toThrow(QueryException::class);
});

it('saves a step on a version that has no draft_ui_state row by inserting one', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $version = Cluster::seedBlockVersion($workspace);

    bdSave($version, 1, 1, ['step' => 3])->assertOk()->assertJsonPath('data.step', 3);

    expect(bdRows('draft_ui_state'))->toHaveCount(1)->and((int) bdRows('draft_ui_state')[0]['step'])->toBe(3);
});

it('keeps stored config keys a step-1 save does not own, and replaces the ones it does', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');
    Cluster::superuser()->prepare('UPDATE block_versions SET config = ?::jsonb WHERE id = ?')->execute([json_encode([
        'schema_version' => 1, 'description' => 'Old', 'query_plan' => ['v' => 1], 'slots' => ['headline_value' => ['mapping' => 'x'], 'footer_action' => ['required' => false, 'mapping' => null]],
    ]), $id]);

    $saved = bdSave($id, 1, 1, ['config' => ['description' => 'New', 'display' => bwDisplay()]])->assertOk();

    expect($saved->json('data.config.query_plan'))->toEqual(['v' => 1])
        ->and($saved->json('data.config.slots'))->toEqual(['headline_value' => ['mapping' => 'x']])
        ->and($saved->json('data.config.description'))->toBe('New');
});

it('lets a Draft whose category was archived save with it unchanged, but not move to an archived one', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');
    $category = Cluster::seedBlockCategory($workspace, 'Old');
    bdSave($id, 1, 1, ['category_id' => $category])->assertOk();
    Cluster::superuser()->prepare('UPDATE block_categories SET archived = true WHERE id = ?')->execute([$category]);

    bdSave($id, 2, 1, ['category_id' => $category])->assertOk()->assertJsonPath('data.category_id', $category);
});

it('carries the stored config in a 409 body', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');
    bdSave($id, 1, 1, ['config' => ['description' => 'Kept']])->assertOk();

    bdSave($id, 1, 1)->assertStatus(409)->assertJsonPath('current.data.config.description', 'Kept');
});

it('requires blocks.edit to read a Draft or the categories', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace, []);

    $this->getJson(BD_URL.'/'.Str::uuid7(), BD_HEADERS)->assertForbidden();
    $this->getJson('/api/v1/admin/blocks/categories', BD_HEADERS)->assertForbidden();
    expect(bwCategories())->toBe([]);
});

// ---- Story 3.5: autosave ----

it('stores the wizard state in draft_ui_state beside the step, reads it back, and keeps it out of the config and the audit', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');
    $auditsBefore = count(bdAudits());

    $state = ['acknowledged_warnings' => ['type-mismatch:headline_value', 'type-mismatch:headline_value', 'empty-sample'], 'slot_statuses' => ['headline_value' => 'review', 'status_badge' => 'unmapped']];
    $saved = bdSave($id, 1, 1, ['step' => 3, 'ui_state' => $state + ['extra' => 'ignored']])->assertOk();

    expect($saved->json('data.ui_state'))->toEqual(['acknowledged_warnings' => ['type-mismatch:headline_value', 'empty-sample'], 'slot_statuses' => ['headline_value' => 'review', 'status_badge' => 'unmapped']])
        ->and($saved->json('data.step'))->toBe(3)
        ->and(json_decode(bdRows('draft_ui_state')[0]['state'], true))->toHaveKeys(['acknowledged_warnings', 'slot_statuses'])->not->toHaveKey('extra')
        ->and(json_decode(bdRows()[0]['config'], true))->toBe(['schema_version' => 1])
        ->and(count(bdAudits()))->toBe($auditsBefore);

    $this->getJson(BD_URL."/{$id}", BD_HEADERS)->assertOk()->assertJsonPath('data.ui_state.slot_statuses.headline_value', 'review');

    // A later save without ui_state leaves it as it is; one with an empty state clears it.
    bdSave($id, 2, 1, ['step' => 3])->assertOk()->assertJsonPath('data.ui_state.slot_statuses.status_badge', 'unmapped');
    bdSave($id, 3, 1, ['ui_state' => ['acknowledged_warnings' => [], 'slot_statuses' => []]])->assertOk()->assertJsonPath('data.ui_state.acknowledged_warnings', []);
});

it('refuses a secret-valued key in ui_state with 422 and stores nothing', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');

    bdSave($id, 1, 1, ['ui_state' => ['slot_statuses' => ['api_key' => 'abc123'], 'password' => 'hunter2']])
        ->assertStatus(422)->assertJsonPath('error.code', BlocksErrorCode::SecretValuesRefused->value);

    expect((int) bdRows()[0]['revision'])->toBe(1)
        ->and(bdRows('draft_ui_state')[0]['state'])->toBe('{}');
});

it('refuses a malformed ui_state with 422', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');

    bdSave($id, 1, 1, ['ui_state' => ['acknowledged_warnings' => [['x']]]])->assertStatus(422);
    bdSave($id, 1, 1, ['ui_state' => 'text'])->assertStatus(422);
    expect((int) bdRows()[0]['revision'])->toBe(1);
});

it('answers 409 blocks.draft_published when the version is no longer a Draft, writes nothing, and keeps a discarded Draft a 404', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bdCreate()->assertCreated()->json('data.draft_id');
    $gone = bdCreate(['name' => 'Other'])->assertCreated()->json('data.draft_id');
    $this->deleteJson(BD_URL."/{$gone}", [], BD_HEADERS)->assertOk();

    // The published state arrives with Story 3.19; until then the version is moved past Draft by hand, and the constraint put back.
    $db = Cluster::superuser();
    $db->exec('ALTER TABLE block_versions DROP CONSTRAINT block_versions_state_check');

    try {
        $db->prepare("UPDATE block_versions SET state = 'published' WHERE id = ?")->execute([$id]);

        bdSave($id, 1, 1)->assertStatus(409)->assertJsonPath('error.code', BlocksErrorCode::DraftPublished->value);
        expect((int) Cluster::rows($db, 'select revision from block_versions where id = ?', [$id])[0]['revision'])->toBe(1);
        bdSave($gone, 1, 1)->assertNotFound();
        bdSave((string) Str::uuid7(), 1, 1)->assertNotFound();
    } finally {
        $db->prepare("UPDATE block_versions SET state = 'draft' WHERE id = ?")->execute([$id]);
        $db->exec("ALTER TABLE block_versions ADD CONSTRAINT block_versions_state_check CHECK (state IN ('draft', 'discarded'))");
    }
});

it('shares the autosave debounce with the page only when it is a whole positive number of milliseconds', function (mixed $value, ?int $expected) {
    config(['dashflow.tunables.timeouts.autosave_debounce.value' => $value]);
    $share = (new HandleInertiaRequests)->share(Request::create('/admin/blocks/create'));

    expect($share['autosave'])->toBe(['debounce_ms' => $expected]);
})->with([[null, null], ['', null], ['800', 800], [1200, 1200], ['0', null], ['-5', null], ['1s', null], ['08', null]]);
