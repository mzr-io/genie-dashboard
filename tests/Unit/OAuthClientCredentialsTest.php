<?php

use App\Modules\Connector\Contracts\AuthFailed;
use App\Modules\Connector\Contracts\CredentialScheme;
use App\Modules\Connector\Contracts\EgressReason;
use App\Modules\Connector\Contracts\EgressRequest;
use App\Modules\Connector\Contracts\EgressResponse;
use App\Modules\Connector\Contracts\EgressTransport;
use App\Modules\Connector\Contracts\EgressTransportFailed;
use App\Modules\Connector\Contracts\FetchRequest;
use App\Modules\Connector\Contracts\NotJsonResponse;
use App\Modules\Connector\Contracts\OAuthToken;
use App\Modules\Connector\Contracts\SealedSecret;
use App\Modules\Connector\Contracts\SecretContext;
use App\Modules\Connector\Contracts\SecretRef;
use App\Modules\Connector\Contracts\SecretVault;
use App\Modules\Connector\Contracts\SsrfBlocked;
use App\Modules\Connector\Contracts\TokenRequestFailed;
use App\Modules\Connector\Contracts\TokenRequestLog;
use App\Modules\Connector\Infrastructure\DirectFetchTransport;
use App\Modules\Connector\Infrastructure\OAuthTokenCache;
use App\Modules\Connector\Infrastructure\OAuthTokenClient;
use App\Modules\Connector\Infrastructure\SecretSettings;
use App\Platform\Json\InvalidLimitSetting;
use App\Platform\Tenancy\TenantCache;
use App\Platform\Tenancy\TenantKey;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Psr\Log\AbstractLogger;

// Story 2.7: OAuth2 client credentials in the `direct` FetchTransport. The egress transport, the vault and the cache store are
// fakes: the token request, the encrypted cache, the 401 rule and what must never be written are checked in process.

const OA_WS = '018f0000-0000-7000-8000-00000000000a';
const OA_SRC = '018f0000-0000-7000-8000-00000000000b';
const OA_TOKEN_URL = 'https://auth.example.com/oauth/token';
const OA_API_URL = 'https://api.example.com/v1';
const OA_SECRET = 'CANARY-client-secret-91c';
const OA_TOKEN = 'CANARY-access-token-5e7';

/** An answer of the token endpoint. */
function oaToken(string $token = OA_TOKEN, ?string $expires = '3600', string $type = 'Bearer'): EgressResponse
{
    $body = '{"access_token":"'.$token.'","token_type":"'.$type.'"'.($expires === null ? '' : ',"expires_in":'.$expires).'}';

    return new EgressResponse(200, ['content-type' => ['application/json']], $body, OA_TOKEN_URL);
}

function oaApi(int $status = 200): EgressResponse
{
    return new EgressResponse($status, ['content-type' => ['application/json']], '{"ok":true}', OA_API_URL);
}

/** An egress that answers the token URL and the API from two queues and records every request. */
function oaEgress(array $tokens, array $api): EgressTransport
{
    return new class($tokens, $api) implements EgressTransport
    {
        /** @var list<EgressRequest> */
        public array $tokenRequests = [];

        /** @var list<EgressRequest> */
        public array $apiRequests = [];

        public function __construct(private array $tokens, private array $api) {}

        public function send(string $workspaceId, EgressRequest $request): EgressResponse
        {
            if ($request->url === OA_TOKEN_URL) {
                $this->tokenRequests[] = $request;
                $next = array_shift($this->tokens) ?? throw new LogicException('No token answer left.');
            } else {
                $this->apiRequests[] = $request;
                $next = array_shift($this->api) ?? throw new LogicException('No API answer left.');
            }

            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next;
        }
    };
}

function oaVault(): SecretVault
{
    return new class implements SecretVault
    {
        public int $resolved = 0;

        public function seal(SecretContext $context, string $value): SealedSecret
        {
            throw new LogicException('not used');
        }

        public function open(SecretContext $context, string $ciphertext): string
        {
            throw new LogicException('not used');
        }

        public function resolve(SecretRef $ref, SecretContext $context): string
        {
            $this->resolved++;

            return OA_SECRET;
        }

        public function status(string $workspaceId, string $dataSourceId): array
        {
            return [];
        }
    };
}

function oaLog(): TokenRequestLog
{
    return new class implements TokenRequestLog
    {
        /** @var list<array<string, mixed>> */
        public array $rows = [];

        public function record(string $workspaceId, ?string $dataSourceId, string $tokenUrl, ?int $httpStatus, int $latencyMs, int $bytes, ?string $code, DateTimeInterface $startedAt): void
        {
            $this->rows[] = compact('workspaceId', 'dataSourceId', 'tokenUrl', 'httpStatus', 'bytes', 'code');
        }
    };
}

/** A logger that keeps every record, to look at what was (and was not) written. */
function oaLogger(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        /** @var list<array{0: string, 1: string, 2: array<mixed>}> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = [(string) $level, (string) $message, $context];
        }
    };
}

/** @return array{0: DirectFetchTransport, 1: TokenRequestLog, 2: SecretVault, 3: CacheRepository, 4: OAuthTokenCache, 5: AbstractLogger} */
function oaStack(EgressTransport $egress, ?string $keyFile, array $oauth = []): array
{
    $config = new Repository(['dashflow' => ['secrets' => ['token_key_path' => ['value' => $keyFile ?? '/nonexistent/key-token']], 'oauth' => $oauth]]);
    $settings = new SecretSettings($config);
    $store = new CacheRepository(new ArrayStore);
    $logger = oaLogger();
    $cache = new OAuthTokenCache(new TenantCache($store), $settings, $logger);
    $log = oaLog();
    $vault = oaVault();

    return [new DirectFetchTransport($egress, $vault, new OAuthTokenClient($egress, $log), $cache, $settings), $log, $vault, $store, $cache, $logger];
}

function oaKeyFile(): string
{
    $file = tempnam(sys_get_temp_dir(), 'dashflow-token-key');
    file_put_contents($file, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));

    return $file;
}

function oaRequest(int $version = 1, ?string $dataSourceId = OA_SRC, bool $transient = false, ?string $scope = 'read write'): FetchRequest
{
    return new FetchRequest(
        OA_WS, $dataSourceId, null, OA_API_URL, [], CredentialScheme::OAuth2ClientCredentials,
        [new SecretRef('s1', 'oauth_client_secret', operationId: $transient ? '018f0000-0000-7000-8000-00000000000c' : null, secretVersion: $version)],
        [], null, null, null, 'GET', null, OA_TOKEN_URL, 'client-1', $scope, $version,
    );
}

/** The seconds a cache entry has left, read from the array store. */
function oaTtl(CacheRepository $store, string $dataSourceId, int $version): ?int
{
    $storage = (new ReflectionProperty(ArrayStore::class, 'storage'))->getValue($store->getStore());
    $entry = $storage[TenantKey::cache(OA_WS, "oauth:{$dataSourceId}:{$version}")] ?? null;

    return $entry === null ? null : (int) round($entry['expiresAt'] - time());
}

function oaRaw(CacheRepository $store, string $dataSourceId, int $version): ?array
{
    return $store->get(TenantKey::cache(OA_WS, "oauth:{$dataSourceId}:{$version}"));
}

beforeEach(function () {
    $this->keyFile = oaKeyFile();
});

afterEach(function () {
    @unlink($this->keyFile);
});

it('requests a token through the guard on a first call, caches it encrypted, and sends the call with the Bearer', function () {
    $egress = oaEgress([oaToken()], [oaApi()]);
    [$transport, $log, $vault, $store] = oaStack($egress, $this->keyFile);

    $response = $transport->fetch(oaRequest());

    expect($response->status)->toBe(200)
        ->and($egress->tokenRequests)->toHaveCount(1)
        ->and($egress->apiRequests)->toHaveCount(1)
        ->and($egress->apiRequests[0]->credentials)->toBe(['Authorization' => 'Bearer '.OA_TOKEN]);

    $token = $egress->tokenRequests[0];
    parse_str((string) $token->body, $form);

    expect($token->method)->toBe('POST')
        ->and($token->refuseRedirects)->toBeTrue()
        ->and($token->headers['Content-Type'])->toBe('application/x-www-form-urlencoded')
        ->and($token->credentials)->toBe([])
        ->and($form)->toBe(['grant_type' => 'client_credentials', 'client_id' => 'client-1', 'client_secret' => OA_SECRET, 'scope' => 'read write'])
        ->and($log->rows)->toHaveCount(1)
        ->and($log->rows[0])->toMatchArray(['workspaceId' => OA_WS, 'dataSourceId' => OA_SRC, 'tokenUrl' => OA_TOKEN_URL, 'httpStatus' => 200, 'code' => null]);

    // Encrypted in the cache: the stored value is not the token, and it lives under oauth:{ds}:{version} for 3600 s.
    $stored = oaRaw($store, OA_SRC, 1);
    expect($stored['workspace_id'])->toBe(OA_WS)
        ->and(json_encode($stored))->not->toContain(OA_TOKEN)
        ->and(base64_decode($stored['value'], true))->not->toBeFalse()
        ->and(oaTtl($store, OA_SRC, 1))->toBeBetween(3598, 3601);
});

it('leaves the scope out of the token request when there is none', function () {
    $egress = oaEgress([oaToken()], [oaApi()]);
    [$transport] = oaStack($egress, $this->keyFile);

    $transport->fetch(oaRequest(scope: null));
    parse_str((string) $egress->tokenRequests[0]->body, $form);

    expect($form)->not->toHaveKey('scope');
});

it('uses the cached token without a token request or opening the client secret', function () {
    $egress = oaEgress([oaToken()], [oaApi(), oaApi()]);
    [$transport, , $vault] = oaStack($egress, $this->keyFile);

    $transport->fetch(oaRequest());
    $transport->fetch(oaRequest());

    expect($egress->tokenRequests)->toHaveCount(1)
        ->and($egress->apiRequests)->toHaveCount(2)
        ->and($egress->apiRequests[1]->credentials)->toBe(['Authorization' => 'Bearer '.OA_TOKEN])
        ->and($vault->resolved)->toBe(1);
});

it('drops the cache entry on a 401, asks for a fresh token once and repeats the call once', function () {
    $egress = oaEgress([oaToken('first-token'), oaToken('second-token')], [oaApi(401), oaApi(200)]);
    [$transport, $log, , $store] = oaStack($egress, $this->keyFile);

    $response = $transport->fetch(oaRequest());

    expect($response->status)->toBe(200)
        ->and($egress->tokenRequests)->toHaveCount(2)
        ->and($egress->apiRequests)->toHaveCount(2)
        ->and($egress->apiRequests[0]->credentials)->toBe(['Authorization' => 'Bearer first-token'])
        ->and($egress->apiRequests[1]->credentials)->toBe(['Authorization' => 'Bearer second-token'])
        ->and($log->rows)->toHaveCount(2);

    // The cache now holds the fresh token.
    expect(oaRaw($store, OA_SRC, 1))->not->toBeNull();
});

it('raises AuthFailed on a second 401 and tries nothing more', function () {
    $egress = oaEgress([oaToken('first'), oaToken('second'), oaToken('third')], [oaApi(401), oaApi(401), oaApi(200)]);
    [$transport] = oaStack($egress, $this->keyFile);

    expect(fn () => $transport->fetch(oaRequest()))->toThrow(AuthFailed::class);
    expect($egress->tokenRequests)->toHaveCount(2)->and($egress->apiRequests)->toHaveCount(2);

    try {
        $transport->fetch(oaRequest());
    } catch (AuthFailed $e) {
        expect($e->retryable)->toBeFalse()->and($e->code()->value)->toBe('connector.auth_failed');
    }
});

it('never uses a token cached for an earlier secret version: a Replace raises the version and so the key', function () {
    $egress = oaEgress([oaToken('v1-token'), oaToken('v2-token')], [oaApi(), oaApi()]);
    [$transport, , , $store] = oaStack($egress, $this->keyFile);

    $transport->fetch(oaRequest(version: 1));
    $transport->fetch(oaRequest(version: 2));

    expect($egress->tokenRequests)->toHaveCount(2)
        ->and($egress->apiRequests[1]->credentials)->toBe(['Authorization' => 'Bearer v2-token'])
        ->and(oaRaw($store, OA_SRC, 1))->not->toBeNull()
        ->and(oaRaw($store, OA_SRC, 2))->not->toBeNull();
});

it('treats an entry moved to another Data Source, version, Workspace or endpoint as a miss', function () {
    $egress = oaEgress([], []);
    [, , , $store, $cache] = oaStack($egress, $this->keyFile);
    $binding = OAuthTokenCache::binding(OA_TOKEN_URL, 'client-1', null);
    $other = '018f0000-0000-7000-8000-0000000000ff';

    $cache->put(OA_WS, OA_SRC, 1, $binding, 'tok', 60);
    expect($cache->get(OA_WS, OA_SRC, 1, $binding))->toBe('tok')
        // The token URL, client ID or scope changed since the token was requested.
        ->and($cache->get(OA_WS, OA_SRC, 1, OAuthTokenCache::binding('https://evil.example.com/token', 'client-1', null)))->toBeNull()
        ->and($cache->get(OA_WS, OA_SRC, 1, OAuthTokenCache::binding(OA_TOKEN_URL, 'client-2', null)))->toBeNull()
        ->and($cache->get(OA_WS, OA_SRC, 1, OAuthTokenCache::binding(OA_TOKEN_URL, 'client-1', 'admin')))->toBeNull();

    // The same sealed value copied under another Data Source's, version's or Workspace's key does not open there.
    $sealed = oaRaw($store, OA_SRC, 1);
    $store->put(TenantKey::cache(OA_WS, "oauth:{$other}:1"), $sealed, 60);
    $store->put(TenantKey::cache(OA_WS, 'oauth:'.OA_SRC.':2'), $sealed, 60);
    $store->put(TenantKey::cache($other, 'oauth:'.OA_SRC.':1'), ['workspace_id' => $other, 'value' => $sealed['value']], 60);

    expect($cache->get(OA_WS, $other, 1, $binding))->toBeNull()
        ->and($cache->get(OA_WS, OA_SRC, 2, $binding))->toBeNull()
        ->and($cache->get($other, OA_SRC, 1, $binding))->toBeNull();
});

it('treats a damaged or foreign-key entry as a miss', function () {
    [, , , $store, $cache] = oaStack(oaEgress([], []), $this->keyFile);
    $binding = OAuthTokenCache::binding(OA_TOKEN_URL, 'client-1', null);
    $cache->put(OA_WS, OA_SRC, 1, $binding, 'tok', 60);

    $sealed = oaRaw($store, OA_SRC, 1);
    $raw = base64_decode($sealed['value'], true);
    $raw[30] = chr(ord($raw[30]) ^ 1);
    $store->put(TenantKey::cache(OA_WS, 'oauth:'.OA_SRC.':1'), ['workspace_id' => OA_WS, 'value' => base64_encode($raw)], 60);

    expect($cache->get(OA_WS, OA_SRC, 1, $binding))->toBeNull();

    // Another key (a rotated mount) cannot open an older entry either.
    $cache->put(OA_WS, OA_SRC, 1, $binding, 'tok', 60);
    $rotated = oaKeyFile();
    $rotatedCache = new OAuthTokenCache(new TenantCache($store), new SecretSettings(new Repository(['dashflow' => ['secrets' => ['token_key_path' => ['value' => $rotated]]]])), oaLogger());

    expect($rotatedCache->get(OA_WS, OA_SRC, 1, $binding))->toBeNull();
    @unlink($rotated);
});

it('caches for expires_in with no skew when the setting is unset', function () {
    $egress = oaEgress([oaToken(expires: '1200')], [oaApi()]);
    [$transport, , , $store] = oaStack($egress, $this->keyFile);

    $transport->fetch(oaRequest());

    expect(oaTtl($store, OA_SRC, 1))->toBeBetween(1198, 1201);
});

it('caches for expires_in minus the skew setting', function () {
    $egress = oaEgress([oaToken(expires: '1200')], [oaApi()]);
    [$transport, , , $store] = oaStack($egress, $this->keyFile, ['token_skew_seconds' => ['value' => '300']]);

    $transport->fetch(oaRequest());

    expect(oaTtl($store, OA_SRC, 1))->toBeBetween(898, 901);
});

it('does not cache a token with no usable expiry, or one at or below the skew, and uses it for the one call', function (?string $expires, array $oauth) {
    $egress = oaEgress([oaToken(expires: $expires)], [oaApi()]);
    [$transport, , , $store] = oaStack($egress, $this->keyFile, $oauth);

    $response = $transport->fetch(oaRequest());

    expect($response->status)->toBe(200)
        ->and($egress->apiRequests[0]->credentials)->toBe(['Authorization' => 'Bearer '.OA_TOKEN])
        ->and(oaRaw($store, OA_SRC, 1))->toBeNull();
})->with([
    'no expires_in' => [null, []],
    'zero' => ['0', []],
    'equal to the skew' => ['300', ['token_skew_seconds' => ['value' => '300']]],
    'below the skew' => ['120', ['token_skew_seconds' => ['value' => 300]]],
    'a string is not numeric' => ['"3600"', []],
    'negative' => ['-5', []],
]);

it('fails closed on a skew that is set but is not a whole number of zero or more', function (mixed $value) {
    $egress = oaEgress([oaToken()], [oaApi()]);
    [$transport] = oaStack($egress, $this->keyFile, ['token_skew_seconds' => ['value' => $value]]);

    expect(fn () => $transport->fetch(oaRequest()))->toThrow(InvalidLimitSetting::class)
        ->and($egress->tokenRequests)->toBe([]);
})->with(['text' => ['soon'], 'negative' => ['-1'], 'decimal' => ['1.5']]);

it('treats a skew of 0 as no skew', function () {
    $egress = oaEgress([oaToken(expires: '600')], [oaApi()]);
    [$transport, , , $store] = oaStack($egress, $this->keyFile, ['token_skew_seconds' => ['value' => '0']]);

    $transport->fetch(oaRequest());

    expect(oaTtl($store, OA_SRC, 1))->toBeBetween(598, 601);
});

it('uses the token for that call only, caches nothing and warns once without a value when the token key is unavailable', function (?string $keyFile) {
    $egress = oaEgress([oaToken(), oaToken()], [oaApi(), oaApi()]);
    [$transport, , , $store, , $logger] = oaStack($egress, $keyFile);

    $transport->fetch(oaRequest());
    $transport->fetch(oaRequest());

    // No key, so no caching: each call asked for its own token, and nothing is in the cache store.
    expect($egress->tokenRequests)->toHaveCount(2)
        ->and(oaRaw($store, OA_SRC, 1))->toBeNull()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0][0])->toBe('warning')
        ->and(json_encode($logger->records))->not->toContain(OA_TOKEN)->not->toContain(OA_SECRET)->not->toContain('nonexistent');
})->with([
    'a missing file' => [null],
    'a path that is not a file' => ['/tmp'],
]);

it('treats a placeholder that is not a base64 32-byte key as no key', function () {
    $placeholder = tempnam(sys_get_temp_dir(), 'dashflow-placeholder');
    file_put_contents($placeholder, 'placeholder-not-a-real-key-token');

    $egress = oaEgress([oaToken()], [oaApi()]);
    [$transport, , , $store, , $logger] = oaStack($egress, $placeholder);
    $transport->fetch(oaRequest());

    expect(oaRaw($store, OA_SRC, 1))->toBeNull()->and($logger->records)->toHaveCount(1);
    @unlink($placeholder);
});

it('never caches a token requested with a secret typed into a form, or for a form with no Data Source', function (bool $transient, ?string $dataSourceId) {
    $egress = oaEgress([oaToken()], [oaApi()]);
    [$transport, , , $store] = oaStack($egress, $this->keyFile);

    $transport->fetch(oaRequest(transient: $transient, dataSourceId: $dataSourceId));

    expect(oaRaw($store, OA_SRC, 1))->toBeNull();
})->with([
    'typed secret of a saved source' => [true, OA_SRC],
    'a draft' => [true, null],
]);

it('maps a token endpoint answer of 400 or 401 to AuthFailed, logged as auth-failed, never retried', function (int $status) {
    $answer = new EgressResponse($status, ['content-type' => ['application/json']], '{"error":"invalid_client"}', OA_TOKEN_URL);
    $egress = oaEgress([$answer], []);
    [$transport, $log] = oaStack($egress, $this->keyFile);

    expect(fn () => $transport->fetch(oaRequest()))->toThrow(AuthFailed::class)
        ->and($egress->tokenRequests)->toHaveCount(1)
        ->and($egress->apiRequests)->toBe([])
        ->and($log->rows[0])->toMatchArray(['httpStatus' => $status, 'code' => 'auth-failed']);
})->with([400, 401]);

it('fails a token answer that is not usable with the mapped exception and sends no API call', function (EgressResponse $answer, string $exception, string $code) {
    $egress = oaEgress([$answer], []);
    [$transport, $log] = oaStack($egress, $this->keyFile);

    expect(fn () => $transport->fetch(oaRequest()))->toThrow($exception)
        ->and($egress->apiRequests)->toBe([])
        ->and($log->rows[0]['code'])->toBe($code);
})->with([
    'HTML' => [new EgressResponse(200, ['content-type' => ['text/html']], '<html>login</html>', OA_TOKEN_URL), NotJsonResponse::class, 'not-json'],
    'malformed JSON' => [new EgressResponse(200, ['content-type' => ['application/json']], '{"access_token":', OA_TOKEN_URL), NotJsonResponse::class, 'not-json'],
    'no Content-Type' => [new EgressResponse(200, [], '{"access_token":"a","token_type":"bearer"}', OA_TOKEN_URL), NotJsonResponse::class, 'not-json'],
    'a server error' => [new EgressResponse(503, ['content-type' => ['application/json']], '{}', OA_TOKEN_URL), TokenRequestFailed::class, 'fetch-failed'],
    'a redirect answer' => [new EgressResponse(302, ['location' => ['https://elsewhere.example.com/']], '', OA_TOKEN_URL), TokenRequestFailed::class, 'fetch-failed'],
    'a token that is not a string' => [new EgressResponse(200, ['content-type' => ['application/json']], '{"access_token":12345,"token_type":"bearer"}', OA_TOKEN_URL), TokenRequestFailed::class, 'fetch-failed'],
    'a token that is null' => [new EgressResponse(200, ['content-type' => ['application/json']], '{"access_token":null,"token_type":"bearer"}', OA_TOKEN_URL), TokenRequestFailed::class, 'fetch-failed'],
    'an empty token' => [new EgressResponse(200, ['content-type' => ['application/json']], '{"access_token":"","token_type":"bearer"}', OA_TOKEN_URL), TokenRequestFailed::class, 'fetch-failed'],
    'a token with a line break' => [new EgressResponse(200, ['content-type' => ['application/json']], '{"access_token":"a\\r\\nX: y","token_type":"bearer"}', OA_TOKEN_URL), TokenRequestFailed::class, 'fetch-failed'],
    'another token type' => [new EgressResponse(200, ['content-type' => ['application/json']], '{"access_token":"a","token_type":"mac"}', OA_TOKEN_URL), TokenRequestFailed::class, 'fetch-failed'],
    'no token type' => [new EgressResponse(200, ['content-type' => ['application/json']], '{"access_token":"a"}', OA_TOKEN_URL), TokenRequestFailed::class, 'fetch-failed'],
    'a list, not an object' => [new EgressResponse(200, ['content-type' => ['application/json']], '["a"]', OA_TOKEN_URL), TokenRequestFailed::class, 'fetch-failed'],
]);

it('accepts the bearer token type in any case', function (string $type) {
    $egress = oaEgress([oaToken(type: $type)], [oaApi()]);
    [$transport] = oaStack($egress, $this->keyFile);

    expect($transport->fetch(oaRequest())->status)->toBe(200);
})->with(['Bearer', 'bearer', 'BEARER', 'bEaReR']);

it('passes a transport failure of the token request through and records it as fetch-failed', function () {
    $egress = oaEgress([new EgressTransportFailed('x', 28)], []);
    [$transport, $log] = oaStack($egress, $this->keyFile);

    expect(fn () => $transport->fetch(oaRequest()))->toThrow(EgressTransportFailed::class)
        ->and($log->rows[0]['code'])->toBe('fetch-failed');
});

it('passes a guard block of the token URL through, recorded as its user code', function (EgressReason $reason, string $code) {
    $egress = oaEgress([new SsrfBlocked($reason)], []);
    [$transport, $log] = oaStack($egress, $this->keyFile);

    expect(fn () => $transport->fetch(oaRequest()))->toThrow(SsrfBlocked::class)
        ->and($egress->apiRequests)->toBe([])
        ->and($log->rows[0]['code'])->toBe($code);
})->with([
    [EgressReason::HostNotAllowlisted, 'host-not-allowlisted'],
    [EgressReason::BlockedAddress, 'blocked-address'],
]);

it('keeps the token, the client secret and the Authorization value out of logs, the token log and dumps (canary)', function () {
    $egress = oaEgress([oaToken(), oaToken()], [oaApi(401), oaApi(401)]);
    [$transport, $log, , , , $logger] = oaStack($egress, null);

    try {
        $transport->fetch(oaRequest());
    } catch (AuthFailed $e) {
        $caught = $e;
    }

    $issued = new OAuthToken(OA_TOKEN, 3600);

    $everything = json_encode([$logger->records, $log->rows, $caught->getMessage(), $caught->getTraceAsString(), print_r($issued, true), oaRequest()->toArray()], JSON_THROW_ON_ERROR);

    expect($caught)->toBeInstanceOf(AuthFailed::class)
        ->and($everything)->not->toContain(OA_TOKEN)->not->toContain(OA_SECRET)->not->toContain('Bearer ');
});

it('forgets a rejected token only while it is the one cached: a fresher token another worker stored stays', function () {
    [, , , $store, $cache] = oaStack(oaEgress([], []), $this->keyFile);
    $binding = OAuthTokenCache::binding(OA_TOKEN_URL, 'client-1', null);

    $cache->put(OA_WS, OA_SRC, 1, $binding, 'fresh-token', 60);
    $cache->forgetIfRejected(OA_WS, OA_SRC, 1, $binding, 'stale-token');
    expect($cache->get(OA_WS, OA_SRC, 1, $binding))->toBe('fresh-token');

    $cache->forgetIfRejected(OA_WS, OA_SRC, 1, $binding, 'fresh-token');
    expect(oaRaw($store, OA_SRC, 1))->toBeNull();
});

it('does not delete a fresher cached token when the call with the old one is rejected', function () {
    $egress = oaEgress([oaToken('new-token')], [oaApi(401), oaApi(200)]);
    [$transport, , , , $cache] = oaStack($egress, $this->keyFile);
    $binding = OAuthTokenCache::binding(OA_TOKEN_URL, 'client-1', 'read write');
    $cache->put(OA_WS, OA_SRC, 1, $binding, 'old-token', 60);

    $transport->fetch(oaRequest());

    expect($egress->apiRequests[0]->credentials)->toBe(['Authorization' => 'Bearer old-token'])
        ->and($egress->apiRequests[1]->credentials)->toBe(['Authorization' => 'Bearer new-token'])
        ->and($cache->get(OA_WS, OA_SRC, 1, $binding))->toBe('new-token');
});
