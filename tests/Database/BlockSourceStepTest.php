<?php

use App\Modules\Blocks\Contracts\ErrorCode as BlocksErrorCode;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 3.7 against the real PostgreSQL: wizard Step 2 stores the Data Source, Endpoint, Fixed-parameter values, refresh interval and default
// date range in the Draft's `config.source`. The server pins `endpoint_revision_id` and `data_source_revision` itself, refuses an Endpoint that
// does not belong to the Data Source in the Workspace, offers only the System-settings refresh intervals, and refuses secret-valued keys.
// Helpers (bd*) come from DraftBlocksTest.
beforeEach(fn () => $this->withoutVite());

/** An Endpoint with the given parameters ([name, binding, value, kind]); returns [endpoint ID, revision ID]. */
function bsEndpoint(string $workspace, string $dataSource, array $params = [], string $method = 'GET', string $path = '/api/v2/finance/revenue'): array
{
    $id = (string) Str::uuid7();
    $revision = (string) Str::uuid7();
    $membership = (string) Str::uuid7();
    $pdo = Cluster::superuser();
    $json = json_encode(array_map(fn (array $p): array => ['name' => $p[0], 'binding' => $p[1], 'value' => $p[2], 'kind' => $p[3] ?? 'query'], $params));

    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO endpoints (id, workspace_id, data_source_id, revision, current_revision_id, created_by_membership_id, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?, now(), now())')
        ->execute([$id, $workspace, $dataSource, $revision, $membership]);
    $pdo->prepare("INSERT INTO endpoint_revisions (id, workspace_id, endpoint_id, revision, method, path_template, path_ast, params, headers, body_template, read_only_query, created_at, created_by_membership_id) VALUES (?, ?, ?, 1, ?, ?, '[]'::jsonb, ?::jsonb, '[]'::jsonb, NULL, ?, now(), ?)")
        ->execute([$revision, $workspace, $id, $method, $path, $json, $method === 'POST' ? 'true' : 'false', $membership]);
    $pdo->commit();

    return [$id, $revision];
}

/** A Draft of the signed-in Admin; returns its ID. */
function bsDraft(): string
{
    return bdCreate()->assertCreated()->json('data.draft_id');
}

function bsSave(string $id, array $source, int $revision = 1, array $extra = [])
{
    return bdSave($id, $revision, 1, ['config' => ['schema_version' => 1, 'source' => $source]] + $extra);
}

it('stores the selection and pins the Endpoint revision and the Data Source revision on the server', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Finance API');
    [$endpoint, $revision] = bsEndpoint($workspace, $source, [['from', 'date_range_from', null], ['region', 'fixed', 'emea']]);
    $id = bsDraft();

    // The client's own pins are ignored.
    $saved = bsSave($id, [
        'data_source_id' => $source, 'endpoint_id' => $endpoint, 'bindings' => ['region' => 'apac'],
        'endpoint_revision_id' => (string) Str::uuid7(), 'data_source_revision' => 99, 'sample_out_of_date' => true,
    ])->assertOk();

    expect($saved->json('data.config.source'))->toEqual([
        'data_source_id' => $source, 'endpoint_id' => $endpoint, 'endpoint_revision_id' => $revision, 'data_source_revision' => 1,
        'bindings' => ['region' => 'apac'], 'sample_out_of_date' => true,
    ])->and(json_decode(bdRows()[0]['config'], true)['source']['endpoint_revision_id'])->toBe($revision);

    $this->getJson(BD_URL."/{$id}", BD_HEADERS)->assertOk()->assertJsonPath('data.config.source.endpoint_id', $endpoint);
});

it('keeps the pins while the selection is unchanged and takes new ones when it changes', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $source = Cluster::seedDataSource($workspace);
    [$first, $firstRevision] = bsEndpoint($workspace, $source);
    [$second, $secondRevision] = bsEndpoint($workspace, $source, path: '/other');
    $id = bsDraft();

    bsSave($id, ['data_source_id' => $source, 'endpoint_id' => $first])->assertOk();
    // The Data Source moves on to revision 5: an unchanged selection keeps the revision the sample was taken against.
    Cluster::superuser()->prepare('UPDATE data_sources SET revision = 5 WHERE id = ?')->execute([$source]);
    $same = bsSave($id, ['data_source_id' => $source, 'endpoint_id' => $first, 'sample_out_of_date' => false], 2)->assertOk();
    expect($same->json('data.config.source'))->toMatchArray(['endpoint_revision_id' => $firstRevision, 'data_source_revision' => 1]);

    $changed = bsSave($id, ['data_source_id' => $source, 'endpoint_id' => $second], 3)->assertOk();
    expect($changed->json('data.config.source'))->toMatchArray(['endpoint_revision_id' => $secondRevision, 'data_source_revision' => 1]);
});

it('refuses an Endpoint of another Data Source, another Workspace, or with no Data Source, and stores nothing', function () {
    $other = Cluster::workspace('Other');
    $foreignSource = Cluster::seedDataSource($other);
    [$foreignEndpoint] = bsEndpoint($other, $foreignSource);
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $mine = Cluster::seedDataSource($workspace);
    $alsoMine = Cluster::seedDataSource($workspace);
    [$endpointOfOther] = bsEndpoint($workspace, $alsoMine);
    $id = bsDraft();
    $before = bdRows();

    bsSave($id, ['data_source_id' => $mine, 'endpoint_id' => $endpointOfOther])->assertStatus(422)->assertJsonPath('error.code', BlocksErrorCode::InvalidConfig->value)
        ->assertJsonValidationErrors(['config.source.endpoint_id'], 'errors');
    bsSave($id, ['data_source_id' => $mine, 'endpoint_id' => $foreignEndpoint])->assertStatus(422)->assertJsonValidationErrors(['config.source.endpoint_id'], 'errors');
    bsSave($id, ['data_source_id' => $foreignSource])->assertStatus(422)->assertJsonValidationErrors(['config.source.data_source_id'], 'errors');
    bsSave($id, ['endpoint_id' => $endpointOfOther])->assertStatus(422)->assertJsonValidationErrors(['config.source.endpoint_id'], 'errors');
    bsSave($id, ['data_source_id' => 'not-an-id'])->assertStatus(422)->assertJsonValidationErrors(['config.source.data_source_id'], 'errors');

    expect(bdRows())->toBe($before);
});

it('holds a value only for a Fixed parameter and drops a blank one', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $source = Cluster::seedDataSource($workspace);
    [$endpoint] = bsEndpoint($workspace, $source, [['from', 'date_range_from', null], ['region', 'fixed', 'emea'], ['team', 'fixed', '']]);
    $id = bsDraft();

    bsSave($id, ['data_source_id' => $source, 'endpoint_id' => $endpoint, 'bindings' => ['from' => '2026-01-01']])->assertStatus(422)
        ->assertJsonValidationErrors(['config.source.bindings.from'], 'errors');
    bsSave($id, ['data_source_id' => $source, 'endpoint_id' => $endpoint, 'bindings' => ['nope' => 'x']])->assertStatus(422);
    bsSave($id, ['bindings' => ['region' => 'x']])->assertStatus(422);

    $saved = bsSave($id, ['data_source_id' => $source, 'endpoint_id' => $endpoint, 'bindings' => ['region' => 'apac', 'team' => '  ']])->assertOk();
    expect($saved->json('data.config.source.bindings'))->toBe(['region' => 'apac']);
});

it('offers only the System-settings refresh intervals: none when the setting is unset, and Live only for a Live-capable source', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $source = Cluster::seedDataSource($workspace);
    $id = bsDraft();

    config(['dashflow.tunables.sync.refresh_intervals.value' => null]);
    $unset = bsSave($id, ['data_source_id' => $source, 'refresh_interval' => 300])->assertStatus(422);
    expect($unset->json('errors')['config.source.refresh_interval'][0])->toContain('No refresh intervals');

    config(['dashflow.tunables.sync.refresh_intervals.value' => '60,300,900']);
    bsSave($id, ['data_source_id' => $source, 'refresh_interval' => 120])->assertStatus(422);
    bsSave($id, ['data_source_id' => $source, 'refresh_interval' => '300'])->assertStatus(422);
    bsSave($id, ['data_source_id' => $source, 'refresh_interval' => 'live'])->assertStatus(422)->assertJsonValidationErrors(['config.source.refresh_interval'], 'errors');

    $ok = bsSave($id, ['data_source_id' => $source, 'refresh_interval' => 300])->assertOk();
    expect($ok->json('data.config.source.refresh_interval'))->toBe(300);

    Cluster::superuser()->prepare('UPDATE data_sources SET live_capable = true WHERE id = ?')->execute([$source]);
    $live = bsSave($id, ['data_source_id' => $source, 'refresh_interval' => 'live'], 2)->assertOk();
    expect($live->json('data.config.source.refresh_interval'))->toBe('live');

    // A value the Draft already holds may stay after the setting was cleared.
    bsSave($id, ['data_source_id' => $source, 'refresh_interval' => 'live'], 3)->assertOk();
    bsSave($id, ['data_source_id' => $source, 'refresh_interval' => 300], 4)->assertOk();
    config(['dashflow.tunables.sync.refresh_intervals.value' => null]);
    bsSave($id, ['data_source_id' => $source, 'refresh_interval' => 300], 5)->assertOk()->assertJsonPath('data.config.source.refresh_interval', 300);
});

it('checks the default date range: a preset, or a real custom range with the start not after the end', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $id = bsDraft();

    $ok = bsSave($id, ['default_date_range' => ['preset' => 'this_month', 'from' => '2026-01-01']])->assertOk();
    expect($ok->json('data.config.source.default_date_range'))->toBe(['preset' => 'this_month']);

    $custom = bsSave($id, ['default_date_range' => ['preset' => 'custom', 'from' => '2026-01-01', 'to' => '2026-02-28']], 2)->assertOk();
    expect($custom->json('data.config.source.default_date_range'))->toEqual(['preset' => 'custom', 'from' => '2026-01-01', 'to' => '2026-02-28']);

    bsSave($id, ['default_date_range' => ['preset' => 'custom', 'from' => '2026-03-01', 'to' => '2026-02-28']], 3)->assertStatus(422)
        ->assertJsonValidationErrors(['config.source.default_date_range.from'], 'errors');
    bsSave($id, ['default_date_range' => ['preset' => 'custom', 'from' => '2026-02-30', 'to' => '2026-03-01']], 3)->assertStatus(422);
    bsSave($id, ['default_date_range' => ['preset' => 'fortnight']], 3)->assertStatus(422);
    bsSave($id, ['default_date_range' => 'today'], 3)->assertStatus(422);
});

it('refuses a secret-valued key under source and stores nothing', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $source = Cluster::seedDataSource($workspace);
    $id = bsDraft();
    $before = bdRows();

    bsSave($id, ['data_source_id' => $source, 'api_token' => 'abc123'])->assertStatus(422)->assertJsonPath('error.code', BlocksErrorCode::SecretValuesRefused->value);
    bsSave($id, ['data_source_id' => $source, 'bindings' => ['authorization' => 'Bearer abc123']])->assertStatus(422)->assertJsonPath('error.code', BlocksErrorCode::SecretValuesRefused->value);

    expect(bdRows())->toBe($before)->and(json_encode(bdRows()))->not->toContain('abc123');
});

it('keeps the stored source when a later save carries only Step 1, and replaces it when one is sent', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $source = Cluster::seedDataSource($workspace);
    $id = bsDraft();
    bsSave($id, ['data_source_id' => $source])->assertOk();

    $step1 = bdSave($id, 2, 1, ['config' => ['schema_version' => 1, 'description' => 'Revenue.']])->assertOk();
    expect($step1->json('data.config.source.data_source_id'))->toBe($source)->and($step1->json('data.config.description'))->toBe('Revenue.');

    // Clearing the Data Source clears the pins with it.
    $cleared = bsSave($id, ['data_source_id' => null], 3)->assertOk();
    expect($cleared->json('data.config.source'))->toBeEmpty();
});

it('reads Data Sources, Endpoints and refresh intervals with blocks.edit alone, without headers or secrets', function () {
    $other = Cluster::workspace('Other');
    Cluster::seedDataSource($other, 'Foreign');
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $source = Cluster::seedDataSource($workspace, 'Finance API');
    bsEndpoint($workspace, $source, [['region', 'fixed', 'emea'], ['uid', 'user_id', null]], 'POST', '/api/v2/finance/revenue');
    config(['dashflow.tunables.sync.refresh_intervals.value' => '900,60,60']);

    $sources = $this->getJson('/api/v1/admin/blocks/data-sources', BD_HEADERS)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect($sources->json('data'))->toHaveCount(1)
        ->and($sources->json('data.0'))->toBe(['data_source_id' => $source, 'name' => 'Finance API', 'health' => 'checking', 'live_capable' => false, 'revision' => 1]);

    $endpoints = $this->getJson("/api/v1/admin/blocks/data-sources/{$source}/endpoints", BD_HEADERS)->assertOk();
    expect($endpoints->json('data.0'))->toMatchArray(['method' => 'POST', 'path' => '/api/v2/finance/revenue', 'read_only_query' => true])
        ->and(array_keys($endpoints->json('data.0')))->not->toContain('headers')->not->toContain('body_template')
        ->and($endpoints->json('data.0.params.0'))->toBe(['name' => 'region', 'binding' => 'fixed', 'value' => 'emea', 'kind' => 'query']);

    $this->getJson('/api/v1/admin/blocks/refresh-intervals', BD_HEADERS)->assertOk()->assertExactJson(['data' => ['intervals' => [60, 900]]]);

    config(['dashflow.tunables.sync.refresh_intervals.value' => 'soon']);
    $this->getJson('/api/v1/admin/blocks/refresh-intervals', BD_HEADERS)->assertOk()->assertExactJson(['data' => ['intervals' => null]]);

    $this->getJson('/api/v1/admin/blocks/data-sources/'.Cluster::seedDataSource($other).'/endpoints', BD_HEADERS)->assertNotFound();
});

it('answers 403 to the source reads without blocks.edit', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace, ['data_sources.manage']);
    $source = Cluster::seedDataSource($workspace);

    $this->getJson('/api/v1/admin/blocks/data-sources', BD_HEADERS)->assertForbidden();
    $this->getJson("/api/v1/admin/blocks/data-sources/{$source}/endpoints", BD_HEADERS)->assertForbidden();
    $this->getJson('/api/v1/admin/blocks/refresh-intervals', BD_HEADERS)->assertForbidden();
});

it('exposes the Endpoint revision ID the Block pins on the Endpoint resource', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace, ['data_sources.manage']);
    $source = Cluster::seedDataSource($workspace);
    [$endpoint, $revision] = bsEndpoint($workspace, $source);

    $this->getJson("/api/v1/admin/data-sources/{$source}/endpoints/{$endpoint}", BD_HEADERS)->assertOk()->assertJsonPath('data.revision_id', $revision);
});

it('lets an unchanged selection keep saving after its Data Source is gone, and keeps its pins', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $source = Cluster::seedDataSource($workspace);
    $id = bsDraft();
    bsSave($id, ['data_source_id' => $source, 'sample_out_of_date' => true])->assertOk();
    Cluster::superuser()->prepare('DELETE FROM data_sources WHERE id = ?')->execute([$source]);

    // The key is omitted: the stored flag is carried over.
    $saved = bsSave($id, ['data_source_id' => $source], 2)->assertOk();
    expect($saved->json('data.config.source'))->toMatchArray(['data_source_id' => $source, 'data_source_revision' => 1, 'sample_out_of_date' => true]);

    // A different, missing source is still refused.
    bsSave($id, ['data_source_id' => (string) Str::uuid7()], 3)->assertStatus(422);
});

it('drops a binding the Endpoint no longer has as a Fixed parameter when the selection is unchanged, and refuses it on a new selection', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $source = Cluster::seedDataSource($workspace);
    [$endpoint] = bsEndpoint($workspace, $source, [['region', 'fixed', 'emea']]);
    $id = bsDraft();
    bsSave($id, ['data_source_id' => $source, 'endpoint_id' => $endpoint, 'bindings' => ['region' => 'apac']])->assertOk();
    Cluster::superuser()->exec('ALTER TABLE endpoint_revisions DISABLE TRIGGER USER');
    Cluster::superuser()->prepare("UPDATE endpoint_revisions SET params = '[]'::jsonb WHERE endpoint_id = ?")->execute([$endpoint]);
    Cluster::superuser()->exec('ALTER TABLE endpoint_revisions ENABLE TRIGGER USER');

    $saved = bsSave($id, ['data_source_id' => $source, 'endpoint_id' => $endpoint, 'bindings' => ['region' => 'apac']], 2)->assertOk();
    expect($saved->json('data.config.source'))->not->toHaveKey('bindings');

    [$other] = bsEndpoint($workspace, $source, [], path: '/other');
    bsSave($id, ['data_source_id' => $source, 'endpoint_id' => $other, 'bindings' => ['region' => 'apac']], 3)->assertStatus(422);
});

it('keeps the Data Source pin when only the Endpoint changes', function () {
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);
    $source = Cluster::seedDataSource($workspace);
    [$first] = bsEndpoint($workspace, $source);
    [$second, $secondRevision] = bsEndpoint($workspace, $source, path: '/other');
    $id = bsDraft();
    bsSave($id, ['data_source_id' => $source, 'endpoint_id' => $first])->assertOk();
    Cluster::superuser()->prepare('UPDATE data_sources SET revision = 7 WHERE id = ?')->execute([$source]);

    $saved = bsSave($id, ['data_source_id' => $source, 'endpoint_id' => $second], 2)->assertOk();
    expect($saved->json('data.config.source'))->toMatchArray(['data_source_revision' => 1, 'endpoint_revision_id' => $secondRevision]);
});
