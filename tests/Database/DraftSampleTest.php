<?php

use App\Modules\Blocks\Application\PurgeDiscardedDrafts;
use App\Modules\Blocks\Application\PurgeDiscardedDraftsJob;
use App\Modules\Blocks\Contracts\ErrorCode as BlocksErrorCode;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;

// Story 3.8 against the real PostgreSQL: an Admin pastes a sample into a Draft. The server checks it with the lossless decoder (limits first,
// `pending_input`, never invented), derives paths, types and exact examples, and stores it only in `draft_samples`, sealed with the workspace
// `data` key, one per Draft, with a hard end from `draft_sample_ttl` and the personal-data acknowledgement. Helpers (bd*) come from DraftBlocksTest.
const SPL_BODY = <<<'JSON'
{
  "data": {
    "monthly": [
      {"month": "Jan", "revenue": 12345678901234567890.10, "rate": 1.0},
      {"month": "Feb", "revenue": 2.50, "rate": 1.10},
      {"month": "Mar", "revenue": 3, "rate": null}
    ],
    "currency": "USD"
  }
}
JSON;

const SPL_CANARY = 'CANARY-ds-9f3a1c';

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();

    $this->keyFile = tempnam(sys_get_temp_dir(), 'dashflow-data-key');
    file_put_contents($this->keyFile, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    config([
        'dashflow.secrets.data_key_path.value' => $this->keyFile,
        'dashflow.tunables.guards.max_bytes.value' => '5000',
        'dashflow.tunables.guards.depth_limit.value' => '10',
        'dashflow.tunables.timeouts.draft_sample_ttl.value' => '600',
        'dashflow.tunables.timeouts.edit_lock_ttl.value' => null,
    ]);

    $this->logFile = tempnam(sys_get_temp_dir(), 'dashflow-log');
    config(['logging.default' => 'single', 'logging.channels.single.path' => $this->logFile]);
});

afterEach(function () {
    @unlink($this->keyFile);
    @unlink($this->logFile);
    $this->travelBack();
});

function splUrl(string $id, string $tail = ''): string
{
    return BD_URL."/{$id}/sample{$tail}";
}

/** A signed-in Admin with a Draft; returns [workspace, draft ID]. */
function splDraft(): array
{
    $workspace = Cluster::workspace('Acme');
    bdAdmin($workspace);

    return [$workspace, bdCreate()->assertCreated()->json('data.draft_id')];
}

function splPut(string $id, string $text, bool $ack = true, array $extra = [])
{
    return test()->putJson(splUrl($id), $extra + ['text' => $text, 'contains_personal_data' => $ack, 'lock_epoch' => 1], BD_HEADERS);
}

function splCheck(string $id, string $text)
{
    return test()->postJson(splUrl($id, '/check'), ['text' => $text], BD_HEADERS);
}

function splRows(): array
{
    return Cluster::rows(Cluster::superuser(), 'select * from draft_samples order by id');
}

it('reads a valid sample losslessly: records, counts, display and stored paths, types and exact examples', function () {
    [, $id] = splDraft();

    $check = splCheck($id, SPL_BODY)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
    expect($check)->toMatchArray(['valid' => true, 'size_bytes' => strlen(SPL_BODY), 'reason' => null])
        ->and($check['shape']['records'])->toBe(['path' => '$.data.monthly[*]', 'display' => 'data.monthly[]', 'count' => 3])
        ->and($check['shape']['scalars'])->toBe(4)
        ->and($check['shape']['arrays'])->toBe(1)
        ->and($check['shape'])->not->toHaveKey('nodes');

    // Nothing is stored by a check.
    expect(splRows())->toBe([]);

    $stored = splPut($id, SPL_BODY)->assertOk()->json('data');
    $nodes = array_column($stored['shape']['nodes'], null, 'path');

    expect($nodes['$.data.monthly[*].revenue'])->toMatchArray(['display' => 'data.monthly[].revenue', 'type' => 'integer | decimal', 'example' => '12345678901234567890.10'])
        ->and($nodes['$.data.monthly[*].rate'])->toMatchArray(['type' => 'decimal | null', 'example' => '1.0'])
        ->and($nodes['$.data.monthly'])->toMatchArray(['type' => 'array', 'count' => 3])
        ->and($nodes['$.data.currency'])->toMatchArray(['type' => 'string', 'example' => 'USD'])
        ->and($stored['text'])->toBe(SPL_BODY);
});

it('keeps number lexemes exactly: 12345678901234567890.10 and 1.0 keep every digit and the trailing zero', function () {
    [, $id] = splDraft();
    $nodes = array_column(splPut($id, '{"a":12345678901234567890.10,"b":1.0,"c":[1.10,1.1]}')->assertOk()->json('data.shape.nodes'), null, 'path');

    expect($nodes['$.a'])->toMatchArray(['type' => 'decimal', 'example' => '12345678901234567890.10'])
        ->and($nodes['$.b']['example'])->toBe('1.0')
        ->and($nodes['$.c[*]']['example'])->toBe('1.10');
});

it('gives a table view for an array of records, with every cell as received', function () {
    [, $id] = splDraft();
    $shape = splPut($id, SPL_BODY)->assertOk()->json('data.shape');
    $arrayId = array_values(array_filter($shape['nodes'], fn (array $n): bool => $n['path'] === '$.data.monthly'))[0]['id'];
    $table = $shape['tables'][(string) $arrayId];

    expect(array_column($table['columns'], 'display'))->toBe(['data.monthly[].month', 'data.monthly[].revenue', 'data.monthly[].rate'])
        ->and($table['total'])->toBe(3)
        ->and($table['rows'][0])->toBe(['Jan', '12345678901234567890.10', '1.0'])
        ->and($table['rows'][2])->toBe(['Mar', '3', 'null']);
});

it('reports invalid JSON with the line, the column and what is wrong, never the text', function () {
    [, $id] = splDraft();
    $text = "{\n  \"a\": 1,\n  \"b\": [1, 2],\n  \"c\": \"".SPL_CANARY."\",\n}";

    $check = splCheck($id, $text)->assertOk()->json('data');
    expect($check)->toMatchArray(['valid' => false, 'reason' => 'not_json', 'line' => 5, 'column' => 1, 'shape' => null])
        ->and($check['problem'])->toBe('an object key must be a string');

    $found = splCheck($id, 'not json at all')->assertOk()->json('data');
    expect($found)->toMatchArray(['valid' => false, 'reason' => 'not_json', 'line' => 1, 'column' => 1])
        ->and(json_encode($check).json_encode($found))->not->toContain(SPL_CANARY);
});

it('rejects an over-limit paste and one nested too deep, stating the limit, and stores nothing', function () {
    [, $id] = splDraft();
    config(['dashflow.tunables.guards.max_bytes.value' => '20']);

    $large = splPut($id, '{"a":"'.str_repeat('x', 30).'"}')->assertStatus(422);
    expect($large->json('error.code'))->toBe(BlocksErrorCode::SampleInvalid->value)
        ->and($large->json('reason'))->toBe('too_large')
        ->and($large->json('check'))->toMatchArray(['reason' => 'too_large', 'limit' => 20, 'size_bytes' => 38]);

    config(['dashflow.tunables.guards.max_bytes.value' => '5000', 'dashflow.tunables.guards.depth_limit.value' => '3']);
    $deep = splPut($id, '[[[[1]]]]')->assertStatus(422);
    expect($deep->json('reason'))->toBe('too_deep')->and($deep->json('check.limit'))->toBe(3)
        ->and(splRows())->toBe([]);

    splCheck($id, '[[[1]]]')->assertOk()->assertJsonPath('data.valid', true);
    splCheck($id, '[[[[1]]]]')->assertOk()->assertJsonPath('data.reason', 'too_deep');
});

it('refuses a paste when a limit setting is unset or malformed, naming it, and never invents a number', function (string $setting, mixed $value) {
    [, $id] = splDraft();
    config(["dashflow.tunables.guards.{$setting}.value" => $value]);

    splCheck($id, '{"a":1}')->assertOk()->assertJsonPath('data.valid', false)->assertJsonPath('data.reason', 'limit_not_configured')->assertJsonPath('data.setting', $setting);
    splPut($id, '{"a":1}')->assertStatus(422)->assertJsonPath('reason', 'limit_not_configured')->assertJsonPath('check.setting', $setting);
    expect(splRows())->toBe([]);
})->with([
    'bytes unset' => ['max_bytes', null],
    'bytes malformed' => ['max_bytes', '12x'],
    'depth unset' => ['depth_limit', null],
    'depth malformed' => ['depth_limit', '-1'],
]);

it('stores a valid paste sealed with the data key, Draft-scoped, with a hard end and the acknowledgement', function () {
    [$workspace, $id] = splDraft();
    $text = '{"note":"'.SPL_CANARY.'","n":1.50}';

    $response = splPut($id, $text)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect($response->json('data'))->toMatchArray(['origin' => 'pasted', 'size_bytes' => strlen($text), 'contains_personal_data' => true, 'valid' => true]);

    $rows = splRows();
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['workspace_id' => $workspace, 'block_version_id' => $id, 'source' => 'paste', 'contains_personal_data' => true, 'size_bytes' => strlen($text)])
        ->and($rows[0]['body'])->not->toContain(SPL_CANARY)->and($rows[0]['body'])->not->toContain('1.50')
        ->and(base64_decode($rows[0]['body'], true))->not->toBeFalse()
        ->and(strtotime($rows[0]['expires_at']) - strtotime($rows[0]['created_at']))->toBe(600);

    // Never on the version row, in the raw tier, or in the audit trail or the log.
    expect(json_encode(bdRows()))->not->toContain(SPL_CANARY)
        ->and(json_encode(Cluster::rows(Cluster::superuser(), 'select * from audit_events')))->not->toContain(SPL_CANARY)
        ->and(file_get_contents($this->logFile))->not->toContain(SPL_CANARY);

    $read = $this->getJson(splUrl($id), BD_HEADERS)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect($read->json('data.text'))->toBe($text)->and($read->json('data.origin'))->toBe('pasted')
        ->and($read->json('data.shape.records'))->toBeNull();
});

it('replaces the previous sample with a new accepted paste: one sample per Draft', function () {
    [, $id] = splDraft();

    splPut($id, '{"a":1}')->assertOk();
    $first = splRows()[0]['id'];
    splPut($id, '{"b":[{"x":2}]}')->assertOk();

    expect(splRows())->toHaveCount(1)->and(splRows()[0]['id'])->toBe($first);
    $this->getJson(splUrl($id), BD_HEADERS)->assertOk()->assertJsonPath('data.text', '{"b":[{"x":2}]}')->assertJsonPath('data.shape.records.display', 'b[]');
});

it('refuses a paste with no acknowledgement and stores nothing', function () {
    [, $id] = splDraft();

    splPut($id, '{"a":1}', false)->assertStatus(422)->assertJsonPath('error.code', BlocksErrorCode::SampleInvalid->value)->assertJsonPath('reason', 'acknowledgement_required');
    $this->putJson(splUrl($id), ['text' => '{"a":1}', 'lock_epoch' => 1], BD_HEADERS)->assertStatus(422);
    expect(splRows())->toBe([]);
});

it('does not store a sample while the TTL setting is unset or malformed, and says so', function (mixed $ttl) {
    [, $id] = splDraft();
    config(['dashflow.tunables.timeouts.draft_sample_ttl.value' => $ttl]);

    splPut($id, '{"a":1}')->assertStatus(422)->assertJsonPath('reason', 'ttl_not_configured');
    expect(splRows())->toBe([]);
})->with(['unset' => [null], 'malformed' => ['10m'], 'zero' => ['0']]);

it('stores nothing and answers 503 when the data key is unusable', function () {
    [, $id] = splDraft();
    file_put_contents($this->keyFile, 'placeholder-not-a-real-key');

    splPut($id, '{"a":1}')->assertStatus(503)->assertJsonPath('error.code', BlocksErrorCode::SampleUnavailable->value);
    expect(splRows())->toBe([]);
});

it('never reads a sample past its hard end, and the purge deletes it', function () {
    [$workspace, $id] = splDraft();
    splPut($id, '{"a":1}')->assertOk();

    $this->travel(601)->seconds();
    $this->getJson(splUrl($id), BD_HEADERS)->assertOk()->assertJsonPath('data', null);
    expect(splRows())->toHaveCount(1);

    Cluster::superuser()->prepare("UPDATE draft_samples SET expires_at = now() - interval '1 second'")->execute();
    (new PurgeDiscardedDraftsJob($workspace))->handle(app(PurgeDiscardedDrafts::class));
    expect(splRows())->toBe([])->and(bdCount('block_versions'))->toBe(1);
});

it('deletes the sample on discard: unreadable at once, the row gone after the purge', function () {
    Queue::fake();
    [$workspace, $id] = splDraft();
    splPut($id, '{"a":"'.SPL_CANARY.'"}')->assertOk();

    $this->deleteJson(BD_URL."/{$id}", [], BD_HEADERS)->assertOk();
    $rows = splRows();
    expect($rows)->toHaveCount(1)->and($rows[0]['body'])->toBe('')->and($rows[0]['size_bytes'])->toBe(0);
    $this->getJson(splUrl($id), BD_HEADERS)->assertNotFound();

    (new PurgeDiscardedDraftsJob($workspace))->handle(app(PurgeDiscardedDrafts::class));
    expect(splRows())->toBe([]);
});

it('answers 404 for a Draft of another Workspace or a discarded one, on every sample route', function () {
    $other = Cluster::workspace('Other');
    $foreign = Cluster::seedBlockVersion($other);
    [, $id] = splDraft();

    foreach ([$foreign, (string) Str::uuid7()] as $missing) {
        $this->getJson(splUrl($missing), BD_HEADERS)->assertNotFound();
        splCheck($missing, '{"a":1}')->assertNotFound();
        splPut($missing, '{"a":1}')->assertNotFound();
    }

    expect(splRows())->toBe([]);
    $this->deleteJson(BD_URL."/{$id}", [], BD_HEADERS)->assertOk();
    splPut($id, '{"a":1}')->assertNotFound();
});

it('writes only with the edit-lock claim: a stale epoch or a token that does not hold the lock is a 423, a read is allowed', function () {
    config(['dashflow.tunables.timeouts.edit_lock_ttl.value' => '60']);
    [, $id] = splDraft();
    $lock = $this->postJson(BD_URL."/{$id}/lock", [], BD_HEADERS)->assertOk()->json('data');

    splPut($id, '{"a":1}')->assertStatus(423)->assertJsonPath('error.code', PlatformErrorCode::EditLockLost->value);
    splPut($id, '{"a":1}', true, ['lock_token' => str_repeat('a', 32)])->assertStatus(423);
    splPut($id, '{"a":1}', true, ['lock_token' => $lock['token'], 'lock_epoch' => 7])->assertStatus(423);
    expect(splRows())->toBe([]);

    splPut($id, '{"a":1}', true, ['lock_token' => $lock['token']])->assertOk();
    // A second editor with no claim still reads it.
    $this->getJson(splUrl($id), BD_HEADERS)->assertOk()->assertJsonPath('data.text', '{"a":1}');
});

it('needs blocks.edit and does not grant a User-area session or a member without the permission', function () {
    [, $id] = splDraft();
    $this->flushSession();
    $workspace = Cluster::superuser()->query('select workspace_id from block_versions limit 1')->fetchColumn();
    bdAdmin($workspace, [], 'nobody@example.test');

    $this->getJson(splUrl($id), BD_HEADERS)->assertForbidden();
    splCheck($id, '{"a":1}')->assertForbidden();
    splPut($id, '{"a":1}')->assertForbidden();
});

it('lets a secret-looking sample body through: only field names are judged, never what the sample says', function () {
    [, $id] = splDraft();
    $text = '{"password":"hunter2","api_key":"abc","token":"t"}';

    splPut($id, $text)->assertOk();
    $this->getJson(splUrl($id), BD_HEADERS)->assertOk()->assertJsonPath('data.text', $text);
    expect(file_get_contents($this->logFile))->not->toContain('hunter2');
});

it('throttles the sample routes', function () {
    [, $id] = splDraft();

    foreach (range(1, 60) as $i) {
        splCheck($id, '{}')->assertOk();
    }

    splCheck($id, '{}')->assertStatus(429);
});

it('keeps the pasted text exactly, whitespace included, and reads a whitespace-only paste as invalid JSON', function () {
    [, $id] = splDraft();
    $text = "  \n\t{\"a\": 1.0}\r\n  ";

    $stored = splPut($id, $text)->assertOk();
    expect($stored->json('data.text'))->toBe($text)->and($stored->json('data.size_bytes'))->toBe(strlen($text))
        ->and(splRows()[0]['size_bytes'])->toBe(strlen($text));
    $this->getJson(splUrl($id), BD_HEADERS)->assertOk()->assertJsonPath('data.text', $text);

    $blank = splCheck($id, "  \n ")->assertOk();
    expect($blank->json('data'))->toMatchArray(['valid' => false, 'reason' => 'not_json', 'size_bytes' => 4]);
    splCheck($id, '')->assertOk()->assertJsonPath('data.reason', 'not_json');
    splPut($id, "  \n ")->assertStatus(422)->assertJsonPath('reason', 'not_json');
});

it('treats a lifetime above the ceiling as not configured instead of failing', function () {
    [, $id] = splDraft();
    config(['dashflow.tunables.timeouts.draft_sample_ttl.value' => '99999999999999']);

    splPut($id, '{"a":1}')->assertStatus(422)->assertJsonPath('reason', 'ttl_not_configured');
    expect(splRows())->toBe([]);
});

it('answers 503 for a sealed body copied to another Draft, and for a corrupted one', function () {
    [$workspace, $first] = splDraft();
    $second = bdCreate(['name' => 'Other'])->assertCreated()->json('data.draft_id');
    splPut($first, '{"a":1}')->assertOk();
    $row = splRows()[0];

    Cluster::superuser()->prepare('INSERT INTO draft_samples (id, workspace_id, block_version_id, source, body, size_bytes, created_at, expires_at, contains_personal_data) VALUES (?, ?, ?, ?, ?, ?, now(), now() + interval \'1 hour\', true)')
        ->execute([(string) Str::uuid7(), $workspace, $second, 'paste', $row['body'], 7]);
    $this->getJson(splUrl($second), BD_HEADERS)->assertStatus(503)->assertJsonPath('error.code', BlocksErrorCode::SampleUnavailable->value);
    $this->getJson(splUrl($first), BD_HEADERS)->assertOk();

    Cluster::superuser()->prepare('UPDATE draft_samples SET body = ? WHERE block_version_id = ?')->execute([substr($row['body'], 0, -8).'AAAAAAAA', $first]);
    $this->getJson(splUrl($first), BD_HEADERS)->assertStatus(503)->assertJsonPath('error.code', BlocksErrorCode::SampleUnavailable->value);

    Cluster::superuser()->prepare('UPDATE draft_samples SET body = ? WHERE block_version_id = ?')->execute(['not base64 !!', $first]);
    $this->getJson(splUrl($first), BD_HEADERS)->assertStatus(503);
});

it('reads a stored sample as invalid, with its text, when a limit was lowered afterwards', function () {
    [, $id] = splDraft();
    splPut($id, '{"a":"'.str_repeat('x', 30).'"}')->assertOk();
    config(['dashflow.tunables.guards.max_bytes.value' => '20']);

    $read = $this->getJson(splUrl($id), BD_HEADERS)->assertOk();
    expect($read->json('data'))->toMatchArray(['valid' => false, 'reason' => 'too_large', 'limit' => 20, 'shape' => null])
        ->and($read->json('data.text'))->toBe('{"a":"'.str_repeat('x', 30).'"}');
});
