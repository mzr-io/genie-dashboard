<?php

namespace App\Modules\Blocks\Contracts;

use RuntimeException;

/** A pasted sample was not stored (`blocks.sample_invalid`, HTTP 422): `reason` says why, and nothing was written. */
final class SampleRejected extends RuntimeException
{
    public const ACKNOWLEDGEMENT_REQUIRED = 'acknowledgement_required';

    public const TTL_NOT_CONFIGURED = 'ttl_not_configured';

    public function __construct(public readonly string $reason, public readonly ?SampleCheck $check = null)
    {
        parent::__construct(ErrorCode::SampleInvalid->value);
    }
}
