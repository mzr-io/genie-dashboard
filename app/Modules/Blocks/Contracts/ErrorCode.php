<?php

namespace App\Modules\Blocks\Contracts;

/**
 * Error codes owned by Blocks: `blocks.{snake_case}`. A generated TypeScript enum
 * (`resources/js/types/error-codes.ts`) mirrors every module's codes.
 */
enum ErrorCode: string
{
    /** The Block already has a Draft (Story 3.3): HTTP 409, with the current Draft's ID. */
    case DraftExists = 'blocks.draft_exists';
    /** The caller's `expected_revision` is stale (Story 3.3): HTTP 409, with the current state. */
    case RevisionConflict = 'blocks.revision_conflict';
    /** A Draft payload carried a secret-valued field (Story 3.3): HTTP 422, nothing stored. */
    case SecretValuesRefused = 'blocks.secret_values_refused';
    /** A Draft's `config` has a value outside the Block Type's limits or of the wrong type (Story 3.4): HTTP 422 with field errors. */
    case InvalidConfig = 'blocks.invalid_config';
    /** The version being saved is no longer a Draft, it was published (Story 3.5): HTTP 409. A discarded Draft stays a 404. */
    case DraftPublished = 'blocks.draft_published';
    /** A pasted sample was not stored (Story 3.8): HTTP 422 with `reason` (not JSON, over a limit, a limit or the TTL unset, no acknowledgement). */
    case SampleInvalid = 'blocks.sample_invalid';
    /** A pasted sample could not be sealed or opened because the `data` key is unusable (Story 3.8): HTTP 503, nothing stored. */
    case SampleUnavailable = 'blocks.sample_unavailable';
    /** An undo of a slot that was changed after the override (Story 3.13c): HTTP 409, nothing written. */
    case SlotChanged = 'blocks.slot_changed';
    /** A preview as a user (Story 3.17) was asked for a Fetch as user result that is gone: HTTP 422; the wizard shows the shared sample again. */
    case PreviewAsUserEnded = 'blocks.preview_as_user_ended';
    /** A preview as a user named a Data Source or Endpoint that is not the Draft's (Story 3.17): HTTP 422, nothing run. */
    case PreviewAsUserMismatch = 'blocks.preview_as_user_mismatch';
}
