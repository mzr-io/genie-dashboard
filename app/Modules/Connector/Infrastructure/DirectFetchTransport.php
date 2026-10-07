<?php

namespace App\Modules\Connector\Infrastructure;

use App\Modules\Connector\Contracts\CredentialScheme;
use App\Modules\Connector\Contracts\EgressRequest;
use App\Modules\Connector\Contracts\EgressTransport;
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
 * scheme is applied (a bearer or basic `Authorization` header, an API key in a header or in the query string, a secret
 * default header) and the request goes out through the {@see EgressTransport}: the guard decides every hop and the connection
 * is pinned to the address it checked. Credentials travel as credentials, so the transport drops them if the origin changes.
 */
final class DirectFetchTransport implements FetchTransport
{
    public function __construct(
        private readonly EgressTransport $egress,
        private readonly SecretVault $vault,
    ) {}

    public function fetch(FetchRequest $request): FetchResponse
    {
        $headers = [];
        $credentials = [];
        $url = $request->urlTemplate;

        foreach ($request->headers as $header) {
            if (($header['secret'] ?? false) === true) {
                $credentials[$header['name']] = $this->secret($request, SecretSlots::header($header['name']));
            } else {
                $headers[$header['name']] = $header['value'];
            }
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
        }

        $started = hrtime(true);
        $response = $this->egress->send($request->workspaceId, new EgressRequest($url, $request->method, $headers, $credentials, null, $request->timeoutSeconds));
        $latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);

        return new FetchResponse($response->status, $response->headers, $response->body, strlen($response->body), $latencyMs);
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
