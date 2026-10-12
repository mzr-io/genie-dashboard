<?php

namespace App\Modules\Mapping\Contracts;

/**
 * Error codes owned by Mapping: `mapping.{snake_case}`. A generated TypeScript enum (`resources/js/types/error-codes.ts`) mirrors every module's codes.
 */
enum ErrorCode: string
{
    /** The Draft has no stored sample to run the plan over (Story 3.10): HTTP 422, nothing run. */
    case NoSample = 'mapping.no_sample';
    /** The Draft's config has no Query Plan (Story 3.10): HTTP 422, nothing run. */
    case NoPlan = 'mapping.no_plan';
    /** A guard (extracted values, evaluations or wall time) was passed during a run (Story 3.10): HTTP 422, no partial payload. */
    case GuardExceeded = 'mapping.guard_exceeded';
    /** A `pending_input` preview setting is unset (Story 3.10): HTTP 422 naming the environment variable, nothing run. */
    case SettingNotConfigured = 'mapping.setting_not_configured';
    /** The Draft's Block Type package is not registered or has no Shaper (Story 3.10): HTTP 422, nothing run. */
    case BlockTypeUnavailable = 'mapping.block_type_unavailable';
}
