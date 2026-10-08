<?php

namespace App\Modules\Connector\Infrastructure;

use App\Modules\Connector\Contracts\AuthFailed;
use App\Modules\Connector\Contracts\ConnectionTestCode;
use App\Modules\Connector\Contracts\EgressRequest;
use App\Modules\Connector\Contracts\EgressResponse;
use App\Modules\Connector\Contracts\EgressTransport;
use App\Modules\Connector\Contracts\EgressTransportFailed;
use App\Modules\Connector\Contracts\FetchRequest;
use App\Modules\Connector\Contracts\FetchResponse;
use App\Modules\Connector\Contracts\NotJsonResponse;
use App\Modules\Connector\Contracts\OAuthToken;
use App\Modules\Connector\Contracts\ResponseLimitExceeded;
use App\Modules\Connector\Contracts\SsrfBlocked;
use App\Modules\Connector\Contracts\TokenRequestFailed;
use App\Modules\Connector\Contracts\TokenRequestLog;
use App\Platform\Json\DecimalLiteral;
use App\Platform\Json\JsonObject;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Asks a token endpoint for an access token (Story 2.7; client credentials grant): a `POST` of
 * `application/x-www-form-urlencoded` (`grant_type=client_credentials`, `client_id`, `client_secret`, optional `scope`)
 * through the {@see EgressTransport}, so the guard decides the URL, with no redirect followed (any 3xx is a failure). The
 * answer is read under the platform size cap and must pass the JSON check (Story 2.6) and carry a string `access_token`
 * and a `token_type` of `bearer` (any case), with an optional numeric `expires_in`. A 400 or 401 is an authentication
 * failure ({@see AuthFailed}); any other failure is the transport's own exception or {@see TokenRequestFailed}.
 *
 * Every request, whatever its outcome, is one `sync_runs` row of kind `oauth_token` through the {@see TokenRequestLog}
 * (token URL without query, status, latency, bytes and the user code): never a token, a secret or a body.
 */
final class OAuthTokenClient
{
    /** A token longer than this, or with anything but visible ASCII, cannot travel in a header and is refused. */
    private const TOKEN_MAX = 8192;

    public function __construct(
        private readonly EgressTransport $egress,
        private readonly TokenRequestLog $log,
    ) {}

    /**
     * @throws AuthFailed when the endpoint answers 400 or 401
     * @throws TokenRequestFailed|NotJsonResponse|ResponseLimitExceeded|SsrfBlocked|EgressTransportFailed on any other failure
     */
    public function request(FetchRequest $request, #[\SensitiveParameter] string $clientSecret): OAuthToken
    {
        $url = $request->oauthTokenUrl ?? throw new \InvalidArgumentException('An OAuth2 request needs a token URL.');
        $clientId = $request->oauthClientId ?? throw new \InvalidArgumentException('An OAuth2 request needs a client ID.');

        $fields = ['grant_type' => 'client_credentials', 'client_id' => $clientId, 'client_secret' => $clientSecret];

        if ($request->oauthScope !== null && $request->oauthScope !== '') {
            $fields['scope'] = $request->oauthScope;
        }

        $startedAt = CarbonImmutable::now()->utc();
        $began = hrtime(true);
        $status = null;
        $bytes = 0;
        $code = null;

        try {
            $response = $this->egress->send($request->workspaceId, new EgressRequest(
                $url, 'POST',
                ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
                [], http_build_query($fields, '', '&', PHP_QUERY_RFC3986), $request->timeoutSeconds, null, true,
            ));
            $status = $response->status;
            $bytes = strlen($response->body);

            return $this->token($response, $status);
        } catch (AuthFailed $e) {
            $code = ConnectionTestCode::AuthFailed->value;

            throw $e;
        } catch (SsrfBlocked $e) {
            $code = ConnectionTestCode::forEgress($e->reason)->value;

            throw $e;
        } catch (NotJsonResponse $e) {
            $code = ConnectionTestCode::NotJson->value;

            throw $e;
        } catch (ResponseLimitExceeded $e) {
            $status = $e->status;
            $bytes = $e->bytesRead;
            $code = ConnectionTestCode::ResponseTooLarge->value;

            throw $e;
        } catch (Throwable $e) {
            $code = ConnectionTestCode::FetchFailed->value;

            throw $e;
        } finally {
            $latencyMs = (int) round((hrtime(true) - $began) / 1_000_000);

            $this->log->record($request->workspaceId, $request->dataSourceId, $url, $status, $latencyMs, $bytes, $code, $startedAt);
        }
    }

    private function token(EgressResponse $answer, int $status): OAuthToken
    {
        if ($status >= 300 && $status < 400) {
            throw new TokenRequestFailed('redirect');
        }

        if ($status === 400 || $status === 401) {
            throw new AuthFailed('token_rejected');
        }

        if ($status < 200 || $status >= 300) {
            throw new TokenRequestFailed('http_'.$status);
        }

        // The JSON check of Story 2.6: the media type, a body that parses, within the depth limit. Numbers stay lexemes.
        $body = (new FetchResponse($answer->status, $answer->headers, $answer->body, strlen($answer->body), 0))->json();

        if (! $body instanceof JsonObject) {
            throw new TokenRequestFailed('token_invalid');
        }

        $token = $body->get('access_token');
        $type = $body->get('token_type');

        if (! is_string($token) || $token === '' || strlen($token) > self::TOKEN_MAX || preg_match('/\A[\x21-\x7e]+\z/D', $token) !== 1
            || ! is_string($type) || strtolower($type) !== 'bearer') {
            throw new TokenRequestFailed('token_invalid');
        }

        return new OAuthToken($token, self::expiresIn($body->get('expires_in')));
    }

    /** Whole seconds from a JSON number; null for anything else (a string, a negative or a number that is not finite). */
    private static function expiresIn(mixed $value): ?int
    {
        if (! $value instanceof DecimalLiteral) {
            return null;
        }

        $seconds = (float) $value->lexeme;

        if (! is_finite($seconds) || $seconds < 0) {
            return null;
        }

        return (int) min(floor($seconds), 2147483647);
    }
}
