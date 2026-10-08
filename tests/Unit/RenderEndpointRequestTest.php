<?php

use App\Modules\Connector\Application\RenderEndpointRequest;
use App\Modules\Connector\Contracts\DataSource;
use App\Modules\Connector\Contracts\Endpoint;
use App\Modules\Connector\Contracts\EndpointQuery;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\SecretStatus;

// Story 2.10: the server renders the request from the Endpoint's current revision and the Admin's test values. Values are
// checked with the rules of a save; a refusal names the parameter and never echoes the value.

function rerSource(string $baseUrl = 'https://api.example.com/v1'): DataSource
{
    return new DataSource('d', 'Sales', $baseUrl, 'https', 'api.example.com', 443, 'none', [['name' => 'X-Default', 'value' => 'd']], 30, 4096, null, false, 1, 't', 't');
}

/** @param list<array<string, mixed>> $params */
function rerEndpoint(array $params = [], array $headers = [], string $method = 'GET', string $path = '/customers/{id}/revenue', ?string $body = null, bool $readOnly = false): Endpoint
{
    $ast = [];

    foreach (explode('/', substr($path, 1)) as $part) {
        $ast[] = preg_match('/\A\{(.+)\}\z/', $part, $m) === 1 ? ['type' => 'param', 'name' => $m[1]] : ['type' => 'literal', 'value' => $part];
    }

    return new Endpoint('e', 'd', 3, 'r', $method, $path, $ast, $params, $headers, $body, $readOnly, 't', 't');
}

const RER_PARAMS = [
    ['name' => 'id', 'binding' => 'fixed', 'value' => 'c-42', 'kind' => 'path'],
    ['name' => 'from', 'binding' => 'date_range_from', 'value' => null, 'kind' => 'query'],
    ['name' => 'limit', 'binding' => 'fixed', 'value' => '10', 'kind' => 'query'],
];

function rerRefused(Endpoint $endpoint, array $given): InvalidDataSource
{
    try {
        (new RenderEndpointRequest)->values($endpoint, $given);
    } catch (InvalidDataSource $e) {
        return $e;
    }

    throw new LogicException('The values were accepted.');
}

it('uses a fixed parameter\'s stored value as the default and needs an ISO date for a date or period parameter', function () {
    $values = (new RenderEndpointRequest)->values(rerEndpoint(RER_PARAMS), ['from' => '2026-02-28']);

    expect($values)->toBe(['id' => 'c-42', 'from' => '2026-02-28', 'limit' => '10']);

    $override = (new RenderEndpointRequest)->values(rerEndpoint(RER_PARAMS), ['from' => '2026-02-28', 'limit' => '25', 'unknown' => 'ignored']);

    expect($override['limit'])->toBe('25')->and($override)->not->toHaveKey('unknown');
});

it('refuses a missing value, naming the parameter', function () {
    $e = rerRefused(rerEndpoint(RER_PARAMS), []);

    expect(array_keys($e->errors))->toBe(['values.from'])
        ->and($e->errors['values.from'][0])->toContain('from')
        ->and($e->reasons)->toBe(['values.from' => 'param-value-required']);
});

it('refuses an empty value for a path, query and body parameter alike, even a fixed one', function () {
    $e = rerRefused(rerEndpoint(RER_PARAMS), ['from' => '2026-01-01', 'id' => '', 'limit' => '']);

    expect(array_keys($e->errors))->toBe(['values.id', 'values.limit']);
});

it('refuses a path value with a slash or a dot segment, a bad date and a header with CR or LF, all at once', function (array $given, string $field, string $reason) {
    $endpoint = rerEndpoint(RER_PARAMS, [['name' => 'X-Day', 'binding' => 'period_start', 'value' => null], ['name' => 'X-Team', 'binding' => 'fixed', 'value' => 'finance']]);
    $e = rerRefused($endpoint, $given + ['from' => '2026-01-01', 'header:X-Day' => '2026-01-01']);

    expect($e->errors)->toHaveKey("values.{$field}")
        ->and($e->reasons["values.{$field}"])->toBe($reason)
        ->and(json_encode($e->errors))->not->toContain('CANARY');
})->with([
    'slash' => [['id' => 'a/CANARY'], 'id', 'param-value-invalid'],
    'dot' => [['id' => '.'], 'id', 'param-value-invalid'],
    'dot dot' => [['id' => '..'], 'id', 'param-value-invalid'],
    'not a date' => [['from' => 'CANARY'], 'from', 'param-date-invalid'],
    'impossible date' => [['from' => '2026-02-30'], 'from', 'param-date-invalid'],
    'wrong shape' => [['from' => '2026-1-1'], 'from', 'param-date-invalid'],
    'control character' => [['limit' => "1\x00CANARY"], 'limit', 'param-value-invalid'],
    'header bound to a date' => [['header:X-Day' => 'CANARY'], 'header:X-Day', 'param-date-invalid'],
    'header with LF' => [['header:X-Team' => "a\nCANARY: b"], 'header:X-Team', 'header-value-invalid'],
    'header with CR' => [['header:X-Team' => "a\rb"], 'header:X-Team', 'header-value-invalid'],
    'header outside ASCII' => [['header:X-Team' => 'caf'."\u{e9}"], 'header:X-Team', 'header-value-invalid'],
]);

it('refuses values that are not a map of text', function (array $given) {
    expect(rerRefused(rerEndpoint(RER_PARAMS), $given)->reasons)->toBe(['values' => 'values-invalid']);
})->with([[['id' => ['a']]], [['id' => 5]], [['id' => null]]]);

it('renders the path against the base URL only, with each value percent-encoded as one segment', function () {
    $renderer = new RenderEndpointRequest;
    $endpoint = rerEndpoint(RER_PARAMS);
    $values = $renderer->values($endpoint, ['id' => 'a b%2e&?#', 'from' => '2026-01-01']);

    $request = $renderer->request('w', rerSource('https://api.example.com/v1/'), $endpoint, $values, [], 'op');

    expect($request->url)->toBe('https://api.example.com/v1/customers/a%20b%252e%26%3F%23/revenue')
        ->and($request->urlTemplate)->toBe('https://api.example.com/v1/customers/{id}/revenue')
        ->and($request->parameterNames)->toBe(['id', 'from', 'limit'])
        ->and($request->queryPairs)->toBe([['from', '2026-01-01'], ['limit', '10']])
        ->and($request->method)->toBe('GET')
        ->and($request->idempotencyKey)->toBeNull()
        ->and($request->body)->toBeNull()
        ->and($request->headers)->toBe([['name' => 'X-Default', 'value' => 'd']])
        ->and($request->timeoutSeconds)->toBe(30)
        ->and($request->maxResponseBytes)->toBe(4096);
});

it('cannot leave the base URL however the value is written', function (string $value) {
    $renderer = new RenderEndpointRequest;
    $endpoint = rerEndpoint([['name' => 'id', 'binding' => 'fixed', 'value' => 'x', 'kind' => 'path']], path: '/c/{id}');

    $url = $renderer->request('w', rerSource(), $endpoint, ['id' => $value], [], 'op')->url;

    expect(str_starts_with($url, 'https://api.example.com/v1/c/'))->toBeTrue()
        ->and(substr_count(substr($url, strlen('https://api.example.com')), '/'))->toBe(3)
        ->and(parse_url($url, PHP_URL_HOST))->toBe('api.example.com');
})->with(['@evil.example', ':8080', 'evil.example', '%2F..%2F', "a\tb", '\\evil', 'ünï']);

it('puts every non-path parameter through the one query builder, names included', function () {
    expect(EndpointQuery::build([['a b', 'x&y=z'], ['q[]', '€'], ['k', '']]))->toBe('a%20b=x%26y%3Dz&q%5B%5D=%E2%82%AC&k=')
        ->and(EndpointQuery::append('https://h/p', []))->toBe('https://h/p')
        ->and(EndpointQuery::append('https://h/p', [['a', '1']]))->toBe('https://h/p?a=1')
        ->and(EndpointQuery::append('https://h/p?x=1', [['a', '1']]))->toBe('https://h/p?x=1&a=1');
});

it('applies the Endpoint headers as rendered pairs, bound ones from their date', function () {
    $renderer = new RenderEndpointRequest;
    $endpoint = rerEndpoint(
        [['name' => 'id', 'binding' => 'fixed', 'value' => 'c', 'kind' => 'path']],
        [['name' => 'X-Team', 'binding' => 'fixed', 'value' => 'finance'], ['name' => 'X-Day', 'binding' => 'period_end', 'value' => null]],
    );
    $values = $renderer->values($endpoint, ['header:X-Day' => '2026-03-31']);

    expect($values)->toBe(['id' => 'c', 'header:X-Team' => 'finance', 'header:X-Day' => '2026-03-31'])
        ->and($renderer->request('w', rerSource(), $endpoint, $values, [], 'op')->endpointHeaders)
        ->toBe([['name' => 'X-Team', 'value' => 'finance'], ['name' => 'X-Day', 'value' => '2026-03-31']]);
});

it('renders a POST body by replacing each whole-value $param with the typed value, keeping every number lexeme', function () {
    $renderer = new RenderEndpointRequest;
    $template = '{"from":{"$param":"from"},"filters":[{"$param":"team"},12345678901234567890.12,1.10],"nested":{"limit":{"$param":"limit"}},"flag":true,"none":null}';
    $endpoint = rerEndpoint([
        ['name' => 'from', 'binding' => 'date_range_from', 'value' => null, 'kind' => 'body'],
        ['name' => 'team', 'binding' => 'fixed', 'value' => 'a"b', 'kind' => 'body'],
        ['name' => 'limit', 'binding' => 'fixed', 'value' => '{"$param":"from"}', 'kind' => 'body'],
    ], [], 'POST', '/search', $template, true);
    $values = $renderer->values($endpoint, ['from' => '2026-01-01']);

    $request = $renderer->request('w', rerSource(), $endpoint, $values, [], '018f0000-0000-7000-8000-0000000000aa');

    // Keys are sorted (canonical form); a value can only fill its position, never change the shape of the JSON.
    expect($request->body)->toBe('{"filters":["a\"b",12345678901234567890.12,1.10],"flag":true,"from":"2026-01-01","nested":{"limit":"{\"$param\":\"from\"}"},"none":null}')
        ->and($request->method)->toBe('POST')
        ->and($request->idempotencyKey)->toBe('018f0000-0000-7000-8000-0000000000aa')
        ->and($request->readOnlyQuery)->toBeTrue()
        ->and($request->endpointHeaders)->toBe([['name' => 'Content-Type', 'value' => 'application/json']])
        ->and($request->queryPairs)->toBe([]);
});

it('sends no Idempotency-Key and no body for a GET', function () {
    $renderer = new RenderEndpointRequest;
    $endpoint = rerEndpoint([['name' => 'id', 'binding' => 'fixed', 'value' => 'c', 'kind' => 'path']]);

    $request = $renderer->request('w', rerSource(), $endpoint, $renderer->values($endpoint, []), [], 'op');

    expect($request->idempotencyKey)->toBeNull()->and($request->body)->toBeNull()->and($request->readOnlyQuery)->toBeFalse();
});

it('does not render when a value has gone missing between the check and the render', function () {
    $renderer = new RenderEndpointRequest;

    expect(fn () => $renderer->request('w', rerSource(), rerEndpoint(RER_PARAMS), ['id' => 'c'], [], 'op'))->toThrow(InvalidDataSource::class)
        ->and(fn () => $renderer->request('w', rerSource(), rerEndpoint(RER_PARAMS), ['id' => '..', 'from' => '2026-01-01', 'limit' => '1'], [], 'op'))->toThrow(InvalidDataSource::class);
});

it('does not send a parameter the body template uses in the query string too, even one stored as a query parameter', function () {
    $renderer = new RenderEndpointRequest;
    // Stored before references inside arrays were recognised: `team` is a body parameter that was kept as a query one.
    $endpoint = rerEndpoint([
        ['name' => 'team', 'binding' => 'fixed', 'value' => 'a', 'kind' => 'query'],
        ['name' => 'debug', 'binding' => 'fixed', 'value' => '1', 'kind' => 'query'],
    ], [], 'POST', '/search', '{"filters":[{"$param":"team"}]}', true);

    $request = $renderer->request('w', rerSource(), $endpoint, $renderer->values($endpoint, []), [], 'op');

    expect($request->queryPairs)->toBe([['debug', '1']])->and($request->body)->toBe('{"filters":["a"]}');
});

// Credentials: dropping the scheme, the refs, the key name and placement or the OAuth fields would send a test unauthenticated.
it('carries the Data Source credentials into the request: scheme, secret refs with their versions, key name and placement, OAuth fields', function (string $auth, ?string $name, ?string $placement, array $statuses, array $oauth, string $scheme, array $refs, ?int $version) {
    $source = new DataSource('d', 'Sales', 'https://api.example.com/v1', 'https', 'api.example.com', 443, $auth, [], null, null, null, false, 1, 't', 't', $name, $placement, $statuses, ...$oauth);
    $endpoint = rerEndpoint([['name' => 'id', 'binding' => 'fixed', 'value' => 'c', 'kind' => 'path']]);
    $renderer = new RenderEndpointRequest;

    $request = $renderer->request('w', $source, $endpoint, $renderer->values($endpoint, []), $statuses, 'op');

    expect($request->scheme->value)->toBe($scheme)
        ->and(array_map(fn ($ref) => [$ref->slot, $ref->secretVersion, $ref->operationId], $request->secretRefs))->toBe($refs)
        ->and($request->apiKeyName)->toBe($name)
        ->and($request->apiKeyPlacement)->toBe($placement)
        ->and($request->oauthTokenUrl)->toBe($oauth[0] ?? null)
        ->and($request->oauthClientId)->toBe($oauth[1] ?? null)
        ->and($request->oauthScope)->toBe($oauth[2] ?? null)
        ->and($request->secretVersion)->toBe($version);
})->with([
    'none' => ['none', null, null, [], [], 'none', [], null],
    'bearer' => ['bearer', null, null, ['bearer_token' => new SecretStatus('bearer_token', true, 't', 's1', 3, 2)], [], 'bearer', [['bearer_token', 2, null]], null],
    'API key in the query' => ['api_key', 'key', 'query', ['api_key' => new SecretStatus('api_key', true, 't', 's2', 3, 4)], [], 'api_key_query', [['api_key', 4, null]], null],
    'API key in a header' => ['api_key', 'X-Api-Key', 'header', ['api_key' => new SecretStatus('api_key', true, 't', 's2', 3, 1)], [], 'api_key_header', [['api_key', 1, null]], null],
    'a slot that holds no value is no ref' => ['bearer', null, null, ['bearer_token' => new SecretStatus('bearer_token', false)], [], 'bearer', [], null],
    'OAuth2' => [
        'oauth2_client_credentials', null, null, ['oauth_client_secret' => new SecretStatus('oauth_client_secret', true, 't', 's3', 3, 5)],
        ['https://auth.example.com/token', 'client-1', 'read write'], 'oauth2_client_credentials', [['oauth_client_secret', 5, null]], 5,
    ],
]);
