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
}
