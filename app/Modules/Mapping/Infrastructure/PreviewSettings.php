<?php

namespace App\Modules\Mapping\Infrastructure;

use App\Modules\Mapping\Contracts\PlanGuards;
use App\Modules\Mapping\Contracts\SettingNotConfigured;
use Illuminate\Contracts\Config\Repository;

/**
 * The live-preview settings (Story 3.10), every one `pending_input` with no default and outside the AR-57 tunable list: the per-membership limit
 * and its window, and the three guards of a run. A setting that is unset, or is not a positive whole number, is never guessed: {@see self::require()}
 * names the environment variable. `p95_target_ms` (the C6 spike) is recorded only and nothing reads it to decide anything.
 */
final class PreviewSettings
{
    private const ENV = [
        'membership_limit' => 'DASHFLOW_PREVIEW_MEMBERSHIP_LIMIT',
        'window_seconds' => 'DASHFLOW_PREVIEW_WINDOW_SECONDS',
        'max_extracted_values' => 'DASHFLOW_PREVIEW_MAX_EXTRACTED_VALUES',
        'max_evaluations' => 'DASHFLOW_PREVIEW_MAX_EVALUATIONS',
        'max_wall_ms' => 'DASHFLOW_PREVIEW_MAX_WALL_MS',
    ];

    public function __construct(private readonly Repository $config) {}

    /** @throws SettingNotConfigured the first of the five that is not set to a positive whole number */
    public function require(): void
    {
        foreach (array_keys(self::ENV) as $name) {
            $this->value($name);
        }
    }

    public function membershipLimit(): int
    {
        return $this->value('membership_limit');
    }

    public function windowSeconds(): int
    {
        return $this->value('window_seconds');
    }

    public function guards(): PlanGuards
    {
        $this->require();

        return new PlanGuards($this->value('max_extracted_values'), $this->value('max_evaluations'), $this->value('max_wall_ms'));
    }

    private function value(string $name): int
    {
        $value = $this->config->get("dashflow.preview.{$name}.value");

        if ((is_int($value) || (is_string($value) && preg_match('/\A[1-9][0-9]{0,8}\z/D', trim($value)) === 1)) && (int) $value > 0) {
            return (int) $value;
        }

        throw new SettingNotConfigured(self::ENV[$name]);
    }
}
