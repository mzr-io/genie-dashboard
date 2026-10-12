<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\PublishedConfig;
use App\Modules\Blocks\Contracts\PublishedConfigs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** {@see PublishedConfigs}: reads `block_versions.config` through the platform's JSON codec ({@see DraftConfig::decode()}) and hands out plain arrays. */
final class ReadPublishedConfigs implements PublishedConfigs
{
    public function find(string $workspaceId, string $versionId): ?PublishedConfig
    {
        if (! Str::isUuid($versionId)) {
            return null;
        }

        $row = DB::selectOne(
            'select v.id, v.block_id, v.state, v.config::text as config from block_versions v where v.workspace_id = ? and v.id = ?',
            [$workspaceId, strtolower($versionId)],
        );

        if ($row === null) {
            return null;
        }

        $config = DraftConfig::decode((string) $row->config);
        $fields = [];

        foreach (is_array($config['fields']['items'] ?? null) ? $config['fields']['items'] : [] as $item) {
            if (is_array($item) && is_string($item['alias'] ?? null) && is_string($item['path'] ?? null)) {
                $fields[] = [
                    'alias' => $item['alias'],
                    'path' => $item['path'],
                    'role' => is_string($item['role'] ?? null) ? $item['role'] : null,
                    'type' => is_string($item['type'] ?? null) ? $item['type'] : null,
                ];
            }
        }

        $source = is_array($config['source'] ?? null) ? $config['source'] : [];
        $range = is_array($source['default_date_range'] ?? null) && is_string($source['default_date_range']['preset'] ?? null)
            ? array_filter(['preset' => $source['default_date_range']['preset'], 'from' => $source['default_date_range']['from'] ?? null, 'to' => $source['default_date_range']['to'] ?? null], is_string(...))
            : null;
        $bindings = [];

        foreach (is_array($source['bindings'] ?? null) ? $source['bindings'] : [] as $name => $value) {
            $bindings[(string) $name] = is_string($value) ? $value : null;
        }

        $endpoint = $config['endpoint']['id'] ?? $config['source']['endpoint_id'] ?? null;

        return new PublishedConfig(
            strtolower((string) $row->id),
            strtolower((string) $row->block_id),
            is_string($config['type_key'] ?? null) ? $config['type_key'] : '',
            is_int($config['contract_version'] ?? null) ? $config['contract_version'] : 1,
            (string) $row->state,
            is_array($config['query_plan'] ?? null) ? $config['query_plan'] : [],
            is_array($config['presentation'] ?? null) ? $config['presentation'] : [],
            $fields,
            is_string($endpoint) ? strtolower($endpoint) : null,
            is_string($source['data_source_id'] ?? null) ? strtolower($source['data_source_id']) : null,
            is_int($config['refresh_interval'] ?? null) || ($config['refresh_interval'] ?? null) === 'live' ? $config['refresh_interval'] : null,
            is_string($config['period'] ?? null) ? $config['period'] : null,
            $range,
            $bindings,
        );
    }
}
