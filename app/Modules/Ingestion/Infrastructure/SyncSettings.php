<?php

namespace App\Modules\Ingestion\Infrastructure;

use App\Modules\Connector\Contracts\GovernorLimits;
use Illuminate\Contracts\Config\Repository;

/**
 * The two pending-input tunables the scheduled fetch reads (AR-57): `sync.refresh_intervals` and `sync.dispatch_tick`. No number is invented:
 * an unset or malformed interval list means no target is ever scheduled, and an unset or malformed tick falls back to the proposed 5 seconds
 * the configuration already shows.
 */
final class SyncSettings
{
    /** The proposed tick (seconds), used when the setting is unset or malformed. */
    public const DEFAULT_TICK = 5;

    /** The sub-minute steps the Laravel scheduler offers, in seconds. */
    public const STEPS = [1, 2, 5, 10, 15, 20, 30];

    public function __construct(private readonly Repository $config) {}

    /**
     * The smallest value of `refresh_intervals` (whole seconds, comma-separated), or null when it is unset or any entry is not a positive whole number.
     */
    public function refreshInterval(): ?int
    {
        return self::smallest($this->config->get('dashflow.tunables.sync.refresh_intervals.value'));
    }

    /**
     * `sync.superseded_payload_grace` in whole seconds: how long a superseded payload is kept. Null when unset or malformed: the `latest`
     * rule of the sweep is then inert and superseded payloads stay (Story 2.16).
     */
    public function supersededGraceSeconds(): ?int
    {
        return self::seconds($this->config->get('dashflow.tunables.sync.superseded_payload_grace.value'));
    }

    /**
     * `sync.cold_purge_after` in whole seconds: how long a retired target is kept before its payloads and the target are deleted. Null when
     * unset or malformed: nothing is purged (Story 2.16).
     */
    public function coldPurgeAfterSeconds(): ?int
    {
        return self::seconds($this->config->get('dashflow.tunables.sync.cold_purge_after.value'));
    }

    /**
     * The retry policy (Story 2.17), or null (retries are off) unless `retry.base`, `retry.cap` and `retry.max_attempts` are all positive
     * whole numbers.
     */
    public function retry(): ?RetryPolicy
    {
        $base = self::seconds($this->config->get('dashflow.tunables.retry.base.value'));
        $cap = self::seconds($this->config->get('dashflow.tunables.retry.cap.value'));
        $attempts = self::seconds($this->config->get('dashflow.tunables.retry.max_attempts.value'));

        return $base === null || $cap === null || $attempts === null ? null : new RetryPolicy($base, $cap, $attempts);
    }

    /** `retry.cap` alone, which a penalty needs even when the retry as a whole is off; null when unset or malformed. */
    public function retryCap(): ?int
    {
        return self::seconds($this->config->get('dashflow.tunables.retry.cap.value'));
    }

    /**
     * What the governor applies (Story 2.17): the breaker (`circuit_breaker.failure_count` and `cool_down`, both or neither), the bucket
     * (`budgets.max_fetch_rate_per_data_source`, whole calls per minute), the concurrency cap (`fetch.data_source_concurrency`) with its
     * lease (`guards.platform_timeout_ceiling`), and the penalty cap (`retry.cap`). Every unset or malformed number leaves that guard off.
     */
    public function governorLimits(): GovernorLimits
    {
        $failures = self::seconds($this->config->get('dashflow.tunables.circuit_breaker.failure_count.value'));
        $coolDown = self::seconds($this->config->get('dashflow.tunables.circuit_breaker.cool_down.value'));
        $breaker = $failures !== null && $coolDown !== null;

        return new GovernorLimits(
            $breaker ? $failures : null,
            $breaker ? $coolDown : null,
            self::seconds($this->config->get('dashflow.tunables.budgets.max_fetch_rate_per_data_source.value')),
            self::seconds($this->config->get('dashflow.fetch.data_source_concurrency.value')),
            self::seconds($this->config->get('dashflow.tunables.guards.platform_timeout_ceiling.value')),
            $this->retryCap(),
        );
    }

    /** `fetch.workspace_fair_share`: the most targets one dispatch tick takes per Workspace; null (no cap) when unset or malformed. */
    public function workspaceFairShare(): ?int
    {
        return self::seconds($this->config->get('dashflow.fetch.workspace_fair_share.value'));
    }

    /**
     * `sync.hot_window` in whole seconds (Story 2.19): how long a subscription stays hot after its last access. Null when unset or malformed:
     * the demand rule is then off, subscriptions never turn hot and the dispatcher keeps scheduling every target (Story 2.14).
     */
    public function hotWindowSeconds(): ?int
    {
        return self::seconds($this->config->get('dashflow.tunables.sync.hot_window.value'));
    }

    /** `budgets.max_hot_keys_per_workspace`: the most hot targets of one Workspace kept at their own interval; null (no cap) when unset or malformed. */
    public function maxHotKeysPerWorkspace(): ?int
    {
        return self::seconds($this->config->get('dashflow.tunables.budgets.max_hot_keys_per_workspace.value'));
    }

    /** `budgets.max_new_cold_keys_per_membership_per_hour`: the most new per-user targets one member may cause in an hour; null (no cap) when unset or malformed. */
    public function maxNewColdKeysPerMembershipPerHour(): ?int
    {
        return self::seconds($this->config->get('dashflow.tunables.budgets.max_new_cold_keys_per_membership_per_hour.value'));
    }

    /**
     * Every entry of `refresh_intervals`, ascending and without repeats; null when it is unset or any entry is not a positive whole number.
     *
     * @return list<int>|null
     */
    public function refreshIntervals(): ?array
    {
        if (self::smallest($this->config->get('dashflow.tunables.sync.refresh_intervals.value')) === null) {
            return null;
        }

        $entries = array_map(fn (string $entry): int => (int) trim($entry), explode(',', (string) $this->config->get('dashflow.tunables.sync.refresh_intervals.value')));
        $entries = array_values(array_unique($entries));
        sort($entries);

        return $entries;
    }

    /** The next larger entry of `refresh_intervals` than `$interval` (the budget widening, Story 2.19), or null when there is none or the list is unset. */
    public function widened(int $interval): ?int
    {
        foreach ($this->refreshIntervals() ?? [] as $entry) {
            if ($entry > $interval) {
                return $entry;
            }
        }

        return null;
    }

    /** A positive whole number of seconds (digits only), or null. */
    public static function seconds(mixed $setting): ?int
    {
        if (! is_string($setting) && ! is_int($setting)) {
            return null;
        }

        return preg_match('/\A[1-9][0-9]{0,8}\z/D', trim((string) $setting)) === 1 ? (int) trim((string) $setting) : null;
    }

    public function dispatchTick(): int
    {
        return self::tick($this->config->get('dashflow.tunables.sync.dispatch_tick.value'));
    }

    public static function smallest(mixed $setting): ?int
    {
        if (! is_string($setting) && ! is_int($setting)) {
            return null;
        }

        $entries = array_map('trim', explode(',', (string) $setting));
        $smallest = null;

        foreach ($entries as $entry) {
            if (preg_match('/\A[1-9][0-9]{0,8}\z/D', $entry) !== 1) {
                return null;
            }

            $smallest = $smallest === null ? (int) $entry : min($smallest, (int) $entry);
        }

        return $smallest;
    }

    /** The nearest sub-minute step to the configured tick; 60 seconds or more is one minute (returned as 60). */
    public static function tick(mixed $setting): int
    {
        $seconds = is_int($setting) || (is_string($setting) && preg_match('/\A[1-9][0-9]{0,5}\z/D', trim($setting)) === 1)
            ? (int) $setting
            : self::DEFAULT_TICK;

        if ($seconds >= 45) {
            return 60;
        }

        $nearest = self::STEPS[0];

        foreach (self::STEPS as $step) {
            if (abs($step - $seconds) < abs($nearest - $seconds)) {
                $nearest = $step;
            }
        }

        return $nearest;
    }
}
