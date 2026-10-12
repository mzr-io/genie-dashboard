<?php

namespace App\Modules\Blocks\Contracts;

use RuntimeException;

/**
 * Publish was refused because there is no passed ValidationReport for the Draft's current revision (Story 3.19): HTTP 409
 * `blocks.publish_not_ready` with the `reason`. Nothing was changed.
 */
final class PublishRefused extends RuntimeException
{
    /** The Draft was never validated. */
    public const MISSING = 'report_missing';

    /** The newest report is for an earlier revision, or the Endpoint it was made against has changed. */
    public const STALE = 'report_stale';

    /** The newest report failed. */
    public const FAILED = 'report_failed';

    /** The live API could not be reached when the Draft was last validated. */
    public const UNREACHABLE = 'report_unreachable';

    /** The Block Type package this Draft is pinned to is not registered any more. */
    public const BLOCK_TYPE_UNAVAILABLE = 'block_type_unavailable';

    /** A field the plan reads has no known role or type (the sample is gone): refreshing the sample resolves it. */
    public const FIELDS_UNRESOLVED = 'fields_unresolved';

    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct(ErrorCode::PublishNotReady->value, 0, $previous);
    }
}
