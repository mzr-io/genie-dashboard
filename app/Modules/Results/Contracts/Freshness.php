<?php

namespace App\Modules\Results\Contracts;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * The only place the staleness rule exists (Story 4.3; AR-11, AD-15, C12): a result is stale when its source has not been successfully checked for
 * more than {@see self::FACTOR} refresh intervals. "Checked" is `last_success_at`, which a 304 or an equal body moves too, so an unchanged source
 * stays fresh while `data_as_of` shows how old the data really is. A pure function of its arguments: `now` is the database clock, read by the caller.
 */
final class Freshness
{
    /** A result is stale after this many refresh intervals without a successful check. */
    public const FACTOR = 2;

    /** The moment the result becomes stale: `last_success_at + FACTOR x interval`. */
    public static function staleAt(DateTimeInterface $lastSuccessAt, int $refreshIntervalSeconds): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($lastSuccessAt)->modify('+'.(self::FACTOR * max(1, $refreshIntervalSeconds)).' seconds');
    }

    /** Stale strictly after `stale_at`: exactly 2 x interval old is still fresh. */
    public static function isStale(DateTimeInterface $lastSuccessAt, int $refreshIntervalSeconds, DateTimeInterface $now): bool
    {
        return DateTimeImmutable::createFromInterface($now) > self::staleAt($lastSuccessAt, $refreshIntervalSeconds);
    }

    /**
     * The `last_success_at` a result is judged by when several payloads feed it (a comparison Block, Epic 5): the oldest of them. Null when any is
     * missing, because a side that has never succeeded makes the whole result unjudgeable.
     *
     * @param  list<DateTimeInterface|null>  $lastSuccessAts
     */
    public static function olderOf(array $lastSuccessAts): ?DateTimeImmutable
    {
        $oldest = null;

        foreach ($lastSuccessAts as $at) {
            if ($at === null) {
                return null;
            }

            $at = DateTimeImmutable::createFromInterface($at);
            $oldest = $oldest === null || $at < $oldest ? $at : $oldest;
        }

        return $oldest;
    }
}
