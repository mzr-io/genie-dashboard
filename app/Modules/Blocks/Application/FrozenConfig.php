<?php

namespace App\Modules\Blocks\Application;

use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\Mapping\Contracts\Presentation;

/**
 * The immutable document of a published version (Story 3.19, `schema_version` 1), built from a validated Draft at the freeze. Pure: it reads the Draft's
 * stored `config` and what the freeze resolved, and decides nothing the Admin did not already choose. The keys are the version's contract:
 *
 *  schema_version, type_key, contract_version  what the version is a version of (the Block Type is pinned by key and contract version)
 *  source        the Draft's Data Source and Endpoint choices as stored: Fixed parameters, refresh interval and the revisions pinned by Step 2
 *  endpoint      {id, revision} the Endpoint revision the passed ValidationReport was made against
 *  fields        {record_path, items: [{alias, path, role, type}]}: the fields the Query Plan extracts, with their roles and types
 *  slot_map      the Admin's slot decisions
 *  query_plan    the Query Plan, version 1
 *  presentation  the presentation rules ({} when none)
 *  chrome        the Step 1 options (description, icon and the on/off flags that were set)
 *  sizes         the width and height limits and defaults, the Block Type's where the Draft did not choose
 *  period        how the block follows the period (null: not chosen)
 *  refresh_interval  the refresh interval in whole seconds or `live` (null: not chosen)
 */
final class FrozenConfig
{
    private const FLAGS = ['show_header', 'show_subtitle', 'allow_resize', 'allow_minimize', 'allow_refresh', 'show_footer_action'];

    /**
     * @param  array<string, mixed>  $config  the Draft's stored `config`
     * @param  array<string, array{role: string|null, type: string|null}>  $fieldInfo  stored path => the effective role and type (null: not known)
     * @return array<string, mixed>
     */
    public static function build(array $config, BlockType $type, int $contractVersion, string $endpointId, int $endpointRevision, array $fieldInfo): array
    {
        $source = is_array($config['source'] ?? null) ? $config['source'] : [];
        $display = is_array($config['display'] ?? null) ? $config['display'] : [];
        $plan = is_array($config['query_plan'] ?? null) ? $config['query_plan'] : [];
        $roles = is_array($config['field_roles'] ?? null) ? $config['field_roles'] : [];
        $types = is_array($config['field_types'] ?? null) ? $config['field_types'] : [];
        $size = $type->size;

        $items = [];

        foreach (self::extracted($plan) as $alias => $path) {
            $info = $fieldInfo[$path] ?? ['role' => null, 'type' => null];

            $items[] = [
                'alias' => (string) $alias,
                'path' => $path,
                'role' => is_string($roles[$path] ?? null) ? $roles[$path] : $info['role'],
                'type' => is_string($types[$path] ?? null) ? $types[$path] : $info['type'],
            ];
        }

        $chrome = [];

        foreach (['description', 'icon'] as $key) {
            if (is_string($config[$key] ?? null)) {
                $chrome[$key] = $config[$key];
            }
        }

        foreach (self::FLAGS as $flag) {
            if (is_bool($display[$flag] ?? null)) {
                $chrome[$flag] = $display[$flag];
            }
        }

        $presentation = is_array($config[Presentation::KEY] ?? null) ? $config[Presentation::KEY] : [];
        $interval = $source['refresh_interval'] ?? null;

        return [
            'schema_version' => 1,
            'type_key' => $type->key,
            'contract_version' => $contractVersion,
            'source' => $source,
            'endpoint' => ['id' => $endpointId, 'revision' => $endpointRevision],
            'fields' => ['record_path' => is_string($config['record_path'] ?? null) ? $config['record_path'] : null, 'items' => $items],
            'slot_map' => is_array($config['slot_map'] ?? null) ? $config['slot_map'] : [],
            'query_plan' => $plan,
            'presentation' => $presentation,
            'chrome' => $chrome,
            'sizes' => [
                'min_width' => $display['min_width'] ?? $size['min_width'],
                'max_width' => $display['max_width'] ?? $size['max_width'],
                'width' => $display['width'] ?? $size['default_width'],
                'min_height' => $display['min_height'] ?? $size['min_height'],
                'max_height' => $display['max_height'] ?? $size['max_height'],
                'height' => $display['height'] ?? $size['default_height'],
            ],
            'period' => is_string($display['period'] ?? null) ? $display['period'] : null,
            'refresh_interval' => is_int($interval) || is_string($interval) ? $interval : null,
        ];
    }

    /**
     * Whether every field the plan reads has a role and a type, from the Admin's overrides or from the sample. A field with neither would freeze as
     * nulls into an immutable document, so Publish refuses instead.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, array{role: string|null, type: string|null}>  $fieldInfo
     */
    public static function resolved(array $config, array $fieldInfo): bool
    {
        $plan = is_array($config['query_plan'] ?? null) ? $config['query_plan'] : [];
        $roles = is_array($config['field_roles'] ?? null) ? $config['field_roles'] : [];
        $types = is_array($config['field_types'] ?? null) ? $config['field_types'] : [];

        foreach (self::extracted($plan) as $path) {
            $role = is_string($roles[$path] ?? null) ? $roles[$path] : ($fieldInfo[$path]['role'] ?? null);
            $type = is_string($types[$path] ?? null) ? $types[$path] : ($fieldInfo[$path]['type'] ?? null);

            if ($role === null || $type === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * The plan's alias => stored path pairs; a path that is not text is skipped.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, string>
     */
    private static function extracted(array $plan): array
    {
        $out = [];

        foreach (is_array($plan['extract']['fields'] ?? null) ? $plan['extract']['fields'] : [] as $alias => $path) {
            if (is_string($path)) {
                $out[(string) $alias] = $path;
            }
        }

        return $out;
    }
}
