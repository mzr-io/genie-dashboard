<?php

namespace App\Modules\Connector\Contracts;

use JsonSerializable;

/**
 * What the fetch pipeline is asked to call (AR-26): the Workspace, Data Source and Endpoint, a sanitized URL template, the
 * parameter names, the credential scheme and the `secret_ref`s, plus (since version 2) the plain request settings of the
 * Data Source: its non-secret default headers (a secret one is only a name, its value a `header:{name}` ref), the API
 * key's name and placement, the timeout, the method (version 3) the Data Source's own response size limit and (version 4) what an OAuth2 client-credentials call needs to get a token: the token URL, the scope, the plain client ID and the `secret_version` of the client secret (the token cache key). Version 5 (Story 2.10) adds what an Endpoint test sends: the rendered URL (the path with its values filled in), the query pairs, the rendered header pairs, the rendered body, the Idempotency-Key and the read-only flag. Those hold the Admin's test values, so they are never serialised ({@see self::toArray()} carries the template, the parameter names and counts only), never logged and never recorded. Version 6 (Story 2.11) adds the Data Source's pagination (the style, the parameter names, the size, the records path and the cursor path) and its `max_pages`: names and limits only, never a value. Never a secret value, a ciphertext or a resolved parameter:
 * {@see FetchTransport} resolves the refs through {@see SecretVault::resolve} at egress. Versioned, so a later change to
 * the shape is explicit. A connection test has no Endpoint (`endpointId` is null), and an unsaved form no Data Source.
 */
final readonly class FetchRequest implements JsonSerializable
{
    public const VERSION = 6;

    public string $urlTemplate;

    public ?string $oauthTokenUrl;

    /**
     * @param  list<string>  $parameterNames
     * @param  list<SecretRef>  $secretRefs
     * @param  list<array{name: string, value: string, secret?: true}>  $headers  the default headers; a secret one has an empty value
     * @param  list<array{0: string, 1: string}>  $queryPairs  the query string as `[name, value]` pairs; {@see EndpointQuery::build()} percent-encodes them
     * @param  list<array{name: string, value: string}>  $endpointHeaders  the Endpoint's rendered headers, applied after the default headers
     */
    public function __construct(
        public string $workspaceId,
        public ?string $dataSourceId,
        public ?string $endpointId,
        string $urlTemplate,
        public array $parameterNames,
        public CredentialScheme $scheme,
        public array $secretRefs,
        public array $headers = [],
        public ?string $apiKeyName = null,
        public ?string $apiKeyPlacement = null,
        public ?int $timeoutSeconds = null,
        public string $method = 'GET',
        public ?int $maxResponseBytes = null,
        ?string $oauthTokenUrl = null,
        public ?string $oauthClientId = null,
        public ?string $oauthScope = null,
        public ?int $secretVersion = null,
        /** The URL to call, the base URL with the rendered path and no query (null: the template is the URL). Holds test values. */
        public ?string $url = null,
        public array $queryPairs = [],
        public array $endpointHeaders = [],
        /** The rendered JSON body of a POST. Holds test values. */
        public ?string $body = null,
        /** Sent as `Idempotency-Key` on a POST (the Operation id). */
        public ?string $idempotencyKey = null,
        /** True only for a POST whose Endpoint revision is flagged read-only; any other POST is refused by the transport. */
        public bool $readOnlyQuery = false,
        /** How the API pages its answers (Story 2.11); names and numbers only. Not used by a connection test. */
        public Pagination $pagination = new Pagination,
        /** The Data Source's own page cap; the transport applies the smaller of it and the platform ceiling ({@see PageLimit}). */
        public ?int $maxPages = null,
    ) {
        // The template is kept without userinfo, query and fragment: a credential placed in a URL never travels here.
        $url = (string) preg_replace('/[?#].*\z/s', '', $urlTemplate);
        $this->urlTemplate = (string) preg_replace('~\A([a-z][a-z0-9+.-]*://)[^/@]*@~i', '$1', $url);
        $this->oauthTokenUrl = $oauthTokenUrl === null ? null : (string) preg_replace('~\A([a-z][a-z0-9+.-]*://)[^/@]*@~i', '$1', (string) preg_replace('/[?#].*\z/s', '', $oauthTokenUrl));
    }

    /**
     * @param  list<string>  $parameterNames
     * @param  array<string, SecretStatus>  $secrets  the Data Source's slot statuses; only the slots that hold a value become refs
     */
    public static function for(string $workspaceId, DataSource $source, string $endpointId, string $urlTemplate, array $parameterNames, array $secrets): self
    {
        $refs = [];

        foreach ($secrets as $status) {
            if ($status->configured && $status->id !== null) {
                $refs[] = new SecretRef($status->id, $status->slot, secretVersion: $status->secretVersion ?? 1);
            }
        }

        $client = $secrets[SecretSlots::OAUTH_CLIENT_SECRET] ?? null;

        return new self(
            $workspaceId, $source->id, $endpointId, $urlTemplate, $parameterNames, CredentialScheme::forSource($source), $refs,
            array_map(fn (array $header): array => ($header['secret'] ?? false) === true ? ['name' => $header['name'], 'value' => '', 'secret' => true] : $header, $source->headers),
            $source->apiKeyName, $source->apiKeyPlacement, $source->timeoutSeconds, 'GET', $source->maxResponseBytes,
            $source->oauthTokenUrl, $source->oauthClientId, $source->oauthScope, $client?->secretVersion,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'v' => self::VERSION,
            'workspace_id' => $this->workspaceId,
            'data_source_id' => $this->dataSourceId,
            'endpoint_id' => $this->endpointId,
            'url_template' => $this->urlTemplate,
            'parameter_names' => $this->parameterNames,
            'credential_scheme' => $this->scheme->value,
            'secret_refs' => array_map(fn (SecretRef $ref): array => $ref->toArray(), $this->secretRefs),
            'headers' => $this->headers,
            'api_key_name' => $this->apiKeyName,
            'api_key_placement' => $this->apiKeyPlacement,
            'timeout_seconds' => $this->timeoutSeconds,
            'method' => $this->method,
            'max_response_bytes' => $this->maxResponseBytes,
            'oauth_token_url' => $this->oauthTokenUrl,
            'oauth_client_id' => $this->oauthClientId,
            'oauth_scope' => $this->oauthScope,
            'secret_version' => $this->secretVersion,
            // Counts and a flag only: the pairs, the rendered URL and the body hold the Admin's test values.
            'query_count' => count($this->queryPairs),
            'endpoint_header_count' => count($this->endpointHeaders),
            'has_body' => $this->body !== null,
            'read_only_query' => $this->readOnlyQuery,
            'pagination' => $this->pagination->toArray(),
            'max_pages' => $this->maxPages,
        ];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
