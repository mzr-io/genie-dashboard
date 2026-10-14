<?php

namespace App\Modules\Blocks\Application;

use App\Platform\Json\LosslessJson;

/**
 * What a Draft changed against the published version before it (Story 6.3), in exactly four sections: Mapping, Presentation, Chrome and Endpoint, each as
 * before and after pairs. Pure: both sides are Draft-shaped configs (a published version's through {@see FrozenConfig::draftConfig()}). The wizard's own
 * state (`draft_ui_state`) is not part of a config and so can never be part of a diff; the pinned sample revision and the schema version are left out.
 */
final class VersionDiff
{
    public const SECTIONS = ['mapping', 'presentation', 'chrome', 'endpoint'];

    private const SKIPPED = ['schema_version', 'mapped_sample_revision', 'slots'];

    private const VALUE_MAX = 200;

    /**
     * @param  array<string, mixed>  $before  the published version, as a Draft config
     * @param  array<string, mixed>  $after  the Draft's config
     * @return array<string, list<array{path: string, before: string|null, after: string|null}>> by section
     */
    public static function between(array $before, array $after, string $nameBefore = '', string $nameAfter = ''): array
    {
        $out = ['mapping' => [], 'presentation' => [], 'chrome' => [], 'endpoint' => []];
        $left = self::flat($before + ['name' => $nameBefore]);
        $right = self::flat($after + ['name' => $nameAfter]);
        $paths = array_values(array_unique([...array_keys($left), ...array_keys($right)]));
        sort($paths, SORT_STRING);

        foreach ($paths as $path) {
            $was = $left[$path] ?? null;
            $now = $right[$path] ?? null;

            if ($was === $now) {
                continue;
            }

            $out[self::section($path)][] = ['path' => $path, 'before' => $was, 'after' => $now];
        }

        return $out;
    }

    /**
     * The summary stored on a version: each section's name with the count of its changed items, and only sections that changed.
     *
     * @param  array<string, list<array<string, string|null>>>  $diff
     * @return array<string, int>
     */
    public static function summary(array $diff): array
    {
        $out = [];

        foreach (self::SECTIONS as $section) {
            $count = count($diff[$section] ?? []);

            if ($count > 0) {
                $out[$section] = $count;
            }
        }

        return $out;
    }

    private static function section(string $path): string
    {
        $head = explode('.', $path, 2)[0];

        return match (true) {
            $head === 'presentation' => 'presentation',
            $head === 'source' => 'endpoint',
            in_array($head, ['name', 'description', 'icon', 'display'], true) => 'chrome',
            default => 'mapping',
        };
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, string>
     */
    private static function flat(array $value, string $prefix = ''): array
    {
        $out = [];

        foreach ($value as $key => $entry) {
            $key = (string) $key;

            if ($prefix === '' && in_array($key, self::SKIPPED, true)) {
                continue;
            }

            $path = $prefix === '' ? $key : "{$prefix}.{$key}";

            if (is_array($entry) && $entry !== [] && ! array_is_list($entry)) {
                $out += self::flat($entry, $path);

                continue;
            }

            $out[$path] = self::text($entry);
        }

        return $out;
    }

    private static function text(mixed $value): string
    {
        $text = match (true) {
            $value === null => 'none',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => LosslessJson::encode($value),
        };

        return mb_strlen($text) > self::VALUE_MAX ? mb_substr($text, 0, self::VALUE_MAX - 1).'…' : $text;
    }
}
