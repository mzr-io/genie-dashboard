<?php

use App\Modules\Connector\Contracts\ConnectionTestCode;
use App\Modules\Connector\Contracts\CredentialScheme;
use App\Modules\Connector\Contracts\EgressReason;
use App\Modules\Connector\Contracts\EgressRequest;
use App\Modules\Connector\Contracts\EgressResponse;
use App\Modules\Connector\Contracts\EgressTransport;
use App\Modules\Connector\Contracts\FetchRequest;
use App\Modules\Connector\Contracts\SealedSecret;
use App\Modules\Connector\Contracts\SecretContext;
use App\Modules\Connector\Contracts\SecretMissing;
use App\Modules\Connector\Contracts\SecretRef;
use App\Modules\Connector\Contracts\SecretSlots;
use App\Modules\Connector\Contracts\SecretVault;
use App\Modules\Connector\Contracts\TokenRequestLog;
use App\Modules\Connector\Infrastructure\ConnectionTestSettings;
use App\Modules\Connector\Infrastructure\DirectFetchTransport;
use App\Modules\Connector\Infrastructure\OAuthTokenCache;
use App\Modules\Connector\Infrastructure\OAuthTokenClient;
use App\Modules\Connector\Infrastructure\SecretSettings;
use App\Platform\Tenancy\TenantCache;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Psr\Log\NullLogger;

// Story 2.5: the `direct` FetchTransport applies the credential scheme from resolved refs and sends through the egress
// transport only. The vault and the egress transport are fakes here; the real ones are covered by the Database suite.

const DFT_WS = '018f0000-0000-7000-8000-00000000000a';
const DFT_SRC = '018f0000-0000-7000-8000-00000000000b';
const DFT_OP = '018f0000-0000-7000-8000-00000000000c';

function dftVault(array $values): SecretVault
{
    return new class($values) implements SecretVault
    {
        /** @var list<array{0: SecretRef, 1: SecretContext}> */
        public array $resolved = [];

        public function __construct(private readonly array $values) {}

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
            $this->resolved[] = [$ref, $context];

            return $this->values[$ref->slot] ?? throw new SecretMissing;
        }

        public function status(string $workspaceId, string $dataSourceId): array
        {
            return [];
        }
    };
}

function dftEgress(int $status = 200, string $body = 'abc'): EgressTransport
{
    return new class($status, $body) implements EgressTransport
    {
        public ?EgressRequest $sent = null;

        public ?string $workspace = null;

        public function __construct(private readonly int $status, private readonly string $body) {}

        public function send(string $workspaceId, EgressRequest $request): EgressResponse
        {
            $this->workspace = $workspaceId;
            $this->sent = $request;

            return new EgressResponse($this->status, ['content-type' => ['application/json']], $this->body, $request->url);
        }
    };
}

/** The transport over fakes: a token client on the same fake egress, an array cache and settings without a token key. */
function dftTransport(EgressTransport $egress, SecretVault $vault, ?OAuthTokenCache $cache = null, array $settings = []): DirectFetchTransport
{
    $config = new Repository(['dashflow' => ['secrets' => [], 'oauth' => []] + $settings]);
    $secretSettings = new SecretSettings($config);

    return new DirectFetchTransport(
        $egress, $vault,
        new OAuthTokenClient($egress, dftTokenLog()),
        $cache ?? new OAuthTokenCache(new TenantCache(new CacheRepository(new ArrayStore)), $secretSettings, new NullLogger),
        $secretSettings,
    );
}

function dftTokenLog(): TokenRequestLog
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

/** @param list<SecretRef> $refs */
function dftRequest(CredentialScheme $scheme, array $refs, array $headers = [], ?string $apiKeyName = null, ?string $placement = null, ?string $source = DFT_SRC, ?int $timeout = null): FetchRequest
{
    return new FetchRequest(DFT_WS, $source, null, 'https://api.example.com/v1', [], $scheme, $refs, $headers, $apiKeyName, $placement, $timeout);
}

it('sends a GET with the plain default headers and no credential for scheme none, and reports status, bytes and latency', function () {
    $egress = dftEgress(200, 'twelve bytes');

    $response = (dftTransport($egress, dftVault([])))->fetch(dftRequest(CredentialScheme::None, [], [['name' => 'Accept', 'value' => 'application/json']]));

    expect($egress->workspace)->toBe(DFT_WS)
        ->and($egress->sent->url)->toBe('https://api.example.com/v1')
        ->and($egress->sent->method)->toBe('GET')
        ->and($egress->sent->headers)->toBe(['Accept' => 'application/json'])
        ->and($egress->sent->credentials)->toBe([])
        ->and($egress->sent->body)->toBeNull()
        ->and($response->status)->toBe(200)
        ->and($response->successful())->toBeTrue()
        ->and($response->bytes)->toBe(12)
        ->and($response->latencyMs)->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and(print_r($response, true))->not->toContain('twelve bytes');
});

it('applies each credential scheme from the resolved refs, as a credential the transport can strip', function (CredentialScheme $scheme, array $refs, array $values, array $headers, ?string $name, ?string $placement, string $url, array $credentials) {
    $egress = dftEgress();

    (dftTransport($egress, dftVault($values)))->fetch(dftRequest($scheme, $refs, $headers, $name, $placement));

    expect($egress->sent->url)->toBe($url)->and($egress->sent->credentials)->toBe($credentials);
})->with([
    'api key in a header' => [CredentialScheme::ApiKeyHeader, [new SecretRef('s1', 'api_key')], ['api_key' => 'k3y'], [], 'X-Api-Key', 'header', 'https://api.example.com/v1', ['X-Api-Key' => 'k3y']],
    'api key in the query, encoded' => [CredentialScheme::ApiKeyQuery, [new SecretRef('s1', 'api_key')], ['api_key' => 'a b&c=d'], [], 'api key', 'query', 'https://api.example.com/v1?api%20key=a%20b%26c%3Dd', []],
    'bearer' => [CredentialScheme::Bearer, [new SecretRef('s1', 'bearer_token')], ['bearer_token' => 't0k'], [], null, null, 'https://api.example.com/v1', ['Authorization' => 'Bearer t0k']],
    'basic' => [CredentialScheme::Basic, [new SecretRef('s1', 'basic_username'), new SecretRef('s2', 'basic_password')], ['basic_username' => 'ada', 'basic_password' => 'pw'], [], null, null, 'https://api.example.com/v1', ['Authorization' => 'Basic '.'YWRhOnB3']],
    'a secret default header' => [CredentialScheme::None, [new SecretRef('s1', 'header:x-token')], ['header:x-token' => 'h3'], [['name' => 'X-Token', 'value' => '', 'secret' => true]], null, null, 'https://api.example.com/v1', ['X-Token' => 'h3']],
]);

it('opens a stored secret for its Data Source and a transient one for its Operation, each for its own slot', function () {
    $vault = dftVault(['bearer_token' => 't', 'header:x-token' => 'h']);
    $refs = [new SecretRef('s1', 'bearer_token', operationId: DFT_OP), new SecretRef('s2', 'header:x-token')];

    (dftTransport(dftEgress(), $vault))->fetch(dftRequest(CredentialScheme::Bearer, $refs, [['name' => 'X-Token', 'value' => '', 'secret' => true]]));

    $contexts = array_map(fn (array $r): array => [$r[0]->slot, $r[1]->workspaceId, $r[1]->dataSourceId, $r[1]->slot, $r[1]->purpose], $vault->resolved);
    expect($contexts)->toEqualCanonicalizing([
        ['header:x-token', DFT_WS, DFT_SRC, 'header:x-token', 'cred'],
        ['bearer_token', DFT_WS, DFT_OP, 'bearer_token', 'cred'],
    ]);
});

it('fails with SecretMissing when the scheme needs a ref the request does not carry, and sends nothing', function () {
    $egress = dftEgress();

    expect(fn () => (dftTransport($egress, dftVault([])))->fetch(dftRequest(CredentialScheme::Bearer, [])))->toThrow(SecretMissing::class)
        ->and(fn () => (dftTransport($egress, dftVault([])))->fetch(dftRequest(CredentialScheme::Bearer, [new SecretRef('s', 'bearer_token')], source: null)))->toThrow(SecretMissing::class)
        ->and($egress->sent)->toBeNull();
});

it('refuses an API key request that has no name to send it under', function () {
    expect(fn () => (dftTransport(dftEgress(), dftVault(['api_key' => 'k'])))->fetch(dftRequest(CredentialScheme::ApiKeyHeader, [new SecretRef('s', 'api_key')])))
        ->toThrow(InvalidArgumentException::class);
});

it('hands the Data Source timeout to the egress transport', function () {
    $egress = dftEgress();

    (dftTransport($egress, dftVault([])))->fetch(dftRequest(CredentialScheme::None, [], timeout: 7));

    expect($egress->sent->timeoutSeconds)->toBe(7);
});

it('reports a non-2xx answer as unsuccessful without throwing', function (int $status, bool $ok) {
    $response = (dftTransport(dftEgress($status), dftVault([])))->fetch(dftRequest(CredentialScheme::None, []));

    expect($response->successful())->toBe($ok);
})->with([[199, false], [200, true], [204, true], [299, true], [301, false], [401, false], [500, false]]);

it('collapses a guard refusal to the three user codes: only the allowlist and address reasons keep their own', function (EgressReason $reason, ConnectionTestCode $code) {
    expect(ConnectionTestCode::forEgress($reason))->toBe($code);
})->with([
    [EgressReason::HostNotAllowlisted, ConnectionTestCode::HostNotAllowlisted],
    [EgressReason::BlockedAddress, ConnectionTestCode::BlockedAddress],
    [EgressReason::Unresolvable, ConnectionTestCode::FetchFailed],
    [EgressReason::RedirectRefused, ConnectionTestCode::FetchFailed],
    [EgressReason::InvalidUrl, ConnectionTestCode::FetchFailed],
    [EgressReason::SchemeNotAllowed, ConnectionTestCode::FetchFailed],
]);

it('lists the slots a form uses, with the field that holds each', function () {
    expect(SecretSlots::used('none', []))->toBe([])
        ->and(SecretSlots::used('basic', []))->toBe(['basic_username' => 'secrets.basic_username', 'basic_password' => 'secrets.basic_password'])
        ->and(SecretSlots::used('api_key', [['name' => 'A', 'value' => 'x'], ['name' => 'X-Token', 'value' => '', 'secret' => true]]))
        ->toBe(['api_key' => 'secrets.api_key', 'header:x-token' => 'headers.1.value']);
});

it('limits only with a window and a positive whole number, and invents nothing', function (array $config, ?int $window, ?int $member, ?int $workspace) {
    $settings = new ConnectionTestSettings(new Repository(['dashflow' => ['connection_test' => $config]]));

    expect([$settings->window(), $settings->membershipLimit(), $settings->workspaceLimit()])->toBe([$window, $member, $workspace]);
})->with([
    'nothing set' => [[], null, null, null],
    'all set' => [['window' => ['value' => '60'], 'membership_limit' => ['value' => '5'], 'workspace_limit' => ['value' => 20]], 60, 5, 20],
    'limits without a window' => [['membership_limit' => ['value' => '5'], 'workspace_limit' => ['value' => '9']], null, null, null],
    'zero, negative and text do not count' => [['window' => ['value' => '60'], 'membership_limit' => ['value' => '0'], 'workspace_limit' => ['value' => 'many']], 60, null, null],
    'a window of zero is no window' => [['window' => ['value' => '0'], 'membership_limit' => ['value' => '5']], null, null, null],
]);

// Story 2.6: JSON is asked for unless the Data Source sets its own Accept, and the Data Source's size limit goes to the transport.
it('asks for JSON unless the Data Source sets its own Accept (any case), and passes its size limit on', function () {
    $egress = dftEgress();
    (dftTransport($egress, dftVault([])))->fetch(new FetchRequest(DFT_WS, DFT_SRC, null, 'https://api.example.com/v1', [], CredentialScheme::None, [], [], maxResponseBytes: 4096));

    expect($egress->sent->headers)->toBe(['Accept' => 'application/json'])->and($egress->sent->maxBytes)->toBe(4096);

    $own = dftEgress();
    (dftTransport($own, dftVault([])))->fetch(dftRequest(CredentialScheme::None, [], [['name' => 'accept', 'value' => 'application/vnd.api+json']]));

    expect($own->sent->headers)->toBe(['accept' => 'application/vnd.api+json'])->and($own->sent->maxBytes)->toBeNull();
});

// Story 2.10: an Endpoint test sends the rendered URL, the query (one builder), the Endpoint's headers after the defaults and,
// for a read-only POST, the body and an Idempotency-Key. Nothing else is ever sent.
function dftEndpoint(string $method = 'GET', array $overrides = []): FetchRequest
{
    return new FetchRequest(
        DFT_WS, DFT_SRC, 'e', 'https://api.example.com/v1/customers/{id}', ['id', 'q'], CredentialScheme::None, [],
        $overrides['defaults'] ?? [['name' => 'X-Default', 'value' => 'd'], ['name' => 'X-Over', 'value' => 'default']], null, null, null, $method,
        url: 'https://api.example.com/v1/customers/c-42', queryPairs: $overrides['query'] ?? [['q', 'a b&c=d#e'], ['ünï', '1']],
        endpointHeaders: $overrides['headers'] ?? [['name' => 'x-over', 'value' => 'endpoint'], ['name' => 'X-Day', 'value' => '2026-01-01']],
        body: $overrides['body'] ?? null, idempotencyKey: $overrides['key'] ?? null, readOnlyQuery: $overrides['readOnly'] ?? false,
    );
}

it('sends the rendered URL with every query name and value percent-encoded, and the Endpoint headers after the defaults', function () {
    $egress = dftEgress();

    (dftTransport($egress, dftVault([])))->fetch(dftEndpoint());

    expect($egress->sent->url)->toBe('https://api.example.com/v1/customers/c-42?q=a%20b%26c%3Dd%23e&%C3%BCn%C3%AF=1')
        ->and($egress->sent->method)->toBe('GET')
        ->and($egress->sent->body)->toBeNull()
        ->and($egress->sent->headers)->toBe(['X-Default' => 'd', 'x-over' => 'endpoint', 'X-Day' => '2026-01-01', 'Accept' => 'application/json']);
});

it('adds the API key to the same query string', function () {
    $egress = dftEgress();
    $request = new FetchRequest(
        DFT_WS, DFT_SRC, 'e', 'https://api.example.com/v1/x', [], CredentialScheme::ApiKeyQuery, [new SecretRef('s1', 'api_key')], [], 'key', 'query', null, 'GET',
        url: 'https://api.example.com/v1/x', queryPairs: [['q', '1']],
    );

    (dftTransport($egress, dftVault(['api_key' => 'k&1'])))->fetch($request);

    expect($egress->sent->url)->toBe('https://api.example.com/v1/x?q=1&key=k%261');
});

it('sends a read-only POST once with its body and an Idempotency-Key equal to the Operation id', function () {
    $egress = dftEgress();

    (dftTransport($egress, dftVault([])))->fetch(dftEndpoint('POST', [
        'readOnly' => true, 'body' => '{"from":"2026-01-01"}', 'key' => DFT_OP,
        'headers' => [['name' => 'Content-Type', 'value' => 'application/json'], ['name' => 'idempotency-key', 'value' => 'client-chosen']],
    ]));

    expect($egress->sent->method)->toBe('POST')
        ->and($egress->sent->body)->toBe('{"from":"2026-01-01"}')
        ->and($egress->sent->headers['Idempotency-Key'])->toBe(DFT_OP)
        ->and(array_change_key_case($egress->sent->headers))->not->toHaveKey('client-chosen')
        ->and(array_filter(array_keys($egress->sent->headers), fn ($name) => strcasecmp($name, 'idempotency-key') === 0))->toHaveCount(1);
});

it('refuses a POST that is not read-only and any method but GET and POST, and sends nothing', function (string $method, bool $readOnly) {
    $egress = dftEgress();

    expect(fn () => (dftTransport($egress, dftVault([])))->fetch(dftEndpoint($method, ['readOnly' => $readOnly, 'body' => '{}'])))->toThrow(InvalidArgumentException::class)
        ->and($egress->sent)->toBeNull();
})->with([['POST', false], ['PUT', true], ['PATCH', true], ['DELETE', true], ['get', false]]);

it('does not send a body or an Idempotency-Key on a GET', function () {
    $egress = dftEgress();

    (dftTransport($egress, dftVault([])))->fetch(dftEndpoint('GET', ['body' => '{"a":1}', 'key' => DFT_OP]));

    expect($egress->sent->body)->toBeNull()->and($egress->sent->headers)->not->toHaveKey('Idempotency-Key');
});

it('lets an Endpoint header replace a secret default header of the same name', function () {
    $egress = dftEgress();
    $request = new FetchRequest(
        DFT_WS, DFT_SRC, 'e', 'https://api.example.com/v1', [], CredentialScheme::None, [new SecretRef('s1', 'header:x-token')],
        [['name' => 'X-Token', 'value' => '', 'secret' => true]], null, null, null, 'GET',
        endpointHeaders: [['name' => 'x-token', 'value' => 'endpoint']],
    );

    (dftTransport($egress, dftVault(['header:x-token' => 'secret'])))->fetch($request);

    expect($egress->sent->credentials)->toBe([])->and($egress->sent->headers['x-token'])->toBe('endpoint');
});
