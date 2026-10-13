<?php

namespace App\Modules\BlockTypes\Infrastructure;

use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Contracts\BlockTypeShapers;
use App\Modules\BlockTypes\Contracts\UnknownBlockType;
use BlockTypes\ActivityFeed\V1\Shaper as ActivityFeedV1;
use BlockTypes\Bar\V1\Shaper as BarV1;
use BlockTypes\CalendarAgenda\V1\Shaper as CalendarAgendaV1;
use BlockTypes\ItemList\V1\Shaper as ListV1;
use BlockTypes\KpiCard\V1\Shaper as KpiCardV1;
use BlockTypes\KpiChart\V1\Shaper as KpiChartV1;
use BlockTypes\LineArea\V1\Shaper as LineAreaV1;
use BlockTypes\PieDonut\V1\Shaper as PieDonutV1;
use BlockTypes\ProgressList\V1\Shaper as ProgressListV1;
use BlockTypes\Table\V1\Shaper as TableV1;
use BlockTypes\TextStatus\V1\Shaper as TextStatusV1;

/** The Shapers of the packages under `block-types/`, by pinned `(key, contractVersion)`: never "latest". */
final class PackageShapers implements BlockTypeShapers
{
    /** @var array<string, class-string> */
    private const SHAPERS = [
        'kpi-card@1' => KpiCardV1::class,
        'line-area@1' => LineAreaV1::class,
        'kpi-chart@1' => KpiChartV1::class,
        'bar@1' => BarV1::class,
        'pie-donut@1' => PieDonutV1::class,
        'table@1' => TableV1::class,
        'list@1' => ListV1::class,
        'progress-list@1' => ProgressListV1::class,
        'activity-feed@1' => ActivityFeedV1::class,
        'calendar-agenda@1' => CalendarAgendaV1::class,
        'text-status@1' => TextStatusV1::class,
    ];

    public function shape(BlockType $type, array $slots, array $options = []): array
    {
        $class = self::SHAPERS["{$type->key}@{$type->contractVersion}"] ?? throw UnknownBlockType::pair($type->key, $type->contractVersion);

        return (new $class)->shape($slots, $options);
    }
}
