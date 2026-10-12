<?php

namespace App\Platform\Period;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The only implementation of period precedence (FR-35, AD-27). A pure function of its arguments: no clock, no database, no module.
 *
 * Precedence: the user's Block Period Selector choice (Own selector Blocks), then the dashboard Date Range (Follow dashboard Blocks), then the
 * Block's Default date range. Not date-filtered Blocks return null and ignore every input. Only presets the Block enabled (its enabled list and
 * its Default date range) are accepted; anything else, an absolute date included, is a 422 and never coerced. The one place absolute dates enter is
 * `$dashboardCustom`: the dashboard's own stored Custom range, which the `SetDateRange` command validated and the server (never a request) passes in.
 * The window is computed in the Workspace timezone, never the user's.
 */
final class PeriodResolver
{
    /** The Block default whose dates the Admin chose; never accepted as a selection or a dashboard Date Range. */
    public const CUSTOM = 'custom';

    /**
     * @param  list<string>  $enabled  the presets the Block enabled
     * @param  array{from?: string, to?: string}|null  $customDefault  the Admin's own dates when the Block default is `custom` (inclusive `Y-m-d`; no period while either is missing)
     * @param  array{from?: string, to?: string}|null  $dashboardCustom  the dates of the dashboard's stored Custom range, used when `$dashboardRange` is `custom`
     *
     * @throws InvalidPeriodInput 422 when the chosen preset is not a catalogue preset the Block enabled
     */
    public static function resolve(
        PeriodBehaviour $behaviour,
        ?string $selection,
        ?string $dashboardRange,
        ?string $default,
        array $enabled,
        string $workspaceTimezone,
        DateTimeInterface $now,
        bool $withComparison = false,
        ?array $customDefault = null,
        ?array $dashboardCustom = null,
    ): ?ResolvedPeriod {
        if ($behaviour === PeriodBehaviour::NotDateFiltered) {
            return null;
        }

        $token = match ($behaviour) {
            PeriodBehaviour::OwnSelector => $selection ?? $default,
            PeriodBehaviour::FollowDashboard => $dashboardRange ?? $default,
        };

        if ($token === null) {
            return null;
        }

        if ($behaviour === PeriodBehaviour::FollowDashboard && $dashboardRange === self::CUSTOM && $dashboardCustom !== null) {
            return self::custom($dashboardCustom, $withComparison);
        }

        if ($token === self::CUSTOM && $token === $default) {
            return self::custom($customDefault ?? [], $withComparison);
        }

        if ($token !== $default) {
            $allowed = array_values(array_unique([...$enabled, ...($default === null ? [] : [$default])]));

            if (! in_array($token, $allowed, true)) {
                throw new InvalidPeriodInput;
            }
        }

        $preset = PeriodPreset::tryFrom($token);

        if ($preset === null) {
            throw new InvalidPeriodInput;
        }

        $today = self::localToday($now, $workspaceTimezone);
        $window = $preset->window($today);
        $comparison = $withComparison ? self::result($preset->value, $preset->previous($window)) : null;

        return self::result($preset->value, $window, $comparison);
    }

    /** @param  array{from?: string, to?: string}  $dates */
    private static function custom(array $dates, bool $withComparison): ?ResolvedPeriod
    {
        $from = $dates['from'] ?? '';
        $to = $dates['to'] ?? '';
        $utc = new DateTimeZone('UTC');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $utc);
        $last = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $utc);

        if ($start === false || $last === false || $start > $last) {
            return null;
        }

        $end = $last->modify('+1 day');
        $comparison = $withComparison ? self::result(self::CUSTOM, [$start->sub($start->diff($end)), $start]) : null;

        return self::result(self::CUSTOM, [$start, $end], $comparison);
    }

    /** @param  array{0: DateTimeImmutable, 1: DateTimeImmutable}  $window */
    private static function result(string $token, array $window, ?ResolvedPeriod $comparison = null): ResolvedPeriod
    {
        return new ResolvedPeriod($token, $window[0]->format('Y-m-d'), $window[1]->format('Y-m-d'), $comparison);
    }

    private static function localToday(DateTimeInterface $now, string $timezone): DateTimeImmutable
    {
        try {
            $zone = new DateTimeZone($timezone);
        } catch (\Exception) {
            $zone = new DateTimeZone('UTC');
        }

        $local = DateTimeImmutable::createFromInterface($now)->setTimezone($zone);

        // Midnight of the local day, built from its date parts so a DST change never shifts the date.
        return new DateTimeImmutable($local->format('Y-m-d').' 00:00:00', new DateTimeZone('UTC'));
    }
}
