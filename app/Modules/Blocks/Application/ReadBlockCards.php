<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\BlockCard;
use App\Modules\Blocks\Contracts\BlockCards;
use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\DataSources;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** {@see BlockCards}: the Block's current published version, as the frozen `chrome`, `sizes` and `refresh_interval` of its config. */
final class ReadBlockCards implements BlockCards
{
    public function __construct(private readonly DataSources $sources) {}

    public function forBlocks(string $workspaceId, array $blockIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(strtolower(...), $blockIds), Str::isUuid(...))));

        if ($ids === []) {
            return [];
        }

        $rows = DB::select(
            'select b.id as block_id, b.type_key, v.id as version_id, v.version, v.name, v.config::text as config from blocks b '
            ."join block_versions v on v.workspace_id = b.workspace_id and v.id = b.current_version_id and v.state = 'published' "
            .'where b.workspace_id = ? and b.id in ('.implode(', ', array_fill(0, count($ids), '?')).')',
            [$workspaceId, ...$ids],
        );

        $cards = [];

        foreach ($rows as $row) {
            $config = DraftConfig::decode((string) $row->config);
            $chrome = is_array($config['chrome'] ?? null) ? $config['chrome'] : [];
            $sizes = is_array($config['sizes'] ?? null) ? $config['sizes'] : [];
            $flag = fn (string $name, bool $default): bool => is_bool($chrome[$name] ?? null) ? $chrome[$name] : $default;
            $interval = $config['refresh_interval'] ?? null;

            $cards[strtolower((string) $row->block_id)] = new BlockCard(
                strtolower((string) $row->block_id),
                strtolower((string) $row->version_id),
                (string) $row->name,
                is_string($chrome['description'] ?? null) && $chrome['description'] !== '' ? $chrome['description'] : null,
                (string) $row->type_key,
                is_string($chrome['icon'] ?? null) ? $chrome['icon'] : null,
                [
                    'show_header' => $flag('show_header', true),
                    'show_subtitle' => $flag('show_subtitle', true),
                    'allow_resize' => $flag('allow_resize', true),
                    'allow_minimize' => $flag('allow_minimize', false),
                    'allow_refresh' => $flag('allow_refresh', true),
                    'show_footer_action' => $flag('show_footer_action', false),
                ],
                [
                    'min_width' => is_int($sizes['min_width'] ?? null) ? $sizes['min_width'] : 3,
                    'max_width' => is_int($sizes['max_width'] ?? null) ? $sizes['max_width'] : 12,
                    'width' => is_int($sizes['width'] ?? null) ? $sizes['width'] : 3,
                    'min_height' => is_string($sizes['min_height'] ?? null) ? $sizes['min_height'] : 'small',
                    'max_height' => is_string($sizes['max_height'] ?? null) ? $sizes['max_height'] : 'large',
                    'height' => is_string($sizes['height'] ?? null) ? $sizes['height'] : 'small',
                ],
                is_int($interval) || $interval === 'live' ? $interval : null,
                [
                    'description' => is_string($chrome['description'] ?? null) && $chrome['description'] !== '' ? $chrome['description'] : null,
                    'source_name' => $this->dataSourceName($workspaceId, $config),
                    'refresh_interval' => is_int($interval) || $interval === 'live' ? $interval : null,
                    'version' => (string) $row->version,
                    'period' => is_string($config['period'] ?? null) ? $config['period'] : null,
                ],
            );
        }

        return $cards;
    }

    public function exists(string $workspaceId, string $blockId): bool
    {
        return Str::isUuid($blockId)
            && DB::selectOne('select 1 as present from blocks where workspace_id = ? and id = ?', [$workspaceId, strtolower($blockId)]) !== null;
    }

    /** @param  array<string, mixed>  $config */
    private function dataSourceName(string $workspaceId, array $config): ?string
    {
        $id = $config['source']['data_source_id'] ?? null;

        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        try {
            return $this->sources->find($workspaceId, strtolower($id))->name;
        } catch (DataSourceNotFound) {
            return null;
        }
    }
}
