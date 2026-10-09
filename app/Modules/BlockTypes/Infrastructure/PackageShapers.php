<?php

namespace App\Modules\BlockTypes\Infrastructure;

use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Contracts\BlockTypeShapers;
use App\Modules\BlockTypes\Contracts\UnknownBlockType;
use BlockTypes\KpiCard\V1\Shaper as KpiCardV1;

/** The Shapers of the packages under `block-types/`, by pinned `(key, contractVersion)`: never "latest". */
final class PackageShapers implements BlockTypeShapers
{
    /** @var array<string, class-string> */
    private const SHAPERS = [
        'kpi-card@1' => KpiCardV1::class,
    ];

    public function shape(BlockType $type, array $slots, array $options = []): array
    {
        $class = self::SHAPERS["{$type->key}@{$type->contractVersion}"] ?? throw UnknownBlockType::pair($type->key, $type->contractVersion);

        return (new $class)->shape($slots, $options);
    }
}
