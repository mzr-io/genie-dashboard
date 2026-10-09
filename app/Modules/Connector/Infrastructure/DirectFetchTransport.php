<?php

namespace App\Modules\Connector\Infrastructure;

use App\Modules\Connector\Contracts\AuthFailed;
use App\Modules\Connector\Contracts\CredentialScheme;
use App\Modules\Connector\Contracts\EgressBlockLog;
use App\Modules\Connector\Contracts\EgressOrigin;
use App\Modules\Connector\Contracts\EgressReason;
use App\Modules\Connector\Contracts\EgressRequest;
use App\Modules\Connector\Contracts\EgressResponse;
use App\Modules\Connector\Contracts\EgressTransport;
use App\Modules\Connector\Contracts\EndpointQuery;
use App\Modules\Connector\Contracts\FetchRequest;
use App\Modules\Connector\Contracts\FetchResponse;
use App\Modules\Connector\Contracts\FetchTransport;
use App\Modules\Connector\Contracts\NotJsonResponse;
use App\Modules\Connector\Contracts\PageFailed;
use App\Modules\Connector\Contracts\PageLimit;
use App\Modules\Connector\Contracts\PageLimitExceeded;
use App\Modules\Connector\Contracts\PaginationFailed;
use App\Modules\Connector\Contracts\PaginationPath;
use App\Modules\Connector\Contracts\ResponseLimit;
use App\Modules\Connector\Contracts\ResponseLimitExceeded;
use App\Modules\Connector\Contracts\SecretContext;
use App\Modules\Connector\Contracts\SecretMissing;
use App\Modules\Connector\Contracts\SecretSlots;
use App\Modules\Connector\Contracts\SecretVault;
use App\Modules\Connector\Contracts\SsrfBlocked;
use App\Modules\Connector\Contracts\UrlReference;
use App\Platform\Json\DecimalLiteral;
use App\Platform\Json\LosslessJson;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Throwable;

/**
 * The `direct` {@see FetchTransport} (Story 2.5): the worker calls the source itself. Each secret ref is resolved at egress
 * through {@see SecretVault::resolve} (the plaintext lives in local variables for the length of one call), the credential
 * scheme is applied (a bearer or basic `Authorization` header, an OAuth2 token as a Bearer (cached encrypted per secret version, refreshed once on a 401), an API key in a header or in the query string, a secret
 * default header) and the request goes out through the {@see EgressTransport}: the guard decides every hop and the connection
 * is pinned to the address it checked. Credentials travel as credentials, so the transport drops them if the origin changes.
 *
 * An Endpoint test (Story 2.10) adds the rendered URL, the query (one builder, everything percent-encoded), the Endpoint's
 * headers (after the defaults) and a body. Only a GET and a POST flagged read-only are ever sent. A POST carries an
 * `Idempotency-Key` (the Operation id) and is sent once: it is never repeated automatically, so a 401 on a POST, OAuth2 included,
 * is reported as {@see AuthFailed} without a second send (a GET is refreshed and repeated once, Story 2.7).
 *
 * Pagination (Story 2.11): when the Data Source pages its answers, this is the only place that follows the pages. Each page is
 * a request of its own through the {@see EgressTransport} (so through the guard, with the same credential handling as the
 * first), read as JSON with the records array taken at the records path; the run ends when the API signals the end (an empty
 * page for `page` and `offset`, no cursor for `cursor`, no `rel="next"` link for `link_header`) and the records of every page
 * are merged into the first page's document, written back with every number as received. A `cursor` is a token appended to
 * the configured parameter, never a URL. A `link_header` target must have exactly the scheme, host and port of the request
 * that returned it: anything else is refused before it is sent, recorded as a block and counted toward the SSRF alert, so a
 * credential never leaves its origin. The page cap and the byte budget (all pages together) end a run that would pass them;
 * nothing is truncated. A page that fails fails the whole fetch as a {@see PageFailed} naming the page; no page is retried.
 */
final class DirectFetchTransport implements FetchTransport
{
    public function __construct(
        private readonly EgressTransport $egress,
        private readonly SecretVault $vault,
        private readonly OAuthTokenClient $tokens,
        private readonly OAuthTokenCache $tokenCache,
        private readonly SecretSettings $settings,
        private readonly EgressBlockLog $blocks,
        private readonly Repository $config,
    ) {}

    public function fetch(FetchRequest $request): FetchResponse
    {
        if (! in_array($request->method, ['GET', 'POST'], true) || ($request->method === 'POST' && ! $request->readOnlyQuery)) {
            throw new InvalidArgumentException('Only a GET and a read-only POST are sent.');
        }

        $headers = [];
        $credentials = [];
        $key = null;
        $base = EndpointQuery::append($request->url ?? $request->urlTemplate, $request->queryPairs);

        foreach ($request->headers as $header) {
            if (($header['secret'] ?? false) === true) {
                $credentials[$header['name']] = $this->secret($request, SecretSlots::header($header['name']));
            } else {
                $headers[$header['name']] = $header['value'];
            }
        }

        // The Endpoint's own headers come after the defaults and win over a default of the same name.
        foreach ($request->endpointHeaders as $header) {
            $headers = self::without($headers, $header['name']);
            $credentials = self::without($credentials, $header['name']);
            $headers[$header['name']] = $header['value'];
        }

        if ($request->method === 'POST' && $request->idempotencyKey !== null) {
            $headers = self::without($headers, 'Idempotency-Key');
            $headers['Idempotency-Key'] = $request->idempotencyKey;
        }

        switch ($request->scheme) {
            case CredentialScheme::None:
                break;
            case CredentialScheme::ApiKeyHeader:
                $credentials[$this->apiKeyName($request)] = $this->secret($request, SecretSlots::API_KEY);

                break;
            case CredentialScheme::ApiKeyQuery:
                $name = $this->apiKeyName($request);
                $key = [$name, $this->secret($request, SecretSlots::API_KEY)];

                break;
            case CredentialScheme::Bearer:
                $credentials['Authorization'] = 'Bearer '.$this->secret($request, SecretSlots::BEARER);

                break;
            case CredentialScheme::Basic:
                $credentials['Authorization'] = 'Basic '.base64_encode($this->secret($request, SecretSlots::BASIC_USERNAME).':'.$this->secret($request, SecretSlots::BASIC_PASSWORD));

                break;
            case CredentialScheme::OAuth2ClientCredentials:
                // The token is added below: it may come from the cache, and a 401 asks for another.
                break;
        }

        // A source is asked for JSON unless the Data Source sets its own Accept.
        if (! array_key_exists('accept', array_change_key_case($headers + $credentials))) {
            $headers['Accept'] = 'application/json';
        }

        $started = hrtime(true);

        if ($request->pagination->enabled()) {
            return $this->paged($request, $base, $key, $headers, $credentials, $started);
        }

        $url = self::withKey($base, $key);
        $token = null;

        if ($request->scheme === CredentialScheme::OAuth2ClientCredentials) {
            $response = $this->sendWithToken($request, $url, $headers, $credentials, $request->maxResponseBytes, $token);
        } else {
            $response = $this->send($request, $url, $headers, $credentials, $request->maxResponseBytes);
        }

        $latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);

        return new FetchResponse($response->status, $response->headers, $response->body, strlen($response->body), $latencyMs);
    }

    /**
     * The pages of a paged run, merged (Story 2.11).
     *
     * @param  array{0: string, 1: string}|null  $key  an API key sent in the query string
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $credentials
     *
     * @throws PageFailed
     */
    private function paged(FetchRequest $request, string $base, ?array $key, array $headers, array $credentials, int $started): FetchResponse
    {
        $paging = $request->pagination;
        // A malformed limit setting raises here, before anything is sent: a typo never quietly removes a protection.
        $cap = PageLimit::effective($request->maxPages, $this->config->get('dashflow.tunables.guards.max_pages.value'));
        $limit = ResponseLimit::effective($request->maxResponseBytes, $this->config->get('dashflow.tunables.guards.max_bytes.value'));

        $token = null;
        $consumed = 0;
        $page = 0;
        $fetched = 0;
        $offset = 0;
        $cursor = null;
        $nextUrl = null;
        /** @var array<string, true> $seen the cursors and links already followed: seeing one again is a loop */
        $seen = [];
        $first = null;
        $firstHeaders = [];
        $records = [];
        $response = null;
        $previous = null;
        // `page` and `offset` end on an empty page, so a run that has exactly `cap` pages of records needs page cap + 1 to learn it is over.
        $terminated = in_array($paging->style, ['page', 'offset'], true);
        // A parameter the run sets itself replaces any query pair of the same name (the Endpoint's, or an API key's).
        $names = array_values(array_filter([$paging->param, $paging->sizeParam]));
        foreach ($names as $name) {
            $base = self::withoutParam($base, $name);
        }
        $key = $key !== null && in_array($key[0], $names, true) ? null : $key;

        while (true) {
            $page++;
            $done = false;

            try {
                if ($cap !== null && $page > $cap + ($terminated ? 1 : 0)) {
                    throw new PageLimitExceeded($cap);
                }

                if ($paging->style === 'link_header' && $page > 1) {
                    $url = self::withKey((string) $nextUrl, $key);
                } else {
                    $pairs = [];

                    if ($paging->style === 'page') {
                        $pairs[] = [(string) $paging->param, (string) $page];
                    } elseif ($paging->style === 'offset') {
                        $pairs[] = [(string) $paging->param, (string) $offset];
                    } elseif ($paging->style === 'cursor' && $cursor !== null) {
                        $pairs[] = [(string) $paging->param, $cursor];
                    }

                    if ($paging->style !== 'link_header' && $paging->sizeParam !== null && $paging->size !== null) {
                        $pairs[] = [$paging->sizeParam, (string) $paging->size];
                    }

                    $url = self::withKey(EndpointQuery::append($base, $pairs), $key);
                }

                // Every page is given what is left of the byte budget, counted on the decompressed stream of all pages together. A budget
                // that is exactly spent fails the next request before it is sent, an empty terminator page included: it counts too.
                $remaining = $limit === null ? $request->maxResponseBytes : $limit - $consumed;

                if ($remaining !== null && $remaining <= 0) {
                    throw new ResponseLimitExceeded($consumed, (int) $limit);
                }

                // A paged read-only POST is a new request for every page: its Idempotency-Key is derived per page (page 1 keeps the Operation id).
                $pageHeaders = $headers;

                if ($page > 1 && $request->method === 'POST' && $request->idempotencyKey !== null) {
                    $pageHeaders = self::without($pageHeaders, 'Idempotency-Key');
                    $pageHeaders['Idempotency-Key'] = hash('sha256', $request->idempotencyKey.':'.$page);
                }

                try {
                    $answer = $request->scheme === CredentialScheme::OAuth2ClientCredentials
                        ? $this->sendWithToken($request, $url, $pageHeaders, $credentials, $remaining, $token)
                        : $this->send($request, $url, $pageHeaders, $credentials, $remaining);
                } catch (ResponseLimitExceeded $e) {
                    throw new ResponseLimitExceeded($consumed + $e->bytesRead, $limit ?? $e->limit, $e->status);
                }

                $response = new FetchResponse($answer->status, $answer->headers, $answer->body, strlen($answer->body), self::elapsed($started));

                if (! $response->successful()) {
                    // Whatever the page answered is the outcome: the run is over and nothing of the earlier pages is kept.
                    return new FetchResponse($response->status, $response->headers, $response->body, $consumed + $response->bytes, $response->latencyMs, $fetched, $page);
                }

                $consumed += $response->bytes;
                $document = $response->json();
                [$found, $list] = PaginationPath::find($document, $paging->recordsPath);

                if (! $found || ! is_array($list) || ! array_is_list($list)) {
                    throw new NotJsonResponse('records_path');
                }

                if ($terminated && $cap !== null && $page > $cap && $list !== []) {
                    // The cap's worth of pages were all there was to read only if this page is empty: it is not.
                    throw new PageLimitExceeded($cap);
                }

                // An API that ignores the page or offset parameter answers with the same records every time.
                $text = $list === [] ? null : LosslessJson::encode($list);

                if ($text !== null && $text === $previous) {
                    throw new PaginationFailed('page_repeat');
                }

                $previous = $text;
                $fetched++;

                if ($page === 1) {
                    $first = $document;
                    $firstHeaders = $response->headers;
                    $records = $list;
                } else {
                    foreach ($list as $record) {
                        $records[] = $record;
                    }
                }

                switch ($paging->style) {
                    case 'page':
                        $done = $list === [];

                        break;
                    case 'offset':
                        $done = $list === [];
                        $offset += count($list);

                        break;
                    case 'cursor':
                        $cursor = $this->nextCursor($document, (string) $paging->cursorPath, $seen);
                        $done = $cursor === null;

                        break;
                    case 'link_header':
                        $nextUrl = $this->nextLink($request, $answer, $answer->url, $seen);
                        $done = $nextUrl === null;

                        break;
                }
            } catch (PageFailed $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new PageFailed($page, $e, $fetched);
            }

            if ($done) {
                break;
            }
        }

        $merged = PaginationPath::replace($first, $paging->recordsPath, $records);

        return new FetchResponse($response->status, $firstHeaders, LosslessJson::encode($merged), $consumed, self::elapsed($started), $fetched, $page);
    }

    /**
     * The cursor the page names, as the token to send next; null when there is none (the end of the run).
     *
     * @param  array<string, true>  $seen
     *
     * @throws PaginationFailed when the cursor is not a plain token, or was already used (a loop)
     */
    private function nextCursor(mixed $document, string $path, array &$seen): ?string
    {
        [$found, $value] = PaginationPath::find($document, $path);

        if (! $found || $value === null) {
            return null;
        }

        $cursor = match (true) {
            is_string($value) => $value,
            $value instanceof DecimalLiteral => $value->lexeme,
            default => throw new PaginationFailed('cursor_invalid'),
        };

        if ($cursor === '') {
            return null;
        }

        if (strlen($cursor) > 4096) {
            throw new PaginationFailed('cursor_invalid');
        }

        if (isset($seen[$cursor])) {
            throw new PaginationFailed('cursor_loop');
        }

        $seen[$cursor] = true;

        return $cursor;
    }

    /**
     * The `rel="next"` target of the `Link` header of the answer to `$current`, as an absolute URL; null when there is none.
     * It must have exactly the scheme, host and port of `$current`: otherwise the run is refused before anything is sent.
     *
     * @param  array<string, true>  $seen
     *
     * @throws SsrfBlocked when the target is on another origin, another port or downgrades https to http
     * @throws PaginationFailed when the target does not parse, or was already followed
     */
    private function nextLink(FetchRequest $request, EgressResponse $answer, string $current, array &$seen): ?string
    {
        $target = null;

        foreach ($answer->headers['link'] ?? [] as $line) {
            $target = self::nextTarget($line);

            if ($target !== null) {
                break;
            }
        }

        if ($target === null) {
            return null;
        }

        $resolved = UrlReference::resolve($current, $target);
        $from = EgressOrigin::of($current);

        if ($resolved === null || $from === null) {
            throw new PaginationFailed('next_url_invalid');
        }

        $to = EgressOrigin::of($resolved);

        if ($to === null && preg_match('~\Ahttps?://[^/?#@]*(?:[/?#]|\z)~i', $resolved) === 1) {
            // It names http or https, has no userinfo, and still does not parse as a URL.
            throw new PaginationFailed('next_url_invalid');
        }

        if ($to === null || ! $to->equals($from)) {
            $this->blocks->record($request->workspaceId, EgressReason::PaginationRefused, $to?->host, $to?->port);

            throw new SsrfBlocked(EgressReason::PaginationRefused);
        }

        $id = hash('sha256', $resolved);

        if (isset($seen[$id])) {
            throw new PaginationFailed('link_loop');
        }

        $seen[$id] = true;

        return $resolved;
    }

    /** The target of the first `rel="next"` link-value of one `Link` header line. */
    private static function nextTarget(string $line): ?string
    {
        if (preg_match_all('/<([^>]*)>([^<]*)/', $line, $links, PREG_SET_ORDER) < 1) {
            return null;
        }

        foreach ($links as $link) {
            if (preg_match_all('/;\s*rel\s*=\s*(?:"([^"]*)"|([^\s;,"]+))/i', $link[2], $rels, PREG_SET_ORDER) < 1) {
                continue;
            }

            // Only the first `rel` parameter counts (RFC 8288), and it may list several relation types.
            $rel = $rels[0];
            $types = preg_split('/\s+/', strtolower(trim(($rel[1] ?? '') !== '' ? $rel[1] : ($rel[2] ?? ''))));

            if (in_array('next', $types ?: [], true)) {
                return $link[1];
            }
        }

        return null;
    }

    /** @param  array{0: string, 1: string}|null  $key */
    private static function withKey(string $url, ?array $key): string
    {
        if ($key === null) {
            return $url;
        }

        // An API that echoes the key in its next link must not get it twice.
        $url = self::withoutParam($url, $key[0]);

        return $url.(str_contains($url, '?') ? '&' : '?').rawurlencode($key[0]).'='.rawurlencode($key[1]);
    }

    /** The URL without any query parameter of that name. */
    private static function withoutParam(string $url, string $name): string
    {
        $at = strpos($url, '?');

        if ($at === false) {
            return $url;
        }

        $kept = array_filter(
            explode('&', substr($url, $at + 1)),
            fn (string $pair): bool => $pair !== '' && rawurldecode(explode('=', $pair, 2)[0]) !== $name,
        );

        return substr($url, 0, $at).($kept === [] ? '' : '?'.implode('&', $kept));
    }

    private static function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }

    /** @param  array<string, string>  $headers
     * @param  array<string, string>  $credentials */
    private function send(FetchRequest $request, string $url, array $headers, array $credentials, ?int $maxBytes): EgressResponse
    {
        return $this->egress->send($request->workspaceId, new EgressRequest($url, $request->method, $headers, $credentials, $request->method === 'POST' ? $request->body : null, $request->timeoutSeconds, $maxBytes));
    }

    /**
     * An OAuth2 call (Story 2.7): the cached token, or a fresh one, as the Bearer. A 401 drops the cache entry, asks for a new
     * token once and repeats the call once; a second 401 is {@see AuthFailed} and nothing more is tried. A POST is not repeated: its
     * 401 is {@see AuthFailed} at once. The token in use is kept in `$token` between the pages of one run (Story 2.11), so a token that
     * is not cached is requested once per run, not once per page.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $credentials
     *
     * @param-out string $token
     *
     * @throws AuthFailed
     */
    private function sendWithToken(FetchRequest $request, string $url, array $headers, array $credentials, ?int $maxBytes, ?string &$token): EgressResponse
    {
        $token = $rejected = $token ?? $this->token($request, false);
        $response = $this->send($request, $url, $headers, $credentials + ['Authorization' => 'Bearer '.$token], $maxBytes);

        if ($response->status !== 401) {
            return $response;
        }

        $this->forgetToken($request, $rejected);

        // A POST is never repeated automatically: the first send may have been acted on, so no second one is made.
        if ($request->method === 'POST') {
            throw new AuthFailed('api_401');
        }

        $token = $this->token($request, true);
        $response = $this->send($request, $url, $headers, $credentials + ['Authorization' => 'Bearer '.$token], $maxBytes);

        if ($response->status === 401) {
            throw new AuthFailed('api_401');
        }

        return $response;
    }

    /**
     * @param  array<string, string>  $set
     * @return array<string, string>
     */
    private static function without(array $set, string $name): array
    {
        return array_filter($set, fn (string $key): bool => strcasecmp($key, $name) !== 0, ARRAY_FILTER_USE_KEY);
    }

    /** The access token: from the cache unless `$fresh`, else requested (and cached when it may be). */
    private function token(FetchRequest $request, bool $fresh): string
    {
        $cacheable = $this->cacheable($request);

        if ($cacheable && ! $fresh) {
            $cached = $this->tokenCache->get($request->workspaceId, (string) $request->dataSourceId, (int) $request->secretVersion, $this->binding($request));

            if ($cached !== null) {
                return $cached;
            }
        }

        $skew = $this->settings->tokenSkewSeconds();
        $issued = $this->tokens->request($request, $this->secret($request, SecretSlots::OAUTH_CLIENT_SECRET));

        if ($cacheable && $issued->expiresIn !== null && $issued->expiresIn - $skew > 0) {
            $this->tokenCache->put($request->workspaceId, (string) $request->dataSourceId, (int) $request->secretVersion, $this->binding($request), $issued->accessToken, $issued->expiresIn - $skew);
        }

        return $issued->accessToken;
    }

    private function forgetToken(FetchRequest $request, string $rejected): void
    {
        if ($this->cacheable($request)) {
            $this->tokenCache->forgetIfRejected($request->workspaceId, (string) $request->dataSourceId, (int) $request->secretVersion, $this->binding($request), $rejected);
        }
    }

    /**
     * Only a token requested with a stored client secret of a saved Data Source is cached: a secret typed into a form that
     * is being tested (a transient one) has no version of its own, and its token must not outlive the test.
     */
    private function cacheable(FetchRequest $request): bool
    {
        if ($request->dataSourceId === null || $request->secretVersion === null) {
            return false;
        }

        foreach ($request->secretRefs as $ref) {
            if ($ref->slot === SecretSlots::OAUTH_CLIENT_SECRET) {
                return $ref->operationId === null;
            }
        }

        return false;
    }

    private function binding(FetchRequest $request): string
    {
        return OAuthTokenCache::binding((string) $request->oauthTokenUrl, (string) $request->oauthClientId, $request->oauthScope);
    }

    private function apiKeyName(FetchRequest $request): string
    {
        return $request->apiKeyName ?? throw new InvalidArgumentException('An API key request needs the name the key is sent under.');
    }

    private function secret(FetchRequest $request, string $slot): string
    {
        foreach ($request->secretRefs as $ref) {
            if ($ref->slot === $slot) {
                // A transient secret was sealed for the Operation that owns it; a stored one for its Data Source.
                $owner = $ref->operationId ?? $request->dataSourceId ?? throw new SecretMissing;

                return $this->vault->resolve($ref, new SecretContext($request->workspaceId, $owner, $slot, $ref->purpose));
            }
        }

        throw new SecretMissing;
    }
}
