<?php

namespace App\Platform\Period;

use DateTimeImmutable;

/**
 * The relative period presets (the catalogue). Weeks start on Monday. `last_N_days` is the N local days ending with today. A calendar preset
 * is a whole day, week, month, quarter or year. {@see self::window()} is half-open: `[start, end)` as local dates.
 */
enum PeriodPreset: string
{
    case Today = 'today';
    case Yesterday = 'yesterday';
    case ThisWeek = 'this_week';
    case LastWeek = 'last_week';
    case ThisMonth = 'this_month';
    case LastMonth = 'last_month';
    case ThisQuarter = 'this_quarter';
    case LastQuarter = 'last_quarter';
    case CurrentYear = 'current_year';
    case LastYear = 'last_year';
    case Last7Days = 'last_7_days';
    case Last30Days = 'last_30_days';
    case Last90Days = 'last_90_days';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }

    /**
     * @param  DateTimeImmutable  $today  midnight of the current local day
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} start (inclusive), end (exclusive)
     */
    public function window(DateTimeImmutable $today): array
    {
        $tomorrow = $today->modify('+1 day');
        $month = $today->modify('first day of this month');
        $quarter = $month->modify('-'.(((int) $month->format('n')) - 1) % 3 .' months');
        $week = $today->modify('-'.(((int) $today->format('N')) - 1).' days');

        return match ($this) {
            self::Today => [$today, $tomorrow],
            self::Yesterday => [$today->modify('-1 day'), $today],
            self::ThisWeek => [$week, $week->modify('+7 days')],
            self::LastWeek => [$week->modify('-7 days'), $week],
            self::ThisMonth => [$month, $month->modify('+1 month')],
            self::LastMonth => [$month->modify('-1 month'), $month],
            self::ThisQuarter => [$quarter, $quarter->modify('+3 months')],
            self::LastQuarter => [$quarter->modify('-3 months'), $quarter],
            self::CurrentYear => [$today->setDate((int) $today->format('Y'), 1, 1), $today->setDate((int) $today->format('Y') + 1, 1, 1)],
            self::LastYear => [$today->setDate((int) $today->format('Y') - 1, 1, 1), $today->setDate((int) $today->format('Y'), 1, 1)],
            self::Last7Days => [$tomorrow->modify('-7 days'), $tomorrow],
            self::Last30Days => [$tomorrow->modify('-30 days'), $tomorrow],
            self::Last90Days => [$tomorrow->modify('-90 days'), $tomorrow],
        };
    }

    /** Whether the previous period is the previous calendar unit (true) or the equal-length window just before (false). */
    private function calendarUnit(): ?string
    {
        return match ($this) {
            self::ThisWeek, self::LastWeek => 'week',
            self::ThisMonth, self::LastMonth => 'month',
            self::ThisQuarter, self::LastQuarter => 'quarter',
            self::CurrentYear, self::LastYear => 'year',
            default => null,
        };
    }

    /**
     * The comparison window: the previous calendar unit for a calendar preset, otherwise the equal-length window immediately before.
     *
     * @param  array{0: DateTimeImmutable, 1: DateTimeImmutable}  $window
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public function previous(array $window): array
    {
        [$start, $end] = $window;

        return match ($this->calendarUnit()) {
            'week' => [$start->modify('-7 days'), $start],
            'month' => [$start->modify('-1 month'), $start],
            'quarter' => [$start->modify('-3 months'), $start],
            'year' => [$start->modify('-1 year'), $start],
            default => [$start->sub($start->diff($end)), $start],
        };
    }
}
