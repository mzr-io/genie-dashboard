<?php

use App\Modules\Blocks\Application\PreviewDraft;
use App\Modules\Connector\Contracts\HostResolver;
use App\Modules\Connector\Infrastructure\CurlClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Database\Support\Cluster;
use Tests\Unit\Support\FakeCurl;
use Tests\Unit\Support\FakeResolver;

// Story 3.17 against the real PostgreSQL: Fetch as user and Preview as in the Create block wizard. The Connector's `fetch_as_user` Operation does the
// fetch; the Blocks routes add the Draft and read the finished response back for the requester only. Helpers (bd*, dp*, fu*) come from
// DraftBlocksTest, DraftPreviewTest and FetchAsUserTest, which load first.
const US_ATTRIBUTE = 'CANARY-us-attr-52d1';
const US_VALUE = 'CANARY-us-value-8e40';
const US_BODY = '{"data":{"revenue":99.75,"owner":"CANARY-us-value-8e40"}}';
const US_SHARED = '{"data":{"revenue":1.50,"owner":"shared"}}';

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

    $this->curl = new FakeCurl;
    app()->instance(HostResolver::class, new FakeResolver(['api.example.com' => ['93.184.216.34']]));
    app()->instance(CurlClient::class, $this->curl);
});

afterEach(function () {
    @unlink($this->keyFile);
    @unlink($this->digestFile);
    @unlink($this->logFile);
    PreviewDraft::forget();
});

const US_PERMISSIONS = ['blocks.edit', 'data_sources.manage', 'data.preview_as_user', 'users.manage'];

/**
 * A Workspace with an Admin holding the wizard's permissions (signed in), a target member with the bound attribute and a group, an Endpoint bound
 * to the user, and a Draft with the shared sample and a plan over `$.data.revenue`.
 *
 * @return array{workspace: string, admin: string, target: string, source: string, endpoint: string, draft: string}
 */
function usWorld(): array
{
    $workspace = Cluster::workspace('Acme');
    [, $admin] = fuAdmin($workspace, US_PERMISSIONS);
    Cluster::seedAttributeKey($workspace, 'region');
    [, $target] = fuMember($workspace, 'grace@example.test');
    fuGroup($workspace, $target, 'Finance');
    fuAttribute($target, ['region' => US_ATTRIBUTE]);
    $source = Cluster::seedDataSource($workspace, 'Sales API');
    $endpoint = fuEndpoint($source);

    $draft = bdCreate()->assertCreated()->json('data.draft_id');
    dpPaste($draft, US_SHARED);
    bdSave($draft, 1, 1, ['config' => [
        'query_plan' => dpPlan(['rev' => '$.data.revenue'], ['headline_value' => 'rev']),
        'source' => ['data_source_id' => $source, 'endpoint_id' => $endpoint],
    ]])->assertOk();

    return compact('workspace', 'admin', 'target', 'source', 'endpoint', 'draft');
}

function usFetch(array $w, ?string $member = null, array $overrides = [])
{
    return test()->postJson(BD_URL."/{$w['draft']}/sample/fetch-as-user", $overrides + [
        'membership' => $member ?? $w['target'],
        'data_source_id' => $w['source'],
        'endpoint_id' => $w['endpoint'],
        'bindings' => [],
    ], BD_HEADERS);
}

function usShape(array $w, string $operation, ?string $endpoint = null)
{
    return test()->getJson(BD_URL."/{$w['draft']}/sample/as-user/{$operation}?".http_build_query(['data_source_id' => $w['source'], 'endpoint_id' => $endpoint ?? $w['endpoint']]), BD_HEADERS);
}

function usPreview(array $w, string $operation)
{
    return test()->postJson(dpUrl($w['draft']), ['as_user_operation' => $operation, 'data_source_id' => $w['source'], 'endpoint_id' => $w['endpoint']], BD_HEADERS);
}

/** Fetches as the target and returns the Operation's id. */
function usFetched(array $w): string
{
    test()->curl->queue = [FakeCurl::answer(200, US_BODY)];

    return usFetch($w)->assertStatus(202)->assertHeader('Cache-Control', 'no-store, private')->json('data.operation_id');
}

function usDenied(): int
{
    return (int) Cluster::rows(Cluster::superuser(), "select count(*) as n from audit_events where action = 'access.admin.denied'")[0]['n'];
}

it('fetches as a member from the wizard: one guarded request with the server-resolved values, one audit event, one outbox event and no attribute value in any response', function () {
    $w = usWorld();
    $this->curl->queue = [FakeCurl::answer(200, US_BODY)];

    $started = usFetch($w)->assertStatus(202);
    $id = $started->json('data.operation_id');

    expect($this->curl->calls)->toHaveCount(1)
        ->and($this->curl->calls[0][CURLOPT_URL])->toContain('region='.US_ATTRIBUTE)
        ->and($started->getContent())->not->toContain(US_ATTRIBUTE)->not->toContain('grace@')
        ->and(fuOperation($id)['status'])->toBe('succeeded')
        ->and(json_encode(fuOperation($id)))->not->toContain(US_ATTRIBUTE)->not->toContain(US_VALUE);

    $operation = Cluster::rows(Cluster::superuser(), 'select * from operations where id = ?', [$id])[0];
    expect($operation)->toMatchArray(['kind' => 'fetch_as_user', 'subject_type' => 'endpoint', 'subject_id' => $w['endpoint'], 'requester_membership_id' => $w['admin']]);

    $audit = Cluster::rows(Cluster::superuser(), "select * from audit_events where action = 'connector.fetch_as_user.performed'");
    $events = Cluster::rows(Cluster::superuser(), "select * from outbox_events where type = 'connector.fetch_as_user.performed'");
    expect($audit)->toHaveCount(1)
        ->and(json_decode($audit[0]['after_state'], true))->toMatchArray(['endpoint_id' => $w['endpoint'], 'target_membership_id' => $w['target']])
        ->and($events)->toHaveCount(1)
        ->and($events[0]['subject'])->toBe('membership:'.$w['target']);
});

it('gives the browser only the value-free structure of the response, and stores nothing of it', function () {
    $w = usWorld();
    $before = Cluster::rows(Cluster::superuser(), 'select sample_revision, expires_at from draft_samples')[0];
    $id = usFetched($w);

    $shape = usShape($w, $id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $paths = array_column($shape->json('data.shape.nodes'), 'path');

    expect($paths)->toContain('$.data.revenue', '$.data.owner')
        ->and(array_filter(array_column($shape->json('data.shape.nodes'), 'example')))->toBe([])
        ->and($shape->json('data.shape'))->not->toHaveKey('tables')
        ->and($shape->json('data.status'))->toBe(200)
        ->and($shape->getContent())->not->toContain(US_VALUE)->not->toContain('99.75')->not->toContain(US_ATTRIBUTE);

    // The shared sample and the Draft are untouched, and no table holds the response or the attribute value.
    expect(Cluster::rows(Cluster::superuser(), 'select sample_revision, expires_at from draft_samples')[0])->toBe($before)
        ->and(json_encode([
            Cluster::rows(Cluster::superuser(), 'select * from operations'),
            Cluster::rows(Cluster::superuser(), 'select * from sync_runs'),
            Cluster::rows(Cluster::superuser(), 'select * from audit_events'),
            Cluster::rows(Cluster::superuser(), 'select * from outbox_events'),
            Cluster::rows(Cluster::superuser(), 'select id, config, name from block_versions'),
            (string) file_get_contents($this->logFile),
        ]))->not->toContain(US_VALUE)->not->toContain(US_ATTRIBUTE)->not->toContain('99.75');
});

it('previews as the member from the ephemeral result, and the shared preview is unchanged', function () {
    $w = usWorld();
    $id = usFetched($w);

    $shared = dpPreview($w['draft'])->assertOk();
    $asUser = usPreview($w, $id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    expect($shared->json('data.payload.headline.value'))->toBe('1.50')
        ->and($shared->json('data.as_user'))->toBeNull()
        ->and($asUser->json('data.payload.headline.value'))->toBe('99.75')
        ->and($asUser->json('data.as_user.expires_at'))->toBeString()
        ->and($asUser->getContent())->not->toContain(US_ATTRIBUTE);

    // It wrote nothing: the Draft keeps its revision and the shared sample.
    expect(dpPreview($w['draft'])->json('data.payload.headline.value'))->toBe('1.50');
});

it('answers 403 with an audited security event to a caller without data.preview_as_user on every as-user route, and the shared preview still works', function () {
    $w = usWorld();
    $id = usFetched($w);
    [$user] = fuMember($w['workspace'], 'limited@example.test', 'admin', ['blocks.edit']);
    fuSignIn($user, $w['workspace']);
    $this->curl->calls = [];
    $operations = fuCount('operations');

    usFetch($w)->assertForbidden()->assertJsonPath('error.code', 'access.not_authorized');
    usShape($w, $id)->assertForbidden();
    usPreview($w, $id)->assertForbidden();
    test()->getJson('/api/v1/admin/blocks/preview-as-user/members', BD_HEADERS)->assertForbidden();

    expect(usDenied())->toBe(4)
        ->and(fuCount('operations'))->toBe($operations)
        ->and($this->curl->calls)->toBe([])
        ->and(json_decode(Cluster::rows(Cluster::superuser(), "select after_state from audit_events where action = 'access.admin.denied' limit 1")[0]['after_state'], true))
        ->toMatchArray(['permission' => 'data.preview_as_user', 'reason' => 'permission']);

    // Without an as-user choice a read-only preview needs no such permission.
    dpPreview($w['draft'])->assertOk();
});

it('fails closed with access.context_missing when the bound attribute is missing: no request, no result to read or preview', function () {
    $w = usWorld();
    [, $bare] = fuMember($w['workspace'], 'bare@example.test');
    fuGroup($w['workspace'], $bare, 'Ops');

    $id = usFetch($w, $bare)->assertStatus(202)->json('data.operation_id');
    $summary = fuOperation($id);

    expect($summary['result'])->toMatchArray(['ok' => false, 'code' => 'access.context_missing', 'reason' => 'context_missing', 'missing' => 'region'])
        ->and($this->curl->calls)->toBe([]);

    usShape($w, $id)->assertNotFound();
    usPreview($w, $id)->assertStatus(422)->assertJsonPath('error.code', 'blocks.preview_as_user_ended');
});

it('reverts to the shared sample once the Operation has expired: the shape is a 404 and the preview a 422 blocks.preview_as_user_ended', function () {
    $w = usWorld();
    $id = usFetched($w);
    usPreview($w, $id)->assertOk();

    Cluster::superuser()->prepare("update operations set expires_at = now() - interval '1 minute' where id = ?")->execute([$id]);

    usShape($w, $id)->assertNotFound();
    usPreview($w, $id)->assertStatus(422)->assertJsonPath('error.code', 'blocks.preview_as_user_ended');
    dpPreview($w['draft'])->assertOk()->assertJsonPath('data.payload.headline.value', '1.50');
});

it('reads a Fetch as user result only for the Admin that started it', function () {
    $w = usWorld();
    $id = usFetched($w);
    [$other] = fuMember($w['workspace'], 'omar@example.test', 'admin', US_PERMISSIONS);
    fuSignIn($other, $w['workspace']);

    usShape($w, $id)->assertNotFound();
    usPreview($w, $id)->assertStatus(422)->assertJsonPath('error.code', 'blocks.preview_as_user_ended');
});

it('answers 404 for another Workspace member, an unknown Draft and a Draft of another Workspace, and 422 for a malformed body, queuing nothing', function () {
    $w = usWorld();
    $stranger = fuMember(Cluster::workspace('Globex'), 'stranger@example.test')[1];
    $operations = fuCount('operations');

    usFetch($w, $stranger)->assertNotFound();
    test()->postJson(BD_URL.'/'.Str::uuid7().'/sample/fetch-as-user', ['membership' => $w['target'], 'data_source_id' => $w['source'], 'endpoint_id' => $w['endpoint']], BD_HEADERS)->assertNotFound();
    usFetch($w, 'not-a-uuid')->assertStatus(422);
    usFetch($w, null, ['data_source_id' => null])->assertStatus(422);

    expect(fuCount('operations'))->toBe($operations);
});

it('ignores a client value for a user-bound name: the request carries the member\'s own value and never the forged one', function () {
    $w = usWorld();
    $this->curl->queue = [FakeCurl::answer(200, US_BODY)];

    usFetch($w, null, ['bindings' => ['region' => 'forged-'.US_VALUE, 'uid' => 'forged-'.US_VALUE]])->assertStatus(202);

    expect($this->curl->calls)->toHaveCount(1)
        ->and($this->curl->calls[0][CURLOPT_URL])->toContain('region='.US_ATTRIBUTE)->not->toContain('forged');
});

it('lists the active members with their group names and no attribute value, only for a holder of data.preview_as_user', function () {
    $w = usWorld();

    $list = test()->getJson('/api/v1/admin/blocks/preview-as-user/members?search=grace', BD_HEADERS)->assertOk();

    expect(array_column($list->json('data.members'), 'membership_id'))->toBe([$w['target']])
        ->and($list->json('data.members.0.groups'))->toBe(['Finance'])
        ->and($list->getContent())->not->toContain(US_ATTRIBUTE);
});

it('sends the Fixed parameter and the date-range value of the wizard choices, next to the member\'s own values', function () {
    $w = usWorld();
    $endpoint = fuEndpoint($w['source'], [
        'path' => '/range',
        'params' => [
            ['name' => 'k', 'binding' => 'fixed', 'value' => 'dflt'],
            ['name' => 'since', 'binding' => 'date_range_from'],
            ['name' => 'until', 'binding' => 'date_range_to'],
            ['name' => 'uid', 'binding' => 'user_id'],
        ],
        'headers' => [],
    ]);
    $this->curl->queue = [FakeCurl::answer(200, US_BODY)];

    usFetch($w, null, [
        'endpoint_id' => $endpoint,
        'bindings' => ['k' => 'over'],
        'range' => ['from' => '2026-01-01', 'to' => '2026-01-31'],
    ])->assertStatus(202);

    $url = $this->curl->calls[0][CURLOPT_URL];
    expect($url)->toContain('k=over')->toContain('since=2026-01-01')->toContain('until=2026-01-31')->toContain('uid='.$w['target']);
});

it('answers 429 with retry_after and starts nothing more when the wizard fetch is over the limit', function () {
    $w = usWorld();
    config(['dashflow.sample_fetch.membership_limit.value' => '1', 'dashflow.sample_fetch.window.value' => '60']);
    $this->curl->queue = [FakeCurl::answer(200, US_BODY)];
    usFetch($w)->assertStatus(202);
    $operations = fuCount('operations');

    $over = usFetch($w)->assertStatus(429);

    expect($over->json('error.retry_after'))->toBeInt()
        ->and($over->headers->get('Retry-After'))->not->toBeNull()
        ->and($over->json('error.message'))->not->toContain('endpoint tests')
        ->and(fuCount('operations'))->toBe($operations);
});

it('leaves a deactivated member and an invitation out of the member list', function () {
    $w = usWorld();
    fuMember($w['workspace'], 'gone@example.test', 'user', [], 'deactivated');
    listInvitation($w['workspace'], 'invited@example.test');

    $ids = array_column(test()->getJson('/api/v1/admin/blocks/preview-as-user/members', BD_HEADERS)->assertOk()->json('data.members'), 'email');

    expect($ids)->toContain('grace@example.test')->not->toContain('gone@example.test')->not->toContain('invited@example.test');
});

it('refuses a plain endpoint test response as a member preview or structure', function () {
    $w = usWorld();
    $plain = fuEndpoint($w['source'], ['path' => '/plain', 'params' => [['name' => 'k', 'binding' => 'fixed', 'value' => 'x']], 'headers' => []]);
    bdSave($w['draft'], 2, 1, ['config' => ['query_plan' => dpPlan(['rev' => '$.data.revenue'], ['headline_value' => 'rev']), 'source' => ['data_source_id' => $w['source'], 'endpoint_id' => $plain]]])->assertOk();
    $this->curl->queue = [FakeCurl::answer(200, US_BODY)];
    $id = test()->postJson(fuUrl($w['source'], $plain, 'test'), ['values' => []], FU_HEADERS)->assertStatus(202)->json('data.operation_id');
    $w['endpoint'] = $plain;

    usShape($w, $id)->assertNotFound();
    usPreview($w, $id)->assertStatus(422)->assertJsonPath('error.code', 'blocks.preview_as_user_ended');
});

it('refuses a preview as a member for another Data Source or Endpoint than the Draft\'s', function () {
    $w = usWorld();
    $id = usFetched($w);
    $other = fuEndpoint($w['source'], ['path' => '/other', 'params' => [['name' => 'uid', 'binding' => 'user_id']], 'headers' => []]);

    test()->postJson(dpUrl($w['draft']), ['as_user_operation' => $id, 'data_source_id' => $w['source'], 'endpoint_id' => $other], BD_HEADERS)
        ->assertStatus(422)->assertJsonPath('error.code', 'blocks.preview_as_user_mismatch');
    usPreview($w, $id)->assertOk();
});
