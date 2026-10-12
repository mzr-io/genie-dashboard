<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\BlockCatalogue;
use App\Modules\Blocks\Contracts\CatalogueBlock;
use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\DataSources;
use App\Platform\Json\LimitSetting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** {@see BlockCatalogue} over each Block's current published version; the card facts come from its frozen config, never from a Draft. */
final class ReadBlockCatalogue implements BlockCatalogue
{
    public function __construct(private readonly DataSources $sources, private readonly Repository $config) {}

    public function published(string $workspaceId): array
    {
        $rows = DB::select(
            'select b.id as block_id, b.type_key, v.version, v.name, v.published_at, v.config::text as config, c.name as category from blocks b '
            ."join block_versions v on v.workspace_id = b.workspace_id and v.id = b.current_version_id and v.state = 'published' "
            .'left join block_categories c on c.workspace_id = b.workspace_id and c.id = b.category_id '
            .'where b.workspace_id = ? order by v.published_at desc, b.id desc',
            [$workspaceId],
        );

        $window = $this->newWindowSeconds();
        $now = CarbonImmutable::now();
        $names = [];
        $out = [];

        foreach ($rows as $row) {
            $config = DraftConfig::decode((string) $row->config);
            $chrome = is_array($config['chrome'] ?? null) ? $config['chrome'] : [];
            $sizes = is_array($config['sizes'] ?? null) ? $config['sizes'] : [];
            $sourceId = $config['source']['data_source_id'] ?? null;
            $interval = $config['refresh_interval'] ?? null;

            if (is_string($sourceId) && Str::isUuid($sourceId) && ! array_key_exists($sourceId, $names)) {
                try {
                    $names[$sourceId] = $this->sources->find($workspaceId, strtolower($sourceId))->name;
                } catch (DataSourceNotFound) {
                    $names[$sourceId] = null;
                }
            }

            $out[] = new CatalogueBlock(
                strtolower((string) $row->block_id),
                (string) $row->name,
                is_string($chrome['description'] ?? null) && $chrome['description'] !== '' ? $chrome['description'] : null,
                $row->category === null ? null : (string) $row->category,
                (string) $row->type_key,
                is_string($sourceId) ? ($names[$sourceId] ?? null) : null,
                is_int($interval) || $interval === 'live' ? $interval : null,
                (string) $row->version,
                $window !== null && CarbonImmutable::parse((string) $row->published_at)->addSeconds($window) > $now,
                is_int($sizes['width'] ?? null) ? $sizes['width'] : 3,
                is_string($sizes['height'] ?? null) ? $sizes['height'] : 'small',
            );
        }

        return $out;
    }

    public function newWindowSeconds(): ?int
    {
        try {
            return LimitSetting::positive('drawer.new_window', $this->config->get('dashflow.drawer.new_window.value'));
        } catch (\Throwable) {
            return null;
        }
    }
}
