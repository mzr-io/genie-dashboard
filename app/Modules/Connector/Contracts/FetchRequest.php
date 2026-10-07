<?php

namespace App\Modules\Connector\Contracts;

use JsonSerializable;

/**
 * What the fetch pipeline is asked to call (AR-26): the Workspace, Data Source and Endpoint, a sanitized URL template, the
 * parameter names, the credential scheme and the `secret_ref`s, plus (since version 2) the plain request settings of the
 * Data Source: its non-secret default headers (a secret one is only a name, its value a `header:{name}` ref), the API
 * key's name and placement, the timeout and the method. Never a secret value, a ciphertext or a resolved parameter:
 * {@see FetchTransport} resolves the refs through {@see SecretVault::resolve} at egress. Versioned, so a later change to
 * the shape is explicit. A connection test has no Endpoint (`endpointId` is null), and an unsaved form no Data Source.
 */
final readonly class FetchRequest implements JsonSerializable
{
    public const VERSION = 2;

    public string $urlTemplate;

    /**
     * @param  list<string>  $parameterNames
     * @param  list<SecretRef>  $secretRefs
     * @param  list<array{name: string, value: string, secret?: true}>  $headers  the default headers; a secret one has an empty value
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
    ) {
        // The template is kept without userinfo, query and fragment: a credential placed in a URL never travels here.
        $url = (string) preg_replace('/[?#].*\z/s', '', $urlTemplate);
        $this->urlTemplate = (string) preg_replace('~\A([a-z][a-z0-9+.-]*://)[^/@]*@~i', '$1', $url);
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
                $refs[] = new SecretRef($status->id, $status->slot);
            }
        }

        return new self(
            $workspaceId, $source->id, $endpointId, $urlTemplate, $parameterNames, CredentialScheme::forSource($source), $refs,
            array_map(fn (array $header): array => ($header['secret'] ?? false) === true ? ['name' => $header['name'], 'value' => '', 'secret' => true] : $header, $source->headers),
            $source->apiKeyName, $source->apiKeyPlacement, $source->timeoutSeconds,
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
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
