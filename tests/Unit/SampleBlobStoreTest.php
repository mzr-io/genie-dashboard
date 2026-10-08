<?php

use App\Modules\Connector\Contracts\Sample;
use App\Modules\Connector\Contracts\SampleBlobUnavailable;
use App\Modules\Connector\Infrastructure\SampleBlobStore;
use App\Modules\Connector\Infrastructure\SecretSettings;
use App\Platform\Tenancy\TenantCache;
use App\Platform\Tenancy\TenantKey;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;

// Story 2.10: the Sample Response is sealed with the `data` key before it reaches the cache, bound to the Workspace, Operation,
// requesting membership and Endpoint revision, and fails closed when it cannot be sealed.

const SB_WS = '018f0000-0000-7000-8000-00000000000a';
const SB_OP = '018f0000-0000-7000-8000-0000000000aa';
const SB_ME = '018f0000-0000-7000-8000-0000000000bb';
const SB_EP = '018f0000-0000-7000-8000-0000000000cc';
const SB_BODY = '{"total":12345678901234567890.12,"rate":1.10,"note":"CANARY-sample-body ünï"}';

/** @return array{0: SampleBlobStore, 1: CacheRepository} */
function sbStore(?string $keyFile): array
{
    $settings = new SecretSettings(new Repository(['dashflow' => ['secrets' => ['data_key_path' => ['value' => $keyFile ?? '/nonexistent/key-data']]]]));
    $cache = new CacheRepository(new ArrayStore);

    return [new SampleBlobStore(new TenantCache($cache), $settings), $cache];
}

function sbRaw(CacheRepository $cache): mixed
{
    return $cache->get(TenantKey::cache(SB_WS, 'sample:'.SB_OP));
}

beforeEach(function () {
    $this->keyFile = tempnam(sys_get_temp_dir(), 'dashflow-data-key');
    file_put_contents($this->keyFile, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
});

afterEach(fn () => @unlink($this->keyFile));

it('stores the raw text sealed, never in the clear, and reads it back exactly for the requester', function () {
    [$store, $cache] = sbStore($this->keyFile);

    $store->put(SB_WS, SB_OP, SB_ME, SB_EP, 4, SB_BODY, 600);

    $raw = sbRaw($cache);
    expect($raw['workspace_id'])->toBe(SB_WS)
        ->and(json_encode($raw))->not->toContain('CANARY')->not->toContain('12345678901234567890')
        ->and(base64_decode($raw['value'], true))->not->toBeFalse()
        ->and($store->get(SB_WS, SB_OP, SB_ME, SB_EP, 4))->toBe(SB_BODY);
});

it('keeps the cache entry for no longer than the TTL it was given', function () {
    [$store, $cache] = sbStore($this->keyFile);

    $store->put(SB_WS, SB_OP, SB_ME, SB_EP, 4, SB_BODY, 90);

    $storage = (new ReflectionProperty(ArrayStore::class, 'storage'))->getValue($cache->getStore());
    $left = (int) round($storage[TenantKey::cache(SB_WS, 'sample:'.SB_OP)]['expiresAt'] - time());

    expect($left)->toBeGreaterThan(80)->toBeLessThanOrEqual(91);
});

it('reads nothing for another membership, Endpoint, revision, Operation or Workspace', function (string $workspace, string $operation, string $membership, string $endpoint, int $revision) {
    [$store, $cache] = sbStore($this->keyFile);
    $store->put(SB_WS, SB_OP, SB_ME, SB_EP, 4, SB_BODY, 600);

    // Move the sealed entry under the other key as well, so only the sealed payload can tell them apart.
    $cache->put(TenantKey::cache($workspace, 'sample:'.$operation), ['workspace_id' => $workspace, 'value' => sbRaw($cache)['value']], 600);

    expect($store->get($workspace, $operation, $membership, $endpoint, $revision))->toBeNull();
})->with([
    'another membership' => [SB_WS, SB_OP, '018f0000-0000-7000-8000-0000000000dd', SB_EP, 4],
    'another Endpoint' => [SB_WS, SB_OP, SB_ME, '018f0000-0000-7000-8000-0000000000ee', 4],
    'another revision' => [SB_WS, SB_OP, SB_ME, SB_EP, 5],
    'another Operation' => [SB_WS, '018f0000-0000-7000-8000-0000000000ab', SB_ME, SB_EP, 4],
    'another Workspace' => ['018f0000-0000-7000-8000-00000000000f', SB_OP, SB_ME, SB_EP, 4],
]);

it('treats a damaged entry, an entry sealed with another key and a missing key as a miss', function () {
    [$store, $cache] = sbStore($this->keyFile);
    $store->put(SB_WS, SB_OP, SB_ME, SB_EP, 4, SB_BODY, 600);
    $value = sbRaw($cache)['value'];

    $cache->put(TenantKey::cache(SB_WS, 'sample:'.SB_OP), ['workspace_id' => SB_WS, 'value' => substr($value, 0, -4).'AAAA'], 600);
    expect($store->get(SB_WS, SB_OP, SB_ME, SB_EP, 4))->toBeNull();

    $cache->put(TenantKey::cache(SB_WS, 'sample:'.SB_OP), ['workspace_id' => SB_WS, 'value' => 'not base64!'], 600);
    expect($store->get(SB_WS, SB_OP, SB_ME, SB_EP, 4))->toBeNull();

    $other = tempnam(sys_get_temp_dir(), 'dashflow-data-key');
    file_put_contents($other, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    $cache->put(TenantKey::cache(SB_WS, 'sample:'.SB_OP), ['workspace_id' => SB_WS, 'value' => $value], 600);
    [$foreign] = [new SampleBlobStore(new TenantCache($cache), new SecretSettings(new Repository(['dashflow' => ['secrets' => ['data_key_path' => ['value' => $other]]]])))];
    expect($foreign->get(SB_WS, SB_OP, SB_ME, SB_EP, 4))->toBeNull();
    @unlink($other);

    [$keyless] = sbStore(null);
    expect($keyless->get(SB_WS, SB_OP, SB_ME, SB_EP, 4))->toBeNull();
});

it('fails closed when there is no usable key: nothing is stored', function (string $content) {
    $file = tempnam(sys_get_temp_dir(), 'dashflow-data-key');

    file_put_contents($file, $content);

    [$store, $cache] = sbStore($file);

    expect(fn () => $store->put(SB_WS, SB_OP, SB_ME, SB_EP, 4, SB_BODY, 600))->toThrow(SampleBlobUnavailable::class)
        ->and(sbRaw($cache))->toBeNull();
    @unlink($file);
})->with([
    'the dev placeholder' => ['placeholder-not-a-real-key-data'],
    'a key of the wrong length' => [base64_encode('short')],
    'an empty file' => [''],
]);

it('fails closed when the key file is missing, and when the TTL is not positive', function () {
    [$store, $cache] = sbStore(null);

    expect(fn () => $store->put(SB_WS, SB_OP, SB_ME, SB_EP, 4, SB_BODY, 600))->toThrow(SampleBlobUnavailable::class);

    [$store, $cache] = sbStore($this->keyFile);

    expect(fn () => $store->put(SB_WS, SB_OP, SB_ME, SB_EP, 4, SB_BODY, 0))->toThrow(SampleBlobUnavailable::class)
        ->and(sbRaw($cache))->toBeNull();
});

it('fails closed when the cache refuses or breaks the write', function () {
    $settings = new SecretSettings(new Repository(['dashflow' => ['secrets' => ['data_key_path' => ['value' => $this->keyFile]]]]));
    $broken = Mockery::mock(CacheRepository::class);
    $broken->shouldReceive('put')->andThrow(new RuntimeException('down'));
    $refusing = Mockery::mock(CacheRepository::class);
    $refusing->shouldReceive('put')->andReturn(false);

    expect(fn () => (new SampleBlobStore(new TenantCache($broken), $settings))->put(SB_WS, SB_OP, SB_ME, SB_EP, 4, SB_BODY, 600))->toThrow(SampleBlobUnavailable::class)
        ->and(fn () => (new SampleBlobStore(new TenantCache($refusing), $settings))->put(SB_WS, SB_OP, SB_ME, SB_EP, 4, SB_BODY, 600))->toThrow(SampleBlobUnavailable::class);
});

it('forgets a sample', function () {
    [$store, $cache] = sbStore($this->keyFile);
    $store->put(SB_WS, SB_OP, SB_ME, SB_EP, 4, SB_BODY, 600);

    $store->forget(SB_WS, SB_OP);

    expect(sbRaw($cache))->toBeNull()->and($store->get(SB_WS, SB_OP, SB_ME, SB_EP, 4))->toBeNull();
});

it('keeps the body out of a dump of a Sample', function () {
    $sample = new Sample(200, 12, SB_BODY, '2026-10-09T10:00:00Z');

    expect(print_r($sample, true))->not->toContain('CANARY')->toContain('latencyMs');
});

it('reads the key path from dashflow.secrets.data_key_path and defaults to the key-data mount', function () {
    expect((new SecretSettings(new Repository([])))->dataKeyPath())->toBe('/run/secrets/key-data')
        ->and((new SecretSettings(new Repository(['dashflow' => ['secrets' => ['data_key_path' => ['value' => '']]]])))->dataKeyPath())->toBe('/run/secrets/key-data')
        ->and((new SecretSettings(new Repository(['dashflow' => ['secrets' => ['data_key_path' => ['value' => '/x/k']]]])))->dataKeyPath())->toBe('/x/k');
});
