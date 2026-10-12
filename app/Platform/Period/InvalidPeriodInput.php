<?php

namespace App\Platform\Period;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** A period input the Block does not offer, or that is not a catalogue preset (an absolute date included): 422 with the error envelope. */
final class InvalidPeriodInput extends UnprocessableEntityHttpException
{
    public function __construct()
    {
        parent::__construct('Choose one of the periods this block offers.');
    }
}
