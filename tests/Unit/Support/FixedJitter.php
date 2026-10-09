<?php

namespace Tests\Unit\Support;

use App\Modules\Connector\Contracts\Jitter;

/** A {@see Jitter} that returns a fixed share of the ceiling (0 = no wait, 1 = the whole ceiling) and remembers every ceiling it was asked for. */
final class FixedJitter implements Jitter
{
    /** @var list<int> */
    public array $ceilings = [];

    public function __construct(private readonly float $share = 0.0) {}

    public function upTo(int $max): int
    {
        $this->ceilings[] = $max;

        return (int) floor($max * $this->share);
    }
}
