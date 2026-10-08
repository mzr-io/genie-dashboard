<?php

namespace App\Modules\Connector\Infrastructure;

use App\Modules\Connector\Contracts\AuthFailed;
use App\Modules\Connector\Contracts\CredentialScheme;
use App\Modules\Connector\Contracts\EgressRequest;
use App\Modules\Connector\Contracts\EgressResponse;
use App\Modules\Connector\Contracts\EgressTransport;
use App\Modules\Connector\Contracts\EndpointQuery;
use App\Modules\Connector\Contracts\FetchRequest;
use App\Modules\Connector\Contracts\FetchResponse;
use App\Modules\Connector\Contracts\FetchTransport;
use App\Modules\Connector\Contracts\SecretContext;
use App\Modules\Connector\Contracts\SecretMissing;
use App\Modules\Connector\Contracts\SecretSlots;
use App\Modules\Connector\Contracts\SecretVault;
use InvalidArgumentException;

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
 */
final class DirectFetchTransport implements FetchTransport
{
    public function __construct(
        private readonly EgressTransport $egress,
        private readonly SecretVault $vault,
        private readonly OAuthTokenClient $tokens,
        private readonly OAuthTokenCache $tokenCache,
        private readonly SecretSettings $settings,
    ) {}

    public function fetch(FetchRequest $request): FetchResponse
    {
        if (! in_array($request->method, ['GET', 'POST'], true) || ($request->method === 'POST' && ! $request->readOnlyQuery)) {
            throw new InvalidArgumentException('Only a GET and a read-only POST are sent.');
        }

        $headers = [];
        $credentials = [];
        $url = EndpointQuery::append($request->url ?? $request->urlTemplate, $request->queryPairs);

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
                $url .= (str_contains($url, '?') ? '&' : '?').rawurlencode($this->apiKeyName($request)).'='.rawurlencode($this->secret($request, SecretSlots::API_KEY));

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

        if ($request->scheme === CredentialScheme::OAuth2ClientCredentials) {
            $response = $this->sendWithToken($request, $url, $headers, $credentials);
        } else {
            $response = $this->send($request, $url, $headers, $credentials);
        }

        $latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);

        return new FetchResponse($response->status, $response->headers, $response->body, strlen($response->body), $latencyMs);
    }

    /** @param  array<string, string>  $headers
     * @param  array<string, string>  $credentials */
    private function send(FetchRequest $request, string $url, array $headers, array $credentials): EgressResponse
    {
        return $this->egress->send($request->workspaceId, new EgressRequest($url, $request->method, $headers, $credentials, $request->method === 'POST' ? $request->body : null, $request->timeoutSeconds, $request->maxResponseBytes));
    }

    /**
     * An OAuth2 call (Story 2.7): the cached token, or a fresh one, as the Bearer. A 401 drops the cache entry, asks for a new
     * token once and repeats the call once; a second 401 is {@see AuthFailed} and nothing more is tried. A POST is not repeated: its
     * 401 is {@see AuthFailed} at once.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $credentials
     *
     * @throws AuthFailed
     */
    private function sendWithToken(FetchRequest $request, string $url, array $headers, array $credentials): EgressResponse
    {
        $token = $rejected = $this->token($request, false);
        $response = $this->send($request, $url, $headers, $credentials + ['Authorization' => 'Bearer '.$token]);

        if ($response->status !== 401) {
            return $response;
        }

        $this->forgetToken($request, $rejected);

        // A POST is never repeated automatically: the first send may have been acted on, so no second one is made.
        if ($request->method === 'POST') {
            throw new AuthFailed('api_401');
        }

        $token = $this->token($request, true);
        $response = $this->send($request, $url, $headers, $credentials + ['Authorization' => 'Bearer '.$token]);

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
