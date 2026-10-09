<?php

use App\Modules\Connector\Contracts\CredentialScheme;
use App\Modules\Connector\Contracts\EgressBlockLog;
use App\Modules\Connector\Contracts\EgressReason;
use App\Modules\Connector\Contracts\EgressRequest;
use App\Modules\Connector\Contracts\EgressResponse;
use App\Modules\Connector\Contracts\EgressTransport;
use App\Modules\Connector\Contracts\EgressTransportFailed;
use App\Modules\Connector\Contracts\FetchRequest;
use App\Modules\Connector\Contracts\NotJsonResponse;
use App\Modules\Connector\Contracts\PageFailed;
use App\Modules\Connector\Contracts\PageLimit;
use App\Modules\Connector\Contracts\PageLimitExceeded;
use App\Modules\Connector\Contracts\Pagination;
use App\Modules\Connector\Contracts\PaginationFailed;
use App\Modules\Connector\Contracts\PaginationPath;
use App\Modules\Connector\Contracts\ResponseLimitExceeded;
use App\Modules\Connector\Contracts\SealedSecret;
use App\Modules\Connector\Contracts\SecretContext;
use App\Modules\Connector\Contracts\SecretMissing;
use App\Modules\Connector\Contracts\SecretRef;
use App\Modules\Connector\Contracts\SecretVault;
use App\Modules\Connector\Contracts\SsrfBlocked;
use App\Modules\Connector\Contracts\TokenRequestLog;
use App\Modules\Connector\Contracts\UrlReference;
use App\Modules\Connector\Infrastructure\DirectFetchTransport;
use App\Modules\Connector\Infrastructure\OAuthTokenCache;
use App\Modules\Connector\Infrastructure\OAuthTokenClient;
use App\Modules\Connector\Infrastructure\SecretSettings;
use App\Platform\Json\InvalidLimitSetting;
use App\Platform\Json\LosslessJson;
use App\Platform\Tenancy\TenantCache;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Psr\Log\NullLogger;

// Story 2.11: the page loop of the `direct` transport, over a scripted egress transport. The Database suite covers the same
// through the real guard; here every style, limit and refusal is pinned without a network.

const PG_WS = '018f0000-0000-7000-8000-0000000000a1';
const PG_SRC = '018f0000-0000-7000-8000-0000000000a2';

function pgVault(array $values): SecretVault
{
    return new class($values) implements SecretVault
    {
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
            return $this->values[$ref->slot] ?? throw new SecretMissing;
        }

        public function status(string $workspaceId, string $dataSourceId): array
        {
            return [];
        }
    };
}

function pgTransport(EgressTransport $egress, array $secrets, array $settings, ?EgressBlockLog $blocks): DirectFetchTransport
{
    $config = new Repository(['dashflow' => ['secrets' => [], 'oauth' => []] + $settings]);
    $secretSettings = new SecretSettings($config);
    $log = new class implements TokenRequestLog
    {
        public function record(string $workspaceId, ?string $dataSourceId, string $tokenUrl, ?int $httpStatus, int $latencyMs, int $bytes, ?string $code, DateTimeInterface $startedAt): void {}
    };

    return new DirectFetchTransport(
        $egress, pgVault($secrets), new OAuthTokenClient($egress, $log),
        new OAuthTokenCache(new TenantCache(new CacheRepository(new ArrayStore)), $secretSettings, new NullLogger),
        $secretSettings, $blocks ?? pgBlocks(), $config,
    );
}

/** A scripted egress: the answers in order, each a body, a response or an exception; every request is kept. */
function pgEgress(array $answers): EgressTransport
{
    return new class($answers) implements EgressTransport
    {
        /** @var list<EgressRequest> */
        public array $sent = [];

        public function __construct(private array $answers) {}

        public function send(string $workspaceId, EgressRequest $request): EgressResponse
        {
            $this->sent[] = $request;
            $next = array_shift($this->answers) ?? throw new LogicException('no more answers');

            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next instanceof EgressResponse ? $next : new EgressResponse(200, ['content-type' => ['application/json']], $next, $request->url);
        }
    };
}

function pgBlocks(): EgressBlockLog
{
    return new class implements EgressBlockLog
    {
        /** @var list<array{0: EgressReason, 1: ?string, 2: ?int}> */
        public array $recorded = [];

        public function record(string $workspaceId, EgressReason $reason, ?string $host, ?int $port): void
        {
            $this->recorded[] = [$reason, $host, $port];
        }
    };
}

function pgLink(string $link, string $body = '{"data":[1]}', int $status = 200): EgressResponse
{
    return new EgressResponse($status, ['content-type' => ['application/json'], 'link' => [$link]], $body, 'https://api.example.com/v1');
}

function pgRequest(Pagination $pagination, ?int $maxPages = null, ?int $maxBytes = null, CredentialScheme $scheme = CredentialScheme::None, array $refs = [], ?string $apiKey = null, string $method = 'GET'): FetchRequest
{
    return new FetchRequest(
        PG_WS, PG_SRC, null, 'https://api.example.com/v1', [], $scheme, $refs, [], $apiKey, $apiKey === null ? null : 'query', maxResponseBytes: $maxBytes,
        method: $method, readOnlyQuery: $method === 'POST', pagination: $pagination, maxPages: $maxPages,
    );
}

function pgFetch(EgressTransport $egress, FetchRequest $request, array $settings = [], ?EgressBlockLog $blocks = null, array $secrets = []): mixed
{
    return pgTransport($egress, $secrets, $settings, $blocks)->fetch($request);
}

/** @return list<string> the URLs requested */
function pgUrls(EgressTransport $egress): array
{
    return array_map(fn (EgressRequest $r): string => $r->url, $egress->sent);
}

it('sends exactly one request and returns the body untouched for the style none', function () {
    $egress = pgEgress(['{"data":[1.10],"next":"x"}']);

    $response = pgFetch($egress, pgRequest(new Pagination));

    expect(pgUrls($egress))->toBe(['https://api.example.com/v1'])
        ->and($response->body)->toBe('{"data":[1.10],"next":"x"}')
        ->and($response->pages)->toBe(1)->and($response->page)->toBeNull();
});

it('follows page numbers from 1 until an empty page and merges the records, keeping the first page and every number lexeme', function () {
    $egress = pgEgress([
        '{"meta":{"total":12345678901234567890.12},"data":[{"p":1.10}],"z":null}',
        '{"meta":{"total":9},"data":[{"p":2.50},{"p":3}]}',
        '{"data":[{"p":4e2}]}',
        '{"data":[]}',
    ]);

    $response = pgFetch($egress, pgRequest(new Pagination('page', 'page', recordsPath: 'data')));

    expect(pgUrls($egress))->toBe([
        'https://api.example.com/v1?page=1', 'https://api.example.com/v1?page=2', 'https://api.example.com/v1?page=3', 'https://api.example.com/v1?page=4',
    ])->and($response->body)->toBe('{"meta":{"total":12345678901234567890.12},"data":[{"p":1.10},{"p":2.50},{"p":3},{"p":4e2}],"z":null}')
        ->and($response->pages)->toBe(4)->and($response->page)->toBe(4)
        ->and($response->bytes)->toBe(strlen('{"meta":{"total":12345678901234567890.12},"data":[{"p":1.10}],"z":null}') + strlen('{"meta":{"total":9},"data":[{"p":2.50},{"p":3}]}') + strlen('{"data":[{"p":4e2}]}') + strlen('{"data":[]}'));
});

it('sends the page size with every page request when it is set, and keeps the endpoint query', function () {
    $egress = pgEgress(['[1]', '[]']);
    $request = new FetchRequest(PG_WS, PG_SRC, null, 'https://api.example.com/v1', [], CredentialScheme::None, [], queryPairs: [['q', 'a b']], pagination: new Pagination('page', 'p', 'limit', 50));

    pgFetch($egress, $request);

    expect(pgUrls($egress))->toBe(['https://api.example.com/v1?q=a%20b&p=1&limit=50', 'https://api.example.com/v1?q=a%20b&p=2&limit=50']);
});

it('advances the offset by the records received and ends on an empty page', function () {
    $egress = pgEgress(['{"items":[1,2,3]}', '{"items":[4,5]}', '{"items":[]}']);

    $response = pgFetch($egress, pgRequest(new Pagination('offset', 'offset', recordsPath: 'items')));

    expect(pgUrls($egress))->toBe(['https://api.example.com/v1?offset=0', 'https://api.example.com/v1?offset=3', 'https://api.example.com/v1?offset=5'])
        ->and($response->body)->toBe('{"items":[1,2,3,4,5]}');
});

it('reads the records from the response root when the records path is empty, and from a nested path with indexes', function () {
    $egress = pgEgress(['[1]', '[2]', '[]']);

    expect(pgFetch($egress, pgRequest(new Pagination('page', 'p')))->body)->toBe('[1,2]');

    $egress = pgEgress(['{"a":[{"rows":[1]}]}', '{"a":[{"rows":[2]}]}', '{"a":[{"rows":[]}]}']);

    expect(pgFetch($egress, pgRequest(new Pagination('page', 'p', recordsPath: 'a.0.rows')))->body)->toBe('{"a":[{"rows":[1,2]}]}');
});

it('sends the cursor as a plain token in the configured parameter and ends when it is missing, null or empty', function (string $last) {
    $egress = pgEgress(['{"d":[1],"meta":{"next":"c1"}}', '{"d":[2],"meta":{"next":"c%2/&="}}', '{"d":[3]'.$last.'}']);

    $response = pgFetch($egress, pgRequest(new Pagination('cursor', 'cursor', recordsPath: 'd', cursorPath: 'meta.next')));

    expect(pgUrls($egress))->toBe(['https://api.example.com/v1', 'https://api.example.com/v1?cursor=c1', 'https://api.example.com/v1?cursor=c%252%2F%26%3D'])
        ->and($response->body)->toBe('{"d":[1,2,3],"meta":{"next":"c1"}}');
})->with(['missing' => [''], 'null' => [',"meta":{"next":null}'], 'empty' => [',"meta":{"next":""}']]);

it('never requests a cursor as a URL', function () {
    $egress = pgEgress(['{"d":[1],"next":"https://evil.example/x"}', '{"d":[2]}']);

    pgFetch($egress, pgRequest(new Pagination('cursor', 'cursor', recordsPath: 'd', cursorPath: 'next')));

    expect(pgUrls($egress))->toBe(['https://api.example.com/v1', 'https://api.example.com/v1?cursor=https%3A%2F%2Fevil.example%2Fx']);
});

it('fails a repeated cursor as a loop and a cursor that is not a plain token', function (string $second, string $reason) {
    $egress = pgEgress(['{"d":[1],"next":"c1"}', $second]);

    try {
        pgFetch($egress, pgRequest(new Pagination('cursor', 'cursor', recordsPath: 'd', cursorPath: 'next')));
        $thrown = null;
    } catch (PageFailed $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(PageFailed::class)
        ->and($thrown->page)->toBe(2)
        ->and($thrown->cause)->toBeInstanceOf(PaginationFailed::class)->and($thrown->cause->reason)->toBe($reason);
})->with([
    'a repeat' => ['{"d":[2],"next":"c1"}', 'cursor_loop'],
    'an object' => ['{"d":[2],"next":{"a":1}}', 'cursor_invalid'],
    'a boolean' => ['{"d":[2],"next":true}', 'cursor_invalid'],
]);

it('follows the rel next link of the Link header, resolved against the current URL, and stops without one', function () {
    $egress = pgEgress([
        pgLink('<https://api.example.com/v1?page=2>; rel="next", <https://api.example.com/v1?page=9>; rel="last"'),
        pgLink('</v1?page=3>; title="x, y"; rel="prev next"', '{"data":[2]}'),
        pgLink('<?page=4>; rel=next', '{"data":[3]}'),
        pgLink('<https://api.example.com/v1?page=1>; rel="first"', '{"data":[4]}'),
    ]);

    $response = pgFetch($egress, pgRequest(new Pagination('link_header', recordsPath: 'data')));

    expect(pgUrls($egress))->toBe(['https://api.example.com/v1', 'https://api.example.com/v1?page=2', 'https://api.example.com/v1?page=3', 'https://api.example.com/v1?page=4'])
        ->and($response->body)->toBe('{"data":[1,2,3,4]}');
});

it('refuses a next link on another origin, another port or an http downgrade before sending anything, and records it', function (string $link, ?string $host, ?int $port) {
    $egress = pgEgress([pgLink($link), pgLink('<https://api.example.com/v1?never>; rel="next"')]);
    $blocks = pgBlocks();

    try {
        pgFetch($egress, pgRequest(new Pagination('link_header', recordsPath: 'data'), scheme: CredentialScheme::Bearer, refs: [new SecretRef('s', 'bearer_token')]), blocks: $blocks, secrets: ['bearer_token' => 'CANARY-TOKEN']);
        $thrown = null;
    } catch (PageFailed $e) {
        $thrown = $e;
    }

    expect($thrown?->page)->toBe(1)
        ->and($thrown?->cause)->toBeInstanceOf(SsrfBlocked::class)
        ->and($thrown?->cause->reason)->toBe(EgressReason::PaginationRefused)
        ->and($egress->sent)->toHaveCount(1)
        ->and($blocks->recorded)->toBe([[EgressReason::PaginationRefused, $host, $port]]);
})->with([
    'another host' => ['<https://evil.example/v1?page=2>; rel="next"', 'evil.example', 443],
    'another port' => ['<https://api.example.com:8443/v1?page=2>; rel="next"', 'api.example.com', 8443],
    'a downgrade to http' => ['<http://api.example.com/v1?page=2>; rel="next"', 'api.example.com', 80],
    'a scheme-relative other host' => ['<//evil.example/x>; rel="next"', 'evil.example', 443],
    'userinfo in the host' => ['<https://api.example.com@evil.example/x>; rel="next"', null, null],
    'another scheme' => ['<ftp://api.example.com/x>; rel="next"', null, null],
]);

it('fails a link that does not parse as fetch-failed material, and a link that repeats as a loop', function () {
    $egress = pgEgress([pgLink('<https://exa mple.com/x>; rel="next"')]);

    try {
        pgFetch($egress, pgRequest(new Pagination('link_header', recordsPath: 'data')));
    } catch (PageFailed $e) {
        expect($e->cause)->toBeInstanceOf(PaginationFailed::class)->and($e->cause->reason)->toBe('next_url_invalid');
    }

    $egress = pgEgress([pgLink('<https://api.example.com/v1?p=2>; rel="next"'), pgLink('<https://api.example.com/v1?p=2>; rel="next"')]);

    expect(fn () => pgFetch($egress, pgRequest(new Pagination('link_header', recordsPath: 'data'))))->toThrow(PageFailed::class);
});

it('fails with too many pages when pages beyond the smaller of the two caps still hold records, and sends no page past the terminator', function (?int $source, ?int $platform, int $cap) {
    $egress = pgEgress(['[1]', '[2]', '[3]', '[4]', '[5]']);
    $settings = ['tunables' => ['guards' => ['max_pages' => ['value' => $platform]]]];

    try {
        pgFetch($egress, pgRequest(new Pagination('page', 'p'), maxPages: $source), $settings);
        $thrown = null;
    } catch (PageFailed $e) {
        $thrown = $e;
    }

    // Page cap + 1 is requested as the terminator; it holds records, so the run overflows.
    expect($thrown?->cause)->toBeInstanceOf(PageLimitExceeded::class)
        ->and($thrown?->cause->cap)->toBe($cap)
        ->and($thrown?->page)->toBe($cap + 1)->and($thrown?->pages)->toBe($cap)
        ->and($egress->sent)->toHaveCount($cap + 1);
})->with([
    'the Data Source cap' => [2, null, 2],
    'the platform cap' => [null, 3, 3],
    'the smaller one' => [4, 2, 2],
]);

it('succeeds when the cap equals the real page count for page and offset, and fails one page more', function (string $style) {
    $paging = new Pagination($style, 'p', recordsPath: 'd');
    $egress = pgEgress(['{"d":[1]}', '{"d":[2]}', '{"d":[]}']);

    expect(pgFetch($egress, pgRequest($paging, maxPages: 2))->body)->toBe('{"d":[1,2]}')
        ->and($egress->sent)->toHaveCount(3);

    $egress = pgEgress(['{"d":[1]}', '{"d":[2]}', '{"d":[3]}', '{"d":[]}']);

    expect(fn () => pgFetch($egress, pgRequest($paging, maxPages: 2)))->toThrow(PageFailed::class)
        ->and($egress->sent)->toHaveCount(3);
})->with(['page', 'offset']);

it('keeps the cap strict for cursor and link_header: a cursor or link after cap pages is an overflow', function () {
    $egress = pgEgress(['{"d":[1],"n":"c1"}', '{"d":[2],"n":"c2"}', '{"d":[3]}']);

    try {
        pgFetch($egress, pgRequest(new Pagination('cursor', 'c', recordsPath: 'd', cursorPath: 'n'), maxPages: 2));
        $thrown = null;
    } catch (PageFailed $e) {
        $thrown = $e;
    }

    expect($thrown?->cause)->toBeInstanceOf(PageLimitExceeded::class)->and($thrown?->page)->toBe(3)->and($egress->sent)->toHaveCount(2);

    $egress = pgEgress([pgLink('<https://api.example.com/v1?p=2>; rel="next"'), pgLink('<https://api.example.com/v1?p=3>; rel="next"', '{"data":[2]}'), pgLink('', '{"data":[3]}')]);

    expect(fn () => pgFetch($egress, pgRequest(new Pagination('link_header', recordsPath: 'data'), maxPages: 2)))->toThrow(PageFailed::class)
        ->and($egress->sent)->toHaveCount(2);

    // Exactly cap pages and no link: fine.
    $egress = pgEgress([pgLink('<https://api.example.com/v1?p=2>; rel="next"'), pgLink('', '{"data":[2]}')]);

    expect(pgFetch($egress, pgRequest(new Pagination('link_header', recordsPath: 'data'), maxPages: 2))->pages)->toBe(2);
});

it('fails the next request before it is sent when the byte budget is exactly spent: the terminator page counts too', function () {
    $egress = pgEgress(['[1111]', '[]']);

    try {
        pgFetch($egress, pgRequest(new Pagination('page', 'p'), maxBytes: 6));
        $thrown = null;
    } catch (PageFailed $e) {
        $thrown = $e;
    }

    expect($thrown?->cause)->toBeInstanceOf(ResponseLimitExceeded::class)
        ->and($thrown?->cause->bytesRead)->toBe(6)
        ->and($thrown?->page)->toBe(2)
        ->and($egress->sent)->toHaveCount(1);
});

it('fails an API that ignores the page parameter and repeats the same records', function () {
    $egress = pgEgress(['{"d":[1.10,{"a":2}]}', '{"d":[1.10,{"a":2}]}', '{"d":[1.10,{"a":2}]}']);

    try {
        pgFetch($egress, pgRequest(new Pagination('page', 'p', recordsPath: 'd')));
        $thrown = null;
    } catch (PageFailed $e) {
        $thrown = $e;
    }

    expect($thrown?->cause)->toBeInstanceOf(PaginationFailed::class)->and($thrown?->cause->reason)->toBe('page_repeat')
        ->and($thrown?->page)->toBe(2)->and($thrown?->pages)->toBe(1)->and($egress->sent)->toHaveCount(2);

    // 1.10 and 1.1 are different lexemes: not a repeat.
    $egress = pgEgress(['{"d":[1.10]}', '{"d":[1.1]}', '{"d":[]}']);

    expect(pgFetch($egress, pgRequest(new Pagination('page', 'p', recordsPath: 'd')))->pages)->toBe(3);
});

it('does not send an API key twice when the next link echoes it, and drops a key or query pair named like a pagination parameter', function () {
    $egress = pgEgress([pgLink('<https://api.example.com/v1?key=OLD&page=2>; rel="next"'), '{"data":[2]}']);

    pgFetch($egress, pgRequest(new Pagination('link_header', recordsPath: 'data'), scheme: CredentialScheme::ApiKeyQuery, refs: [new SecretRef('s', 'api_key')], apiKey: 'key'), secrets: ['api_key' => 'k1']);

    expect(pgUrls($egress))->toBe(['https://api.example.com/v1?key=k1', 'https://api.example.com/v1?page=2&key=k1']);

    // The API key named like the page parameter: the run's own parameter wins, no pair is sent twice.
    $egress = pgEgress(['[1]', '[]']);
    pgFetch($egress, pgRequest(new Pagination('page', 'key'), scheme: CredentialScheme::ApiKeyQuery, refs: [new SecretRef('s', 'api_key')], apiKey: 'key'), secrets: ['api_key' => 'k1']);

    expect(pgUrls($egress))->toBe(['https://api.example.com/v1?key=1', 'https://api.example.com/v1?key=2']);

    $egress = pgEgress(['[1]', '[]']);
    $request = new FetchRequest(PG_WS, PG_SRC, null, 'https://api.example.com/v1', [], CredentialScheme::None, [], queryPairs: [['p', 'old'], ['q', 'x']], pagination: new Pagination('page', 'p'));
    pgFetch($egress, $request);

    expect(pgUrls($egress))->toBe(['https://api.example.com/v1?q=x&p=1', 'https://api.example.com/v1?q=x&p=2']);
});

it('sends a per-page Idempotency-Key on a paged POST: page 1 keeps the Operation id', function () {
    $egress = pgEgress(['[1]', '[2]', '[]']);
    $request = new FetchRequest(PG_WS, PG_SRC, null, 'https://api.example.com/v1', [], CredentialScheme::None, [], method: 'POST', body: '{}', idempotencyKey: 'op-1', readOnlyQuery: true, pagination: new Pagination('page', 'p'));

    pgFetch($egress, $request);

    expect(array_map(fn (EgressRequest $r): string => $r->headers['Idempotency-Key'], $egress->sent))
        ->toBe(['op-1', hash('sha256', 'op-1:2'), hash('sha256', 'op-1:3')]);
});

it('resolves a relative next link against the URL that answered, after an egress redirect', function () {
    $final = 'https://api.example.com/final/dir/x';
    $egress = pgEgress([
        new EgressResponse(200, ['content-type' => ['application/json'], 'link' => ['<y?p=2>; rel="next"']], '{"data":[1]}', $final),
        '{"data":[2]}',
    ]);

    pgFetch($egress, pgRequest(new Pagination('link_header', recordsPath: 'data')));

    expect(pgUrls($egress)[1])->toBe('https://api.example.com/final/dir/y?p=2');
});

it('never sends a page size for link_header', function () {
    $egress = pgEgress([pgLink('<https://api.example.com/v1?p=2>; rel="next"'), '{"data":[2]}']);

    pgFetch($egress, pgRequest(new Pagination('link_header', sizeParam: 'limit', size: 5, recordsPath: 'data')));

    expect(pgUrls($egress)[0])->toBe('https://api.example.com/v1');
});

it('applies no page cap when neither is set, and refuses a malformed setting', function () {
    $egress = pgEgress(array_merge(array_map(fn (int $n): string => "[{$n}]", range(1, 30)), ['[]']));

    expect(pgFetch($egress, pgRequest(new Pagination('page', 'p')))->pages)->toBe(31);
    expect(PageLimit::effective(null, null))->toBeNull()
        ->and(fn () => PageLimit::effective(null, 'many'))->toThrow(InvalidLimitSetting::class)
        ->and(fn () => PageLimit::effective(0, null))->toThrow(InvalidLimitSetting::class);
});

it('gives each page what is left of the byte budget and fails with the cumulative size when it is passed', function () {
    $egress = pgEgress(['[1111]', '[2222]', new ResponseLimitExceeded(40, 14, 200)]);

    try {
        pgFetch($egress, pgRequest(new Pagination('page', 'p'), maxBytes: 20));
        $thrown = null;
    } catch (PageFailed $e) {
        $thrown = $e;
    }

    expect(array_map(fn (EgressRequest $r): ?int => $r->maxBytes, $egress->sent))->toBe([20, 14, 8])
        ->and($thrown?->page)->toBe(3)
        ->and($thrown?->cause)->toBeInstanceOf(ResponseLimitExceeded::class)
        ->and($thrown?->cause->bytesRead)->toBe(12 + 40)
        ->and($thrown?->cause->limit)->toBe(20);
});

it('uses the platform byte ceiling when the Data Source has none, and no budget when neither is set', function () {
    $egress = pgEgress(['[1]', '[]']);
    pgFetch($egress, pgRequest(new Pagination('page', 'p')), ['tunables' => ['guards' => ['max_bytes' => ['value' => 100]]]]);

    expect(array_map(fn (EgressRequest $r): ?int => $r->maxBytes, $egress->sent))->toBe([100, 97]);

    $egress = pgEgress(['[1]', '[]']);
    pgFetch($egress, pgRequest(new Pagination('page', 'p')));

    expect(array_map(fn (EgressRequest $r): ?int => $r->maxBytes, $egress->sent))->toBe([null, null]);
});

it('fails the whole fetch at the page that failed: a transport error, a non-2xx answer, HTML, a missing or wrong records path', function (array $answers, int $page, string $kind) {
    $egress = pgEgress($answers);

    try {
        $response = pgFetch($egress, pgRequest(new Pagination('page', 'p', recordsPath: 'data')));
        $thrown = null;
    } catch (PageFailed $e) {
        $thrown = $e;
        $response = null;
    }

    if ($kind === 'status') {
        expect($response?->status)->toBe(503)->and($response?->page)->toBe($page)->and($response?->pages)->toBe($page - 1);
    } else {
        expect($thrown?->page)->toBe($page)->and($thrown?->cause)->toBeInstanceOf($kind);
    }

    expect($egress->sent)->toHaveCount($page);
})->with([
    'a transport error' => [['{"data":[1]}', '{"data":[2]}', new EgressTransportFailed('x', 28)], 3, EgressTransportFailed::class],
    'a 503' => [['{"data":[1]}', new EgressResponse(503, ['content-type' => ['application/json']], '{}', 'u')], 2, 'status'],
    'HTML' => [['{"data":[1]}', new EgressResponse(200, ['content-type' => ['text/html']], '<html>', 'u')], 2, NotJsonResponse::class],
    'a body that does not parse' => [['{"data":[1]}', '{"data":'], 2, NotJsonResponse::class],
    'a missing records path' => [['{"data":[1]}', '{"other":[1]}'], 2, NotJsonResponse::class],
    'records that are not an array' => [['{"data":[1]}', '{"data":{"a":1}}'], 2, NotJsonResponse::class],
    'records on the first page missing' => [['{"x":[1]}'], 1, NotJsonResponse::class],
]);

it('sends the credentials with every page and an API key in the query after the page parameters', function () {
    $egress = pgEgress(['[1]', '[]']);

    pgFetch($egress, pgRequest(new Pagination('page', 'p'), scheme: CredentialScheme::ApiKeyQuery, refs: [new SecretRef('s', 'api_key')], apiKey: 'key'), secrets: ['api_key' => 'k&1']);

    expect(pgUrls($egress))->toBe(['https://api.example.com/v1?p=1&key=k%261', 'https://api.example.com/v1?p=2&key=k%261']);

    $egress = pgEgress(['[1]', '[]']);
    pgFetch($egress, pgRequest(new Pagination('page', 'p'), scheme: CredentialScheme::Bearer, refs: [new SecretRef('s', 'bearer_token')]), secrets: ['bearer_token' => 't0k']);

    expect(array_map(fn (EgressRequest $r): array => $r->credentials, $egress->sent))->toBe([['Authorization' => 'Bearer t0k'], ['Authorization' => 'Bearer t0k']]);
});

it('repeats a read-only POST body on every page', function () {
    $egress = pgEgress(['[1]', '[]']);
    $request = new FetchRequest(PG_WS, PG_SRC, null, 'https://api.example.com/v1', [], CredentialScheme::None, [], method: 'POST', body: '{"q":1}', readOnlyQuery: true, pagination: new Pagination('page', 'p'));

    pgFetch($egress, $request);

    expect(array_map(fn (EgressRequest $r): array => [$r->method, $r->body], $egress->sent))->toBe([['POST', '{"q":1}'], ['POST', '{"q":1}']]);
});

it('resolves url references the way a redirect does', function (string $base, string $location, ?string $expected) {
    expect(UrlReference::resolve($base, $location))->toBe($expected);
})->with([
    ['https://a.example/x/y?q=1', 'https://b.example/z#f', 'https://b.example/z'],
    ['https://a.example/x/y?q=1', '//b.example/z', 'https://b.example/z'],
    ['https://a.example/x/y?q=1', '/z?p=2', 'https://a.example/z?p=2'],
    ['https://a.example/x/y?q=1', '?p=2', 'https://a.example/x/y?p=2'],
    ['https://a.example/x/y?q=1', 'z', 'https://a.example/x/z'],
    ['https://a.example/x/y', '', null],
    ['not a url', 'z', null],
]);

it('reads and replaces a dotted path in a decoded document', function () {
    $doc = LosslessJson::decode('{"a":{"b":[{"c":[1,2]},{"c":[3]}],"keep":1.10},"z":true}');

    expect(PaginationPath::find($doc, 'a.b.1.c')[1])->toHaveCount(1)
        ->and(PaginationPath::find($doc, 'a.x')[0])->toBeFalse()
        ->and(PaginationPath::find($doc, 'a.b.5')[0])->toBeFalse()
        ->and(PaginationPath::find($doc, 'z.q')[0])->toBeFalse()
        ->and(PaginationPath::find($doc, '')[1])->toBe($doc)
        ->and(LosslessJson::encode(PaginationPath::replace($doc, 'a.b.0.c', [])))->toBe('{"a":{"b":[{"c":[]},{"c":[3]}],"keep":1.10},"z":true}');
});

it('accepts only dotted paths of at most eight safe segments', function (string $path, bool $valid) {
    expect(PaginationPath::valid($path))->toBe($valid);
})->with([
    ['data', true], ['data.items', true], ['pages.0.rows', true], ['a-b_c.D9', true], ['a.b.c.d.e.f.g.h', true],
    ['a.b.c.d.e.f.g.h.i', false], ['', false], ['a..b', false], ['.a', false], ['a.', false], ['a b', false], ['a[0]', false], ['$.a', false], ['a/b', false],
    [str_repeat('a', 256), false],
]);
