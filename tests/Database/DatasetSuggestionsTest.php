<?php

use App\Modules\Blocks\Application\PreviewDraft;
use App\Modules\Datasets\Contracts\DatasetSuggestions;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 3.11c against the real PostgreSQL: the Datasets suggestion catalog. A record-path pick remembers (workspace, endpoint, record_path) with its
// typed fields; a new Draft of the same Endpoint with no record path reads it as `dataset_suggestion`; nothing is ever applied. Helpers (bd*, bs*, dsl*)
// come from DraftBlocksTest, BlockSourceStepTest and DraftSlotsTest.
const DS_SAMPLE = '{"data":[{"id":7,"name":"North","revenue":1250.5,"date":"2026-09-01"}],"meta":{"links":[{"rel":"next"}]}}';

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
    ]);
});

afterEach(function () {
    @unlink($this->keyFile);
    PreviewDraft::forget();
});

/** A Draft with a sample, on the Endpoint when one is given (set in the Draft's source). */
function dsDraft(?string $workspace = null, ?string $endpoint = null, ?string $source = null, string $sample = DS_SAMPLE, bool $signIn = true): string
{
    $workspace ??= Cluster::workspace('Acme');

    if ($signIn) {
        bdAdmin($workspace);
    }

    $id = bdCreate()->assertCreated()->json('data.draft_id');

    if ($endpoint !== null) {
        $revision = (int) Cluster::rows(Cluster::superuser(), 'select revision from block_versions where id = ?', [$id])[0]['revision'];
        bsSave($id, ['data_source_id' => $source, 'endpoint_id' => $endpoint], $revision)->assertOk();
    }

    dslPaste($id, $sample);

    return $id;
}

function dsRevision(string $id): int
{
    return (int) Cluster::rows(Cluster::superuser(), 'select revision from block_versions where id = ?', [$id])[0]['revision'];
}

function dsPick(string $id, ?string $path)
{
    return test()->putJson(BD_URL."/{$id}/mapping/record-path", ['record_path' => $path, 'draft_revision' => dsRevision($id), 'lock_epoch' => 1], BD_HEADERS);
}

function dsFields(): array
{
    return Cluster::rows(Cluster::superuser(), 'select d.record_path, f.path, f.type, f.role, f.position from dataset_fields f join datasets d on d.id = f.dataset_id where f.position is not null order by d.record_path, f.position');
}

/** A Workspace with a Data Source and an Endpoint; returns [workspace, source, endpoint]. */
function dsWorkspace(string $name = 'Acme'): array
{
    $workspace = Cluster::workspace($name);
    $source = Cluster::seedDataSource($workspace, 'Finance API');
    [$endpoint] = bsEndpoint($workspace, $source);

    return [$workspace, $source, $endpoint];
}

it('remembers the dataset and its typed fields on the first record-path pick', function () {
    [$workspace, $source, $endpoint] = dsWorkspace();
    bdAdmin($workspace);
    $id = dsDraft($workspace, $endpoint, $source, signIn: false);

    dsPick($id, '$.data[*]')->assertOk();

    $datasets = Cluster::rows(Cluster::superuser(), 'select * from datasets');
    expect($datasets)->toHaveCount(1)
        ->and($datasets[0])->toMatchArray(['workspace_id' => $workspace, 'endpoint_id' => $endpoint, 'record_path' => '$.data[*]'])
        ->and(dsFields())->toBe([
            ['record_path' => '$.data[*]', 'path' => '$.data[*].id', 'type' => 'integer', 'role' => 'dimension', 'position' => 0],
            ['record_path' => '$.data[*]', 'path' => '$.data[*].name', 'type' => 'string', 'role' => 'label', 'position' => 1],
            ['record_path' => '$.data[*]', 'path' => '$.data[*].revenue', 'type' => 'decimal', 'role' => 'measure', 'position' => 2],
            ['record_path' => '$.data[*]', 'path' => '$.data[*].date', 'type' => 'string', 'role' => 'time', 'position' => 3],
        ]);
});

it('stores no sample text and no Draft id', function () {
    [$workspace, $source, $endpoint] = dsWorkspace();
    bdAdmin($workspace);
    $id = dsDraft($workspace, $endpoint, $source, signIn: false);
    dsPick($id, '$.data[*]')->assertOk();

    $dump = json_encode([Cluster::rows(Cluster::superuser(), 'select * from datasets'), Cluster::rows(Cluster::superuser(), 'select * from dataset_fields')]);

    expect($dump)->not->toContain('North')->not->toContain('1250.5')->not->toContain($id);
});

it('updates the same dataset on a re-pick, replaces its fields and moves updated_at', function () {
    [$workspace, $source, $endpoint] = dsWorkspace();
    bdAdmin($workspace);
    $id = dsDraft($workspace, $endpoint, $source, signIn: false);
    dsPick($id, '$.data[*]')->assertOk();
    $first = Cluster::rows(Cluster::superuser(), 'select id, updated_at from datasets')[0];
    $rows = Cluster::rows(Cluster::superuser(), 'select id from dataset_fields order by id');

    dslWrite($id, 'field-roles', ['path' => '$.data[*].id', 'role' => 'filter'], ['draft_revision' => dsRevision($id)])->assertOk();
    usleep(10000);
    dsPick($id, '$.data[*]')->assertOk();

    $after = Cluster::rows(Cluster::superuser(), 'select id, updated_at from datasets');
    expect($after)->toHaveCount(1)
        ->and($after[0]['id'])->toBe($first['id'])
        ->and($after[0]['updated_at'])->not->toBe($first['updated_at'])
        ->and(dsFields()[0])->toMatchArray(['path' => '$.data[*].id', 'role' => 'filter'])
        ->and(Cluster::rows(Cluster::superuser(), 'select id from dataset_fields order by id'))->toBe($rows);
});

it('retires surplus field rows and reuses them when the field list changes length', function () {
    [$workspace, $source, $endpoint] = dsWorkspace();
    bdAdmin($workspace);
    $id = dsDraft($workspace, $endpoint, $source, signIn: false);
    dsPick($id, '$.data[*]')->assertOk();
    dsPick($id, '$.meta.links[*]')->assertOk();

    $suggestions = app(DatasetSuggestions::class);
    $suggestions->remember($workspace, $endpoint, '$.data[*]', [['path' => '$.data[*].id', 'type' => 'number', 'role' => null]]);

    expect(array_column(dsFields(), 'path'))->toBe(['$.data[*].id', '$.meta.links[*].rel'])
        ->and((int) Cluster::rows(Cluster::superuser(), 'select count(*) as n from dataset_fields')[0]['n'])->toBe(5);

    $suggestions->remember($workspace, $endpoint, '$.data[*]', [['path' => '$.a', 'type' => 'text', 'role' => 'label'], ['path' => '$.b', 'type' => 'text', 'role' => 'label'], ['path' => '$.c', 'type' => 'text', 'role' => 'label']]);

    expect(array_column(Cluster::rows(Cluster::superuser(), 'select path from dataset_fields d where position is not null and dataset_id = (select id from datasets where record_path = \'$.data[*]\') order by position'), 'path'))->toBe(['$.a', '$.b', '$.c'])
        ->and((int) Cluster::rows(Cluster::superuser(), 'select count(*) as n from dataset_fields')[0]['n'])->toBe(5);
});

it('keeps a second dataset row for another record path of the Endpoint', function () {
    [$workspace, $source, $endpoint] = dsWorkspace();
    bdAdmin($workspace);
    $id = dsDraft($workspace, $endpoint, $source, signIn: false);

    dsPick($id, '$.data[*]')->assertOk();
    dsPick($id, '$.meta.links[*]')->assertOk();

    expect(array_column(Cluster::rows(Cluster::superuser(), 'select record_path from datasets order by record_path'), 'record_path'))->toBe(['$.data[*]', '$.meta.links[*]']);
});

it('upserts nothing when the record path is cleared', function () {
    [$workspace, $source, $endpoint] = dsWorkspace();
    bdAdmin($workspace);
    $id = dsDraft($workspace, $endpoint, $source, signIn: false);

    dsPick($id, null)->assertOk();

    expect(bdCount('datasets'))->toBe(0)->and(bdCount('dataset_fields'))->toBe(0);
});

it('remembers nothing for a Draft with no Endpoint, and offers it no suggestion', function () {
    [$workspace, $source, $endpoint] = dsWorkspace();
    bdAdmin($workspace);
    Cluster::seedDatasetField($workspace, Cluster::seedDataset($workspace, $endpoint));
    $id = dsDraft($workspace, signIn: false);

    dsPick($id, '$.data[*]')->assertOk();

    expect(bdCount('datasets'))->toBe(1)
        ->and(dslGet($id)->assertOk()->json('data.dataset_suggestion'))->toBeNull();
});

it('offers the latest dataset to a new Draft of the same Endpoint, with nothing applied', function () {
    [$workspace, $source, $endpoint] = dsWorkspace();
    bdAdmin($workspace);
    $first = dsDraft($workspace, $endpoint, $source, signIn: false);
    dsPick($first, '$.data[*]')->assertOk();
    usleep(10000);
    dsPick($first, '$.meta.links[*]')->assertOk();

    $second = dsDraft($workspace, $endpoint, $source, signIn: false);
    $before = Cluster::rows(Cluster::superuser(), 'select config, revision from block_versions where id = ?', [$second]);
    $plain = dslGet($first)->json('data');

    $read = dslGet($second)->assertOk()->json('data');
    $suggestion = $read['dataset_suggestion'];

    expect($suggestion)->toMatchArray(['record_path' => '$.meta.links[*]', 'record_path_display' => 'meta.links[]'])
        ->and($suggestion['fields'])->toBe([['path' => '$.meta.links[*].rel', 'type' => 'string', 'role' => 'dimension']])
        ->and($suggestion['updated_at'])->toMatch('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/')
        // The suggested record path is one the candidates list offers, in the same form.
        ->and(array_column($read['roles']['candidates'], 'path'))->toContain($suggestion['record_path'])
        // Nothing is applied: no stored path, the inference still starts from its own suggestion, and the config is as it was.
        ->and($read['roles']['record_path'])->toBe('$.data[*]')
        ->and(Cluster::rows(Cluster::superuser(), 'select config, revision from block_versions where id = ?', [$second]))->toBe($before);

    // A Draft that has chosen a path has no suggestion.
    expect($plain['dataset_suggestion'])->toBeNull();
});

it('returns no suggestion once the Draft has a chosen record path', function () {
    [$workspace, $source, $endpoint] = dsWorkspace();
    bdAdmin($workspace);
    Cluster::seedDatasetField($workspace, Cluster::seedDataset($workspace, $endpoint));
    $id = dsDraft($workspace, $endpoint, $source, signIn: false);

    expect(dslGet($id)->json('data.dataset_suggestion.record_path'))->toBe('$.data[*]');

    dsPick($id, '$.data[*]')->assertOk();

    expect(dslGet($id)->json('data.dataset_suggestion'))->toBeNull();
});

it('does not show another Workspace\'s dataset, even for the same record path', function () {
    [$mine, $mySource, $myEndpoint] = dsWorkspace('Mine');
    [$other, $otherSource, $otherEndpoint] = dsWorkspace('Other');
    $suggestions = app(DatasetSuggestions::class);
    $suggestions->remember($other, $otherEndpoint, '$.data[*]', [['path' => '$.data[*].secret_field', 'type' => 'number', 'role' => 'measure']]);

    bdAdmin($mine);
    $id = dsDraft($mine, $myEndpoint, $mySource, signIn: false);

    expect(dslGet($id)->json('data.dataset_suggestion'))->toBeNull()
        ->and($suggestions->suggestFor($mine, $otherEndpoint))->toBeNull()
        ->and($suggestions->suggestFor($mine, $myEndpoint))->toBeNull();

    dsPick($id, '$.data[*]')->assertOk();

    expect($suggestions->suggestFor($other, $otherEndpoint)->fields)->toBe([['path' => '$.data[*].secret_field', 'type' => 'number', 'role' => 'measure']])
        // Workspace B's own read sees only its own row, and none of A's field paths.
        ->and(array_column($suggestions->suggestFor($mine, $myEndpoint)->fields, 'path'))->not->toContain('$.data[*].secret_field')
        ->and(dslGet($id)->json('data.dataset_suggestion'))->toBeNull()
        ->and(array_unique(array_column(Cluster::rows(Cluster::superuser(), 'select workspace_id from datasets'), 'workspace_id')))->toEqualCanonicalizing([$mine, $other]);

    $second = dsDraft($mine, $myEndpoint, $mySource, signIn: false);
    expect(dslGet($second)->json('data.dataset_suggestion.fields'))->not->toContain(['path' => '$.data[*].secret_field', 'type' => 'number', 'role' => 'measure']);
});

it('keeps the suggestion read out of the checklist and the roles', function () {
    [$workspace, $source, $endpoint] = dsWorkspace();
    bdAdmin($workspace);
    $id = dsDraft($workspace, $endpoint, $source, signIn: false);
    $without = dslGet($id)->json('data');

    Cluster::seedDatasetField($workspace, Cluster::seedDataset($workspace, $endpoint, '$.meta.links[*]'));
    $with = dslGet($id)->assertOk()->json('data');

    expect($with['dataset_suggestion'])->not->toBeNull()
        ->and([...$with, 'dataset_suggestion' => null])->toBe([...$without, 'dataset_suggestion' => null]);
});

it('offers a suggestion to a Draft with an Endpoint but no usable sample', function () {
    [$workspace, $source, $endpoint] = dsWorkspace();
    bdAdmin($workspace);
    Cluster::seedDatasetField($workspace, Cluster::seedDataset($workspace, $endpoint));
    $id = bdCreate()->assertCreated()->json('data.draft_id');
    bsSave($id, ['data_source_id' => $source, 'endpoint_id' => $endpoint], dsRevision($id))->assertOk();

    $data = dslGet($id)->assertOk()->json('data');

    expect($data['sample'])->toBe('expired')
        ->and($data['dataset_suggestion'])->toMatchArray(['record_path' => '$.data[*]', 'record_path_display' => 'data[]']);
});

it('leaves the catalog empty when the record-path write is refused', function () {
    [$workspace, $source, $endpoint] = dsWorkspace();
    bdAdmin($workspace);
    $id = dsDraft($workspace, $endpoint, $source, signIn: false);

    test()->putJson(BD_URL."/{$id}/mapping/record-path", ['record_path' => '$.data[*]', 'draft_revision' => dsRevision($id) + 5, 'lock_epoch' => 1], BD_HEADERS)->assertStatus(409);
    test()->putJson(BD_URL."/{$id}/mapping/record-path", ['record_path' => '$.data[*]', 'draft_revision' => dsRevision($id), 'lock_epoch' => 99], BD_HEADERS)->assertStatus(423);
    dsPick($id, '$.nothing[*]')->assertStatus(422);

    expect(bdCount('datasets'))->toBe(0)->and(bdCount('dataset_fields'))->toBe(0);
});

it('lets the pick succeed when the Endpoint is gone or the id is not a UUID, and remembers nothing', function () {
    [$workspace, $source, $endpoint] = dsWorkspace();
    bdAdmin($workspace);
    $id = dsDraft($workspace, $endpoint, $source, signIn: false);

    foreach ([(string) Str::uuid7(), 'not-a-uuid'] as $bad) {
        Cluster::superuser()->prepare("update block_versions set config = jsonb_set(config, '{source,endpoint_id}', to_jsonb(?::text)) where id = ?")->execute([$bad, $id]);
        dsPick($id, null)->assertOk();
        dsPick($id, '$.data[*]')->assertOk();
        dsPick($id, null)->assertOk();
    }

    expect(bdCount('datasets'))->toBe(0)->and(bdCount('dataset_fields'))->toBe(0)
        ->and(Cluster::rows(Cluster::superuser(), 'select config from block_versions where id = ?', [$id])[0]['config'])->toContain('not-a-uuid');
});

it('skips a field whose path is too long and still lets the pick succeed', function () {
    [$workspace, $source, $endpoint] = dsWorkspace();
    bdAdmin($workspace);
    $long = str_repeat('k', 600);
    $id = dsDraft($workspace, $endpoint, $source, '{"data":[{"id":1,"'.$long.'":2}]}', signIn: false);

    dsPick($id, '$.data[*]')->assertOk();

    expect(array_column(dsFields(), 'path'))->toBe(['$.data[*].id']);

    $suggestions = app(DatasetSuggestions::class);
    $suggestions->remember($workspace, $endpoint, '$.x[*]', [['path' => '$.x[*].a', 'type' => str_repeat('t', 80), 'role' => 'label'], ['path' => '$.x[*].b', 'type' => 'string', 'role' => 'bogus']]);
    $suggestions->remember($workspace, $endpoint, '$.'.$long.'[*]', [['path' => '$.a', 'type' => 'string', 'role' => null]]);

    expect(array_column(Cluster::rows(Cluster::superuser(), "select path, role from dataset_fields where path like '\$.x%'"), 'role', 'path'))->toBe(['$.x[*].b' => null])
        ->and(bdCount('datasets'))->toBe(2);
});

it('changes the ETag of the mapping read when another Draft\'s pick changes the suggestion', function () {
    [$workspace, $source, $endpoint] = dsWorkspace();
    bdAdmin($workspace);
    $first = dsDraft($workspace, $endpoint, $source, signIn: false);
    $second = dsDraft($workspace, $endpoint, $source, signIn: false);

    $none = dslGet($second)->assertOk()->headers->get('ETag');
    dsPick($first, '$.data[*]')->assertOk();

    $with = dslGet($second)->assertOk()->headers->get('ETag');
    expect($with)->not->toBe($none);
    dslGet($second, ['If-None-Match' => $none])->assertOk();
    dslGet($second, ['If-None-Match' => $with])->assertStatus(304);

    usleep(10000);
    dsPick($first, '$.data[*]')->assertOk();
    dslGet($second, ['If-None-Match' => $with])->assertOk();
});

it('refreshes the suggestion after a role or type change without a new pick', function () {
    [$workspace, $source, $endpoint] = dsWorkspace();
    bdAdmin($workspace);
    $first = dsDraft($workspace, $endpoint, $source, signIn: false);
    dsPick($first, '$.data[*]')->assertOk();
    $at = Cluster::rows(Cluster::superuser(), 'select updated_at from datasets')[0]['updated_at'];
    usleep(10000);

    dslWrite($first, 'field-roles', ['path' => '$.data[*].revenue', 'role' => 'dimension'], ['draft_revision' => dsRevision($first)])->assertOk();

    $second = dsDraft($workspace, $endpoint, $source, signIn: false);
    $fields = dslGet($second)->json('data.dataset_suggestion.fields');
    expect(array_column($fields, 'role', 'path')['$.data[*].revenue'])->toBe('dimension')
        ->and(Cluster::rows(Cluster::superuser(), 'select updated_at from datasets')[0]['updated_at'])->not->toBe($at);

    dslWrite($first, 'field-types', ['path' => '$.data[*].id', 'type' => 'text'], ['draft_revision' => dsRevision($first)])->assertOk();
});

it('does not offer a dataset whose fields are all retired or empty', function () {
    [$workspace, $source, $endpoint] = dsWorkspace();
    $suggestions = app(DatasetSuggestions::class);
    $suggestions->remember($workspace, $endpoint, '$.old[*]', [['path' => '$.old[*].a', 'type' => 'string', 'role' => 'label']]);
    usleep(10000);
    $suggestions->remember($workspace, $endpoint, '$.empty[*]', []);

    expect($suggestions->suggestFor($workspace, $endpoint)->recordPath)->toBe('$.old[*]');

    $suggestions->remember($workspace, $endpoint, '$.old[*]', []);

    expect($suggestions->suggestFor($workspace, $endpoint))->toBeNull();
});

it('refuses DELETE on the catalog tables to role app', function () {
    $workspace = Cluster::workspace('Acme');
    Cluster::seedDatasetField($workspace);

    foreach (['dataset_fields', 'datasets'] as $table) {
        foreach ([Cluster::directApp(), Cluster::pooledApp()] as $app) {
            expect(fn () => Cluster::inWorkspace($app, $workspace, fn ($pdo) => $pdo->exec("delete from {$table}")))->toThrow(PDOException::class, 'permission denied');
        }
    }

    expect(bdCount('datasets'))->toBe(1)->and(bdCount('dataset_fields'))->toBe(1);
});
