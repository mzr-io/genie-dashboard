<?php

namespace App\Support\Observability;

use InvalidArgumentException;

/** Metric names are `dashflow.<module>.<measure>`; this is the only way to build one. */
final class MetricName
{
    public const PATTERN = '/\Adashflow\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\z/D';

    public static function assert(string $name): string
    {
        if (preg_match(self::PATTERN, $name) !== 1) {
            throw new InvalidArgumentException("Metric name [{$name}] must match dashflow.<module>.<measure>.");
        }

        return $name;
    }

    public static function make(string $module, string $measure): string
    {
        return self::assert("dashflow.{$module}.{$measure}");
    }

    /**
     * @param  array<string, mixed>  $labels
     * @return array<string, bool|int|float|string>
     */
    public static function labels(array $labels): array
    {
        return Scrubber::labels($labels);
    }
}
