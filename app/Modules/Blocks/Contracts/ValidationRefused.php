<?php

namespace App\Modules\Blocks\Contracts;

use RuntimeException;

/** A validation (Story 3.18) that was not started (`blocks.validation_refused`, HTTP 422): `reason` says why, and nothing was queued or counted. */
final class ValidationRefused extends RuntimeException
{
    /** The Draft names no Data Source and Endpoint, or they are gone: a pasted sample is never proof, so there is nothing to validate against. */
    public const NO_REAL_SOURCE = 'no_real_source';

    /** A setting the validation needs is unset: `setting` names its environment variable. */
    public const SETTING_NOT_CONFIGURED = 'setting_not_configured';

    public function __construct(public readonly string $reason, public readonly ?string $setting = null)
    {
        parent::__construct(ErrorCode::ValidationRefused->value);
    }
}
