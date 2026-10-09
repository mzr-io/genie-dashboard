<?php

namespace App\Modules\Connector\Contracts;

/** The source of randomness of a retry delay (Story 2.17), a port so tests can seed it. */
interface Jitter
{
    /** A whole number from 0 up to and including `$max` (full jitter). */
    public function upTo(int $max): int;
}
