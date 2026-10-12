<?php

use App\Modules\Access\Application\RegisterAccessSubjectRow;
use App\Modules\Access\Contracts\ErrorCode;
use App\Modules\Access\Contracts\RegisterAccessSubject;
use App\Modules\Blocks\Application\ActivatePreparedVersions;
use App\Modules\Blocks\Application\ActivateVersion;
use App\Modules\Blocks\Application\PreviewDraft;
use App\Modules\Blocks\Contracts\ErrorCode as BlocksErrorCode;
use App\Modules\Connector\Contracts\HostResolver;
use App\Modules\Connector\Infrastructure\CurlClient;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use Illuminate\Support\Facades\Cache;
use Tests\Database\Support\Cluster;
use Tests\Unit\Support\FakeCurl;
use Tests\Unit\Support\FakeResolver;

// Story 3.19 against the real PostgreSQL: POST /api/v1/admin/blocks/drafts/{draft}/publish. Tx1 freezes the validated Draft (`prepared`), Tx2 activates it
// (`published`); the trigger makes the version immutable; the maintenance job activates a version left prepared. Helpers (bd*, dp*, sf*, pv*) come from
// DraftBlocksTest, DraftPreviewTest, SampleFetchTest and DraftValidationTest.
const PP_BODY = '{"data":{"revenue":12345.50,"label":"Revenue"}}';

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
        'dashflow.tunables.sync.precompute_timeout.value' => '60',
        'dashflow.sample_fetch.membership_limit.value' => '100',
        'dashflow.sample_fetch.workspace_limit.value' => null,
        'dashflow.sample_fetch.window.value' => '60',
        'dashflow.preview.membership_limit.value' => '100',
        'dashflow.preview.window_seconds.value' => '60',
        'dashflow.preview.max_extracted_values.value' => '1000',
        'dashflow.preview.max_evaluations.value' => '1000',
        'dashflow.preview.max_wall_ms.value' => '5000',
    ]);

    $this->curl = new FakeCurl;
    app()->instance(HostResolver::class, new FakeResolver(['api.example.com' => ['93.184.216.34']]));
    app()->instance(CurlClient::class, $this->curl);
});

afterEach(function () {
    @unlink($this->keyFile);
    @unlink($this->digestFile);
    PreviewDraft::forget();
});

/**
 * A signed-in Admin with the given permissions, a Data Source, an Endpoint and a Draft mapped over it, at revision 2, with a pasted sample.
 * Returns [workspace, membership, data source, endpoint, draft].
 *
 * @param  list<string>  $permissions
 * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
 */
function ppWorld(array $permissions = ['data_sources.manage', 'blocks.edit', 'blocks.publish']): array
{
    $workspace = Cluster::workspace('Acme');
    [, $membership] = sfAdmin($workspace, $permissions);
    $source = Cluster::seedDataSource($workspace, 'Sales API', 'api.example.com');
    $endpoint = sfEndpoint($source, ['path' => '/revenue', 'params' => [['name' => 'region', 'binding' => 'fixed', 'value' => 'EU']], 'headers' => []]);
    $draft = bdCreate()->assertCreated()->json('data.draft_id');

    pvConfigure($draft, dpPlan(['rev' => '$.data.revenue', 'name' => '$.data.label'], ['headline_value' => 'rev', 'kpi_label' => 'name']), $source, $endpoint);
    dpPaste($draft, PP_BODY);

    return [$workspace, $membership, $source, $endpoint, $draft];
}

/** Validates the Draft against the live (fake) API: a passed report for its current revision. */
function ppValidate(string $draft): void
{
    test()->curl->queue = [FakeCurl::answer(200, PP_BODY)];
    pvRun($draft);
    expect(pvReport($draft)->json('data.can_publish'))->toBeTrue();
}

function ppPublish(string $draft, array $overrides = [])
{
    return test()->postJson(BD_URL."/{$draft}/publish", $overrides + ['lock_epoch' => 1], BD_HEADERS);
}

function ppVersion(string $draft): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from block_versions where id = ?', [$draft])[0];
}

/** @return list<array<string, mixed>> */
function ppEvents(string $type): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from outbox_events where type = ? order by subject_seq, occurred_at', [$type]);
}

function ppAudits(string $action): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from audit_events where action = ? order by occurred_at, id', [$action]);
}

it('publishes a validated Draft: prepared then published, frozen, current version switched, Access subject All users, samples ended, audited', function () {
    [$workspace, $membership, $source, $endpoint, $draft] = ppWorld();
    ppValidate($draft);
    $blockId = ppVersion($draft)['block_id'];
    $report = pvReports()[0];

    $response = ppPublish($draft)->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    expect($response->json('data'))->toMatchArray(['version_id' => $draft, 'block_id' => $blockId, 'name' => 'Revenue', 'type' => 'kpi-card', 'version' => '1.0', 'state' => 'published', 'category' => 'General']);

    $row = ppVersion($draft);
    expect($row)->toMatchArray(['state' => 'published', 'version' => '1.0', 'revision' => 2, 'endpoint_id' => $endpoint, 'endpoint_revision' => $report['endpoint_revision'], 'published_by_membership_id' => $membership])
        ->and($row['prepared_at'])->not->toBeNull()
        ->and($row['published_at'])->not->toBeNull();

    // The frozen document: the contract of the version.
    $config = json_decode($row['config'], true);
    expect($config)->toMatchArray(['schema_version' => 1, 'type_key' => 'kpi-card', 'contract_version' => 1])
        ->and(array_keys($config))->toEqualCanonicalizing(['schema_version', 'type_key', 'contract_version', 'source', 'endpoint', 'fields', 'slot_map', 'query_plan', 'presentation', 'chrome', 'sizes', 'period', 'refresh_interval'])
        ->and($config['source'])->toMatchArray(['data_source_id' => $source, 'endpoint_id' => $endpoint])
        ->and($config['endpoint'])->toBe(['id' => $endpoint, 'revision' => (int) $report['endpoint_revision']])
        ->and($config['query_plan']['shape'])->toEqual(['headline_value' => 'rev', 'kpi_label' => 'name'])
        ->and(array_column($config['fields']['items'], 'path'))->toBe(['$.data.revenue', '$.data.label'])
        ->and($config['sizes'])->toHaveKeys(['min_width', 'max_width', 'width', 'min_height', 'max_height', 'height']);

    // The Block's current version and its Access subject (All users), in the same commit.
    $block = Cluster::rows(Cluster::superuser(), 'select * from blocks where id = ?', [$blockId])[0];
    $subjects = Cluster::rows(Cluster::superuser(), 'select * from access_subjects');
    expect($block['current_version_id'])->toBe($draft)
        ->and($subjects)->toHaveCount(1)
        ->and($subjects[0])->toMatchArray(['workspace_id' => $workspace, 'subject_type' => 'block', 'subject_id' => $blockId, 'mode' => 'all'])
        ->and($subjects[0]['visible'])->toBeTrue();

    // The Draft's sample is unreadable at once (ended) and the purge, queued after the commit, deletes the row.
    expect(sfCount('draft_samples'))->toBe(0);

    // `prepared` then `published`, in that order, once each; the audit event is the second transaction's.
    $prepared = ppEvents('blocks.version.prepared');
    $published = ppEvents('blocks.version.published');
    expect($prepared)->toHaveCount(1)
        ->and($published)->toHaveCount(1)
        ->and($prepared[0]['subject'])->toBe('block_version:'.$draft)
        ->and(json_decode($prepared[0]['data'], true))->toMatchArray(['version_id' => $draft, 'block_id' => $blockId, 'endpoint_id' => $endpoint])
        ->and(ppAudits('blocks.version.prepared'))->toBe([]);

    $audits = ppAudits('blocks.version.published');
    expect($audits)->toHaveCount(1)
        ->and($audits[0]['actor'])->toBe($membership)
        ->and($audits[0]['security'])->toBeFalse()
        ->and(json_decode($audits[0]['after_state'], true))->toMatchArray(['version_id' => $draft, 'block_id' => $blockId, 'revision' => 2, 'version_major' => 1, 'version_minor' => 0, 'endpoint_id' => $endpoint]);

    // No draft is left behind: the Draft list is empty and the wizard's read of it is gone.
    $this->getJson(BD_URL, BD_HEADERS)->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson(BD_URL."/{$draft}", BD_HEADERS)->assertNotFound();

    // Published blocks lists the row.
    $list = $this->getJson('/api/v1/admin/blocks/published', BD_HEADERS)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0'))->toMatchArray(['block_id' => $blockId, 'version_id' => $draft, 'name' => 'Revenue', 'type' => 'kpi-card', 'category' => 'General', 'version' => '1.0', 'status' => 'published', 'owner_membership_id' => $membership])
        ->and($list->json('meta.total'))->toBe(1);
});

it('refuses Publish without blocks.publish: 403, an audited denial, nothing changes, and Save draft still works', function () {
    [, , , , $draft] = ppWorld(['data_sources.manage', 'blocks.edit']);
    ppValidate($draft);
    Cache::flush();

    $response = ppPublish($draft)->assertForbidden();

    expect($response->json('error.code'))->toBe(ErrorCode::NotAuthorized->value)
        ->and(ppVersion($draft)['state'])->toBe('draft')
        ->and(ppEvents('blocks.version.prepared'))->toBe([])
        ->and(Cluster::rows(Cluster::superuser(), "select * from audit_events where action = 'access.admin.denied'"))->toHaveCount(1)
        ->and(json_decode(Cluster::rows(Cluster::superuser(), "select after_state from audit_events where action = 'access.admin.denied'")[0]['after_state'], true))
        ->toMatchArray(['route' => 'api.admin.blocks.drafts.publish', 'permission' => 'blocks.publish']);

    bdSave($draft, 2, 1, ['name' => 'Still editable'])->assertOk();
});

it('answers 409 and changes nothing when there is no report, the report is stale, or it failed', function () {
    [, , , , $draft] = ppWorld();

    // Never validated.
    $missing = ppPublish($draft)->assertStatus(409);
    expect($missing->json('error.code'))->toBe(BlocksErrorCode::PublishNotReady->value)
        ->and($missing->json('reason'))->toBe('report_missing')
        ->and(ppVersion($draft)['state'])->toBe('draft');

    // Validated, then an autosave: the report is for an earlier revision.
    ppValidate($draft);
    bdSave($draft, 2, 1, ['name' => 'Edited after validation'])->assertOk();
    $stale = ppPublish($draft)->assertStatus(409);
    expect($stale->json('error.code'))->toBe(BlocksErrorCode::PublishNotReady->value)
        ->and($stale->json('reason'))->toBe('report_stale');

    // A failed report.
    $this->curl->queue = [FakeCurl::answer(200, '{"data":{"revenue":1}}')];
    pvRun($draft);
    $failed = ppPublish($draft)->assertStatus(409);
    expect($failed->json('reason'))->toBe('report_failed');

    $row = ppVersion($draft);
    expect($row['state'])->toBe('draft')
        ->and($row['prepared_at'])->toBeNull()
        ->and($row['revision'])->toBe(3)
        ->and(sfCount('access_subjects'))->toBe(0)
        ->and(ppEvents('blocks.version.prepared'))->toBe([])
        ->and(ppAudits('blocks.version.published'))->toBe([]);
});

it('refuses a report of an Endpoint that has changed since', function () {
    [, , $source, $endpoint, $draft] = ppWorld();
    ppValidate($draft);

    // The Endpoint is revised after the validation: the report was made against another revision.
    epUpdate($source, $endpoint, 1, ['path' => '/revenue-v2', 'params' => [['name' => 'region', 'binding' => 'fixed', 'value' => 'EU']], 'headers' => []])->assertOk();

    $response = ppPublish($draft)->assertStatus(409);

    expect($response->json('reason'))->toBe('report_stale')
        ->and(ppVersion($draft)['state'])->toBe('draft');
});

it('needs the edit-lock claim of a save: a stale epoch is a 423 and nothing changes', function () {
    [, , , , $draft] = ppWorld();
    ppValidate($draft);

    $response = ppPublish($draft, ['lock_epoch' => 9])->assertStatus(423);

    expect($response->json('error.code'))->toBe(PlatformErrorCode::EditLockLost->value)
        ->and(ppVersion($draft)['state'])->toBe('draft');
    ppPublish($draft, ['lock_epoch' => 'x'])->assertStatus(422);
});

it('gives an autosave after the freeze a 409 draft_published and leaves the immutable version untouched', function () {
    [, , , , $draft] = ppWorld();
    ppValidate($draft);
    ppPublish($draft)->assertOk();
    $before = ppVersion($draft);

    $save = bdSave($draft, 2, 1, ['name' => 'Too late', 'config' => ['description' => 'Changed']])->assertStatus(409);

    expect($save->json('error.code'))->toBe(BlocksErrorCode::DraftPublished->value)
        ->and(ppVersion($draft))->toBe($before);

    // Every other write of the Draft says the same, and a validation is refused.
    pvStart($draft)->assertStatus(409)->assertJsonPath('error.code', BlocksErrorCode::DraftPublished->value);
    dpPreview($draft)->assertStatus(409)->assertJsonPath('error.code', BlocksErrorCode::DraftPublished->value);
});

it('lets exactly one of two publishes win: the other is a 409 with no second version and no second audit event', function () {
    [, , , , $draft] = ppWorld();
    ppValidate($draft);

    ppPublish($draft)->assertOk();
    $second = ppPublish($draft)->assertStatus(409);

    expect($second->json('error.code'))->toBe(BlocksErrorCode::DraftPublished->value)
        ->and(ppAudits('blocks.version.published'))->toHaveCount(1)
        ->and(ppEvents('blocks.version.prepared'))->toHaveCount(1)
        ->and(ppEvents('blocks.version.published'))->toHaveCount(1)
        ->and(sfCount('access_subjects'))->toBe(1)
        ->and(sfCount('block_versions'))->toBe(1);
});

/**
 * Makes the second publish transaction fail (the Access subject cannot be written) until `ppAccessBack()`. The route keeps its controller between
 * requests, so the stand-in stays bound and a flag decides whether it fails.
 */
function ppAccessDown(): void
{
    $GLOBALS['pp_access_down'] = true;
    $real = new RegisterAccessSubjectRow;

    app()->instance(RegisterAccessSubject::class, new class($real) implements RegisterAccessSubject
    {
        public function __construct(private readonly RegisterAccessSubject $real) {}

        public function register(string $workspaceId, string $type, string $subjectId): bool
        {
            if ($GLOBALS['pp_access_down'] ?? false) {
                throw new RuntimeException('access is down');
            }

            return $this->real->register($workspaceId, $type, $subjectId);
        }
    });
}

function ppAccessBack(): void
{
    $GLOBALS['pp_access_down'] = false;
}

it('answers publish_pending when activation fails, leaves the version prepared, and a retry of Publish completes it', function () {
    [, $membership, , , $draft] = ppWorld();
    ppValidate($draft);
    ppAccessDown();

    $failed = ppPublish($draft)->assertStatus(503);

    expect($failed->json('error.code'))->toBe(BlocksErrorCode::PublishPending->value);
    $row = ppVersion($draft);
    expect($row['state'])->toBe('prepared')
        ->and($row['prepared_by_membership_id'])->toBe($membership)
        ->and($row['published_at'])->toBeNull()
        ->and(sfCount('access_subjects'))->toBe(0)
        ->and(ppEvents('blocks.version.prepared'))->toHaveCount(1)
        ->and(ppEvents('blocks.version.published'))->toBe([]);

    // The save of a prepared version is refused like a published one.
    bdSave($draft, 2, 1)->assertStatus(409)->assertJsonPath('error.code', BlocksErrorCode::DraftPublished->value);

    // A retry while it is still failing is the same answer, not a 409 "published".
    ppPublish($draft)->assertStatus(503)->assertJsonPath('error.code', BlocksErrorCode::PublishPending->value);

    ppAccessBack();
    $done = ppPublish($draft)->assertOk();

    expect($done->json('data'))->toMatchArray(['version_id' => $draft, 'state' => 'published', 'version' => '1.0'])
        ->and(ppVersion($draft)['state'])->toBe('published')
        ->and(sfCount('access_subjects'))->toBe(1)
        ->and(ppAudits('blocks.version.published'))->toHaveCount(1)
        ->and(ppEvents('blocks.version.prepared'))->toHaveCount(1)
        ->and(ppEvents('blocks.version.published'))->toHaveCount(1);
});

it('activates a version left prepared from the maintenance job once precompute_timeout has passed, as the member who published', function () {
    [$workspace, $membership, , , $draft] = ppWorld();
    ppValidate($draft);
    ppAccessDown();
    ppPublish($draft)->assertStatus(503);
    ppAccessBack();

    // Not yet old enough: nothing is activated. With the setting unset nothing is activated either (no number is invented).
    expect(app(ActivatePreparedVersions::class)->run($workspace))->toBe(0);
    config(['dashflow.tunables.sync.precompute_timeout.value' => null]);
    // Aged by the superuser with triggers off, in one batch: no role may change `prepared_at` of a prepared version.
    Cluster::superuser()->exec("BEGIN; SET LOCAL session_replication_role = replica; UPDATE block_versions SET prepared_at = now() - interval '1 hour' WHERE id = '{$draft}'; COMMIT;");
    expect(app(ActivatePreparedVersions::class)->run($workspace))->toBe(0)
        ->and(ppVersion($draft)['state'])->toBe('prepared');

    config(['dashflow.tunables.sync.precompute_timeout.value' => '60']);
    expect(app(ActivatePreparedVersions::class)->run($workspace))->toBe(1);

    $activated = ppVersion($draft);
    $block = Cluster::rows(Cluster::superuser(), 'select current_version_id from blocks where id = ?', [$activated['block_id']])[0];
    $audits = ppAudits('blocks.version.published');
    expect($activated['state'])->toBe('published')
        ->and($activated['published_by_membership_id'])->toBe($membership)
        ->and($block['current_version_id'])->toBe($draft)
        ->and(sfCount('access_subjects'))->toBe(1)
        ->and($audits)->toHaveCount(1)
        ->and($audits[0]['actor'])->toBe($membership)
        ->and(ppEvents('blocks.version.published'))->toHaveCount(1);

    // A second run finds nothing.
    expect(app(ActivatePreparedVersions::class)->run($workspace))->toBe(0)
        ->and(ppAudits('blocks.version.published'))->toHaveCount(1);
});

it('emits one published audit event and one outbox event however often ActivateVersion runs', function () {
    [$workspace, $membership, , , $draft] = ppWorld();
    ppValidate($draft);
    ppPublish($draft)->assertOk();
    $first = ppVersion($draft)['published_at'];

    $again = app(ActivateVersion::class)->activate($workspace, $draft);
    app(ActivateVersion::class)->activate($workspace, $draft, $membership);

    expect($again->versionId)->toBe($draft)
        ->and(ppVersion($draft)['published_at'])->toBe($first)
        ->and(ppAudits('blocks.version.published'))->toHaveCount(1)
        ->and(ppEvents('blocks.version.published'))->toHaveCount(1)
        ->and(sfCount('access_subjects'))->toBe(1);
});

it('does not take current_version_id back when an older prepared version is activated after a newer one', function () {
    [$workspace, , , , $draft] = ppWorld();
    ppValidate($draft);
    ppPublish($draft)->assertOk();
    $blockId = ppVersion($draft)['block_id'];

    // An older version of the same Block, left prepared, is activated late (as the job would).
    $older = Cluster::seedBlockVersion($workspace, ['block_id' => $blockId, 'state' => 'prepared', 'version' => '0.9', 'prepared_at' => '2020-01-01 10:00:00+00']);
    app(ActivateVersion::class)->activate($workspace, $older);

    $block = Cluster::rows(Cluster::superuser(), 'select current_version_id from blocks where id = ?', [$blockId])[0];
    expect(ppVersion($older)['state'])->toBe('published')
        ->and($block['current_version_id'])->toBe($draft);

    // A newer one does take it.
    $newer = Cluster::seedBlockVersion($workspace, ['block_id' => $blockId, 'state' => 'prepared', 'version' => '1.1', 'prepared_at' => '2099-01-01 10:00:00+00']);
    app(ActivateVersion::class)->activate($workspace, $newer);

    expect(Cluster::rows(Cluster::superuser(), 'select current_version_id from blocks where id = ?', [$blockId])[0]['current_version_id'])->toBe($newer);
});

it('refuses with fields_unresolved when the sample is gone and a field has no role or type, and freezes nothing', function () {
    [, , , , $draft] = ppWorld();
    ppValidate($draft);
    Cluster::superuser()->prepare('DELETE FROM draft_samples')->execute();
    PreviewDraft::forget();

    $response = ppPublish($draft)->assertStatus(409);

    expect($response->json('error.code'))->toBe(BlocksErrorCode::PublishNotReady->value)
        ->and($response->json('reason'))->toBe('fields_unresolved')
        ->and(ppVersion($draft)['state'])->toBe('draft')
        ->and(ppEvents('blocks.version.prepared'))->toBe([]);
});

it('refuses with block_type_unavailable, not stale, when the pinned Block Type is not registered', function () {
    [, , , , $draft] = ppWorld();
    ppValidate($draft);
    Cluster::superuser()->prepare('UPDATE blocks SET contract_version = 99 WHERE id = ?')->execute([ppVersion($draft)['block_id']]);

    $response = ppPublish($draft)->assertStatus(409);

    expect($response->json('reason'))->toBe('block_type_unavailable')
        ->and(ppVersion($draft)['state'])->toBe('draft');
});

it('answers 409 draft_published when another version of the Block already holds the version label', function () {
    [$workspace, , , , $draft] = ppWorld();
    ppValidate($draft);
    Cluster::seedBlockVersion($workspace, ['block_id' => ppVersion($draft)['block_id'], 'state' => 'published', 'version' => '1.0', 'prepared_at' => '2020-01-01 10:00:00+00', 'published_at' => '2020-01-01 10:00:00+00']);

    $response = ppPublish($draft)->assertStatus(409);

    expect($response->json('error.code'))->toBe(BlocksErrorCode::DraftPublished->value)
        ->and(ppVersion($draft)['state'])->toBe('draft')
        ->and(ppEvents('blocks.version.prepared'))->toBe([]);
});

it('needs the lock token when the edit lock is on: a token that does not hold it is a 423 and the Draft stays a Draft', function () {
    [, , , , $draft] = ppWorld();
    ppValidate($draft);
    config(['dashflow.tunables.timeouts.edit_lock_ttl.value' => '60']);
    $lock = $this->postJson(BD_URL."/{$draft}/lock", [], BD_HEADERS)->assertOk()->json('data');

    ppPublish($draft)->assertStatus(423)->assertJsonPath('error.code', PlatformErrorCode::EditLockLost->value);
    ppPublish($draft, ['lock_token' => str_repeat('a', 32)])->assertStatus(423);
    expect(ppVersion($draft)['state'])->toBe('draft')
        ->and(ppEvents('blocks.version.prepared'))->toBe([]);

    ppPublish($draft, ['lock_token' => $lock['token']])->assertOk();
    expect(ppVersion($draft)['state'])->toBe('published');
});

it('freezes the Admin\'s own choices: roles and types, chrome, presentation and sizes, with the Block Type defaults where nothing was chosen', function () {
    [, , , , $draft] = ppWorld();
    $presentation = ['bands' => [['op' => '>=', 'value' => '100', 'color' => 'success', 'icon' => 'trending-up', 'label' => 'On target']], 'empty_display' => '-'];
    $patch = [
        'description' => 'Daily revenue',
        'icon' => 'trending-up',
        'display' => ['width' => 4, 'height' => 'medium', 'show_header' => false, 'period' => 'own_selector'],
        'field_roles' => ['$.data.revenue' => 'measure'],
        'field_types' => ['$.data.revenue' => 'number'],
        'presentation' => $presentation,
    ];
    Cluster::superuser()->prepare('UPDATE block_versions SET config = config || ?::jsonb WHERE id = ?')->execute([json_encode($patch), $draft]);
    ppValidate($draft);

    ppPublish($draft)->assertOk();
    $config = json_decode(ppVersion($draft)['config'], true);
    $items = array_column($config['fields']['items'], null, 'path');

    expect($items['$.data.revenue'])->toMatchArray(['alias' => 'rev', 'role' => 'measure', 'type' => 'number'])
        ->and($items['$.data.label']['role'])->toBeString()
        ->and($items['$.data.label']['type'])->toBeString()
        ->and($config['fields']['record_path'])->toBeNull()
        ->and($config['chrome'])->toEqual(['description' => 'Daily revenue', 'icon' => 'trending-up', 'show_header' => false])
        ->and($config['presentation'])->toEqual($presentation)
        ->and($config['period'])->toBe('own_selector')
        ->and($config['sizes'])->toEqual(['min_width' => 2, 'max_width' => 6, 'width' => 4, 'min_height' => 'small', 'max_height' => 'medium', 'height' => 'medium'])
        ->and($config['refresh_interval'])->toBeNull();
});

it('rejects every UPDATE of a version but a Draft\'s content edits, draft to prepared and prepared to published', function () {
    [$workspace, , , , $draft] = ppWorld();
    ppValidate($draft);
    $superuser = Cluster::superuser();
    $update = function (string $sql, array $params = []) use ($superuser): ?string {
        try {
            $superuser->prepare($sql)->execute($params);

            return null;
        } catch (PDOException $e) {
            return (string) $e->getCode();
        }
    };

    // A Draft: content edits and the freeze are allowed; published straight from draft is not.
    expect($update("UPDATE block_versions SET name = 'Edited', revision = revision + 1 WHERE id = ?", [$draft]))->toBeNull()
        ->and($update("UPDATE block_versions SET state = 'published', prepared_at = now(), published_at = now() WHERE id = ?", [$draft]))->toBe('23514');

    // Prepared: only published, and unchanged.
    expect($update("UPDATE block_versions SET state = 'prepared', prepared_at = now() WHERE id = ?", [$draft]))->toBeNull()
        ->and($update("UPDATE block_versions SET name = 'Changed' WHERE id = ?", [$draft]))->toBe('23514')
        ->and($update("UPDATE block_versions SET config = '{\"schema_version\": 1}' WHERE id = ?", [$draft]))->toBe('23514')
        ->and($update("UPDATE block_versions SET state = 'draft', prepared_at = NULL WHERE id = ?", [$draft]))->toBe('23514')
        ->and($update("UPDATE block_versions SET state = 'discarded', discarded_at = now() WHERE id = ?", [$draft]))->toBe('23514')
        ->and($update("UPDATE block_versions SET state = 'published', published_at = now(), name = 'Renamed' WHERE id = ?", [$draft]))->toBe('23514')
        ->and($update("UPDATE block_versions SET state = 'published', published_at = now() WHERE id = ?", [$draft]))->toBeNull();

    // Published: nothing at all.
    expect($update('UPDATE block_versions SET updated_at = now() WHERE id = ?', [$draft]))->toBe('23514')
        ->and($update("UPDATE block_versions SET name = 'Changed' WHERE id = ?", [$draft]))->toBe('23514')
        ->and($update("UPDATE block_versions SET state = 'discarded', discarded_at = now() WHERE id = ?", [$draft]))->toBe('23514')
        ->and($update("UPDATE block_versions SET state = 'draft', published_at = NULL, prepared_at = NULL WHERE id = ?", [$draft]))->toBe('23514')
        ->and(ppVersion($draft)['state'])->toBe('published');

    // The version label of a published Block is unique among its prepared and published versions; a second Draft for the Block can exist.
    $blockId = ppVersion($draft)['block_id'];
    $next = Cluster::seedBlockVersion($workspace, ['block_id' => $blockId, 'version' => '1.1']);
    expect(ppVersion($next)['state'])->toBe('draft');
    expect(fn () => Cluster::seedBlockVersion($workspace, ['block_id' => $blockId, 'state' => 'published', 'version' => '1.0', 'prepared_at' => '2026-10-10 10:00:00+00', 'published_at' => '2026-10-10 10:00:00+00']))
        ->toThrow(PDOException::class);
});

it('does not list a Draft or another Workspace\'s blocks as published', function () {
    [, , , , $draft] = ppWorld();
    ppValidate($draft);

    $this->getJson('/api/v1/admin/blocks/published', BD_HEADERS)->assertOk()->assertJsonPath('meta.total', 0);

    ppPublish($draft)->assertOk();

    $other = Cluster::workspace('Beta');
    bdAdmin($other, ['blocks.edit', 'blocks.publish'], 'bea@example.test');
    $cross = ppPublish($draft);
    expect($cross->status())->toBe(404, $cross->getContent());
    bdAdmin($other, ['blocks.edit'], 'bob@example.test');
    $this->getJson('/api/v1/admin/blocks/published', BD_HEADERS)->assertOk()->assertJsonPath('meta.total', 0);
});
