<?php

namespace App\Modules\Connector\Infrastructure;

use App\Modules\Connector\Contracts\Jitter;

final class RandomJitter implements Jitter
{
    public function upTo(int $max): int
    {
        return $max <= 0 ? 0 : random_int(0, $max);
    }
}
