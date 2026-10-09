<?php

namespace App\Modules\Ingestion\Infrastructure;

use App\Modules\Ingestion\Application\HealthRules;
use Illuminate\Contracts\Config\Repository;

/**
 * The `pending_input` settings of the health status (Story 2.18, AR-57): `health.window`, `threshold_healthy`, `threshold_degraded`,
 * `threshold_unreachable` and `probe_interval`. No number is invented: a rule whose settings are unset or malformed is off.
 */
final class HealthSettings
{
    public function __construct(private readonly Repository $config) {}

    /** `health.probe_interval` in whole seconds, or null (unset or malformed: a probe on save only). */
    public function probeInterval(): ?int
    {
        return SyncSettings::seconds($this->config->get('dashflow.tunables.health.probe_interval.value'));
    }

    public function rules(): HealthRules
    {
        $healthy = self::percent($this->config->get('dashflow.tunables.health.threshold_healthy.value'));
        $degraded = self::percent($this->config->get('dashflow.tunables.health.threshold_degraded.value'));
        $window = SyncSettings::seconds($this->config->get('dashflow.tunables.health.window.value'));
        $both = $healthy !== null && $degraded !== null && $healthy > $degraded;

        return new HealthRules(
            SyncSettings::seconds($this->config->get('dashflow.tunables.health.threshold_unreachable.value')),
            $window !== null && $both ? $window : null,
            $window !== null && $both ? $healthy : null,
            $window !== null && $both ? $degraded : null,
        );
    }

    /** A whole percent from 1 to 100 (digits only), or null. */
    public static function percent(mixed $setting): ?int
    {
        if (! is_string($setting) && ! is_int($setting)) {
            return null;
        }

        $text = trim((string) $setting);

        return preg_match('/\A(?:[1-9][0-9]?|100)\z/D', $text) === 1 ? (int) $text : null;
    }
}
