<?php

namespace App\Modules\Ingestion\Application;

use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\Endpoint;
use App\Modules\Connector\Contracts\EndpointNotFound;
use App\Modules\Connector\Contracts\Endpoints;
use App\Modules\Ingestion\Contracts\EndpointUserBindings;

/** {@see EndpointUserBindings} over Connector's Endpoint contract: the same references {@see Subscribe} reads `bound` values by. */
final class ReadEndpointUserBindings implements EndpointUserBindings
{
    public function __construct(private readonly Endpoints $endpoints) {}

    public function forEndpoint(string $workspaceId, string $dataSourceId, string $endpointId): array
    {
        try {
            $endpoint = $this->endpoints->find($workspaceId, strtolower($dataSourceId), strtolower($endpointId));
        } catch (DataSourceNotFound|EndpointNotFound) {
            return [];
        }

        $bound = [];

        foreach ($endpoint->params as $param) {
            if (Endpoint::isUserBound($param['binding'])) {
                $bound[$param['name']] = ['binding' => $param['binding'], 'key' => $param['binding'] === 'user_attribute' ? (string) $param['value'] : null];
            }
        }

        foreach ($endpoint->headers as $header) {
            if (Endpoint::isUserBound($header['binding'])) {
                $bound['header:'.$header['name']] = ['binding' => $header['binding'], 'key' => $header['binding'] === 'user_attribute' ? (string) $header['value'] : null];
            }
        }

        return $bound;
    }
}
