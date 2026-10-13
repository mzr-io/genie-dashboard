<?php

namespace App\Modules\Blocks\Application;

use App\Platform\Period\PeriodBehaviour;
use App\Platform\Period\PeriodResolver;
use App\Platform\Period\PriorWindow;
use App\Platform\Period\ResolvedPeriod;
use DateTimeInterface;

/**
 * A Draft's period for the wizard (Story 4.1): the Block's own choices (`display.period` and the Default date range, `source.default_date_range`)
 * given to the platform's {@see PeriodResolver} with no selection and no dashboard Date Range. This is the only place the wizard asks for a
 * period; it holds no precedence of its own. The dates a fetch or a validation sends to the source are derived here, never taken from the client.
 */
final class DraftPeriod
{
    /**
     * The Block default's period, or null while no default range is chosen (or a custom one is incomplete).
     *
     * @param  array<string, mixed>  $config  the Draft's stored `config`
     */
    public static function resolve(array $config, string $workspaceTimezone, DateTimeInterface $now): ?ResolvedPeriod
    {
        $source = is_array($config['source'] ?? null) ? $config['source'] : [];
        $display = is_array($config['display'] ?? null) ? $config['display'] : [];

        return self::of(
            is_array($source['default_date_range'] ?? null) ? $source['default_date_range'] : null,
            is_string($display['period'] ?? null) ? $display['period'] : null,
            $workspaceTimezone,
            $now,
        );
    }

    /**
     * The request values of a fetch or a validation: `range` (the Default date range, inclusive `from`/`to`: what the date-range parameters take) and
     * `period` (the same dates as `start`/`end`, only when the Block has its own selector: what the period parameters take).
     *
     * @param  array<string, mixed>  $config  the Draft's stored `config`
     * @param  array<string, mixed>|null  $defaultRange  the range choice to use instead of the stored one (a fetch sends the form's)
     * @return array{0: array{from: string, to: string}|null, 1: array{start: string, end: string}|null}
     */
    public static function dates(array $config, ?array $defaultRange, string $workspaceTimezone, DateTimeInterface $now): array
    {
        $display = is_array($config['display'] ?? null) ? $config['display'] : [];
        $stored = is_array($config['source']['default_date_range'] ?? null) ? $config['source']['default_date_range'] : null;
        $resolved = self::of($defaultRange ?? $stored, 'follow_dashboard', $workspaceTimezone, $now);

        if ($resolved === null) {
            return [null, null];
        }

        $own = ($display['period'] ?? null) === PeriodBehaviour::OwnSelector->value;

        return [
            ['from' => $resolved->start, 'to' => $resolved->lastDay()],
            $own ? ['start' => $resolved->start, 'end' => $resolved->lastDay()] : null,
        ];
    }

    /**
     * The dates of the comparison request of a prior-period comparison (Story 5.6): the prior window of the same Default date range, in the shape of
     * {@see self::dates()}. Null when the Draft's comparison is not a prior-period one, or no range is chosen.
     *
     * @param  array<string, mixed>  $config  the Draft's stored `config`
     * @return array{0: array{from: string, to: string}|null, 1: array{start: string, end: string}|null}|null
     */
    public static function priorDates(array $config, string $workspaceTimezone, DateTimeInterface $now): ?array
    {
        $rules = $config['presentation']['slots']['comparison'] ?? null;

        if (! is_array($rules) || ($rules['source'] ?? null) !== 'prior_period' || ! is_array($rules['window'] ?? null)) {
            return null;
        }

        $display = is_array($config['display'] ?? null) ? $config['display'] : [];
        $stored = is_array($config['source']['default_date_range'] ?? null) ? $config['source']['default_date_range'] : null;
        $resolved = self::of($stored, 'follow_dashboard', $workspaceTimezone, $now);

        if ($resolved === null) {
            return null;
        }

        $window = $rules['window'];
        $prior = PriorWindow::of(
            $resolved->start,
            $resolved->end,
            is_string($window['kind'] ?? null) ? $window['kind'] : '',
            is_int($window['amount'] ?? null) ? $window['amount'] : null,
            is_string($window['unit'] ?? null) ? $window['unit'] : null,
        );

        if ($prior === null) {
            return null;
        }

        $last = (new \DateTimeImmutable($prior['end']))->modify('-1 day')->format('Y-m-d');
        $own = ($display['period'] ?? null) === PeriodBehaviour::OwnSelector->value;

        return [['from' => $prior['start'], 'to' => $last], $own ? ['start' => $prior['start'], 'end' => $last] : null];
    }

    /** @param  array<string, mixed>|null  $range  a `{preset, from?, to?}` choice */
    private static function of(?array $range, ?string $behaviour, string $workspaceTimezone, DateTimeInterface $now): ?ResolvedPeriod
    {
        $preset = is_string($range['preset'] ?? null) ? $range['preset'] : null;
        $behaviour = PeriodBehaviour::tryFrom((string) $behaviour) ?? PeriodBehaviour::FollowDashboard;

        if ($preset === null) {
            return null;
        }

        return PeriodResolver::resolve(
            $behaviour,
            null,
            null,
            $preset,
            [],
            $workspaceTimezone,
            $now,
            customDefault: ['from' => is_string($range['from'] ?? null) ? $range['from'] : '', 'to' => is_string($range['to'] ?? null) ? $range['to'] : ''],
        );
    }
}
