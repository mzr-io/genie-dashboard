<?php

namespace App\Modules\Blocks\Contracts;

use RuntimeException;

/** A pasted or fetched sample was not stored (`blocks.sample_invalid`, HTTP 422): `reason` says why, and nothing was written. */
final class SampleRejected extends RuntimeException
{
    public const ACKNOWLEDGEMENT_REQUIRED = 'acknowledgement_required';

    public const TTL_NOT_CONFIGURED = 'ttl_not_configured';

    /** A setting a fetch needs is unset (Story 3.9): `setting` names its environment variable. */
    public const SETTING_NOT_CONFIGURED = 'setting_not_configured';

    /** The Endpoint or its Data Source no longer exists in this Workspace (Story 3.9). */
    public const ENDPOINT_NOT_FOUND = 'endpoint_not_found';

    public function __construct(public readonly string $reason, public readonly ?SampleCheck $check = null, public readonly ?string $setting = null)
    {
        parent::__construct(ErrorCode::SampleInvalid->value);
    }
}
