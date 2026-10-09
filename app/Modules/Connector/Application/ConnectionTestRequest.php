<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\CredentialScheme;
use App\Modules\Connector\Contracts\DataSourceUrl;
use App\Modules\Connector\Contracts\FetchRequest;
use App\Modules\Connector\Contracts\SecretRef;
use App\Modules\Connector\Contracts\SecretSlots;
use App\Modules\Connector\Contracts\SecretVault;
use Illuminate\Support\Facades\DB;

/**
 * Builds the {@see FetchRequest} of a call to a Data Source's Base URL from the plain settings of its form: the connection test (Story 2.5)
 * and, since Story 2.18, the health probe. Extracted from {@see RunConnectionTest} with no change in behaviour.
 */
final class ConnectionTestRequest
{
    public function __construct(private readonly SecretVault $vault) {}

    /**
     * Refs only for the credentials the form uses, a transient row (the Operation's) before a stored one (the saved Data Source's). Expired
     * transient rows are not listed. With no `$operationId` (a probe) only the stored secrets are used.
     *
     * @param  array<string, mixed>  $input
     * @param  string|null  $callUrl  the URL to call when it is not the Base URL (the probe's health path); never part of the template, which stays the Base URL
     */
    public function build(string $workspaceId, ?string $operationId, array $input, DataSourceUrl $url, ?string $dataSourceId, ?string $callUrl = null): FetchRequest
    {
        $authType = is_string($input['auth_type'] ?? null) ? $input['auth_type'] : 'none';
        $headers = [];

        foreach (is_array($input['headers'] ?? null) ? $input['headers'] : [] as $header) {
            if (is_array($header) && is_string($header['name'] ?? null)) {
                $headers[] = ($header['secret'] ?? false) === true
                    ? ['name' => $header['name'], 'value' => '', 'secret' => true]
                    : ['name' => $header['name'], 'value' => is_string($header['value'] ?? null) ? $header['value'] : ''];
            }
        }

        $used = SecretSlots::used($authType, $headers);
        $refs = [];

        foreach ($operationId === null ? [] : DB::select(
            'select id, slot from secrets where workspace_id = ? and ephemeral and operation_id = ? and expires_at > now()',
            [$workspaceId, $operationId],
        ) as $row) {
            /** @var object{id: string, slot: string} $row */
            if (isset($used[$row->slot])) {
                $refs[$row->slot] = new SecretRef(strtolower($row->id), $row->slot, operationId: $operationId);
            }
        }

        if ($dataSourceId !== null) {
            foreach ($this->vault->status($workspaceId, $dataSourceId) as $slot => $status) {
                if (! isset($refs[$slot]) && isset($used[$slot]) && $status->id !== null) {
                    $refs[$slot] = new SecretRef($status->id, $slot, secretVersion: $status->secretVersion ?? 1);
                }
            }
        }

        $placement = is_string($input['api_key_placement'] ?? null) ? $input['api_key_placement'] : null;
        $client = $refs[SecretSlots::OAUTH_CLIENT_SECRET] ?? null;

        return new FetchRequest(
            $workspaceId, $dataSourceId, null, $url->baseUrl, [], CredentialScheme::fromAuth($authType, $placement), array_values($refs),
            $headers, is_string($input['api_key_name'] ?? null) ? $input['api_key_name'] : null, $placement,
            is_int($input['timeout_seconds'] ?? null) ? $input['timeout_seconds'] : null,
            maxResponseBytes: is_int($input['max_response_bytes'] ?? null) ? $input['max_response_bytes'] : null,
            oauthTokenUrl: is_string($input['oauth_token_url'] ?? null) ? $input['oauth_token_url'] : null,
            oauthClientId: is_string($input['oauth_client_id'] ?? null) ? $input['oauth_client_id'] : null,
            oauthScope: is_string($input['oauth_scope'] ?? null) ? $input['oauth_scope'] : null,
            secretVersion: $client?->secretVersion,
            url: $callUrl,
        );
    }
}
