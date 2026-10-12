<?php

namespace App\Modules\Blocks\Contracts;

use RuntimeException;

/** A preview that has nothing to run: the Draft has no sample, or no Query Plan. `reason` is the Mapping error code. */
class PreviewRefused extends RuntimeException
{
    public const NO_SAMPLE = 'mapping.no_sample';

    public const NO_PLAN = 'mapping.no_plan';

    /** A preview as a user (Story 3.17) whose Fetch as user result is gone: it expired, or is not this Admin's, or the Endpoint moved on. */
    public const AS_USER_ENDED = 'blocks.preview_as_user_ended';

    /** A preview as a user for another Data Source or Endpoint than the Draft's own (Story 3.17): its plan would run over other data. */
    public const AS_USER_MISMATCH = 'blocks.preview_as_user_mismatch';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('The draft cannot be previewed.');
    }
}
