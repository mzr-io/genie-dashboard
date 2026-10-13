<?php

namespace App\Platform\Period;

use DateTimeImmutable;

/**
 * The prior window of a comparison (Story 5.6; FR-27) from the active period, as the same half-open `[start, end)` Workspace-local dates a
 * {@see ResolvedPeriod} holds: the previous period of equal length (the window right before it), the same period last year, or a custom offset (a
 * number and a unit). A pure kernel function, like {@see PeriodResolver}. A month step keeps the day of the month, or the last day of a shorter month.
 */
final class PriorWindow
{
    public const PREVIOUS_PERIOD = 'previous_period';

    public const SAME_PERIOD_LAST_YEAR = 'same_period_last_year';

    public const CUSTOM_OFFSET = 'custom_offset';

    public const KINDS = [self::PREVIOUS_PERIOD, self::SAME_PERIOD_LAST_YEAR, self::CUSTOM_OFFSET];

    public const UNITS = ['day', 'week', 'month', 'quarter', 'year'];

    public const MAX_AMOUNT = 366;

    private const DATE = '/\A\d{4}-\d{2}-\d{2}\z/D';

    /**
     * @return array{start: string, end: string}|null null when the dates, the kind or the offset are not valid
     */
    public static function of(string $start, string $end, string $kind, ?int $amount = null, ?string $unit = null): ?array
    {
        if (preg_match(self::DATE, $start) !== 1 || preg_match(self::DATE, $end) !== 1) {
            return null;
        }

        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', $end);

        if ($from === false || $to === false || $to <= $from || $from->format('Y-m-d') !== $start || $to->format('Y-m-d') !== $end) {
            return null;
        }

        if ($kind === self::PREVIOUS_PERIOD) {
            $days = (int) $from->diff($to)->days;

            return ['start' => $from->modify("-{$days} days")->format('Y-m-d'), 'end' => $from->format('Y-m-d')];
        }

        if ($kind === self::SAME_PERIOD_LAST_YEAR) {
            return ['start' => self::months($from, -12), 'end' => self::months($to, -12)];
        }

        if ($kind !== self::CUSTOM_OFFSET || $amount === null || $amount < 1 || $amount > self::MAX_AMOUNT || ! in_array($unit, self::UNITS, true)) {
            return null;
        }

        return match ($unit) {
            'day' => ['start' => $from->modify("-{$amount} days")->format('Y-m-d'), 'end' => $to->modify("-{$amount} days")->format('Y-m-d')],
            'week' => ['start' => $from->modify('-'.($amount * 7).' days')->format('Y-m-d'), 'end' => $to->modify('-'.($amount * 7).' days')->format('Y-m-d')],
            'month' => ['start' => self::months($from, -$amount), 'end' => self::months($to, -$amount)],
            'quarter' => ['start' => self::months($from, -3 * $amount), 'end' => self::months($to, -3 * $amount)],
            default => ['start' => self::months($from, -12 * $amount), 'end' => self::months($to, -12 * $amount)],
        };
    }

    /** The date `$by` months away, on the same day of the month or the last day of a shorter one (no overflow into the next month). */
    private static function months(DateTimeImmutable $date, int $by): string
    {
        $first = $date->modify('first day of this month')->modify(($by >= 0 ? '+' : '').$by.' months');
        $day = min((int) $date->format('j'), (int) $first->format('t'));

        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day)->format('Y-m-d');
    }
}
