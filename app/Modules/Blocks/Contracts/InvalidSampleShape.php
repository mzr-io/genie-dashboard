<?php

namespace App\Modules\Blocks\Contracts;

/** The Draft's stored sample no longer passes the limits: no sample to map from, but not an ended one (the read reports `invalid`). */
final class InvalidSampleShape extends PreviewRefused
{
    public function __construct()
    {
        parent::__construct(self::NO_SAMPLE);
    }
}
