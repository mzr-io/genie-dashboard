<?php

namespace App\Modules\Connector\Contracts;

/**
 * Error codes owned by Connector: `connector.{snake_case}`. A generated TypeScript enum
 * (`resources/js/types/error-codes.ts`) mirrors every module's codes.
 */
enum ErrorCode: string
{
    case RevisionConflict = 'connector.revision_conflict';
    case SsrfBlocked = 'connector.ssrf_blocked';
    case SecretsNotConfigured = 'connector.secrets_not_configured';
    case SecretValuesRefused = 'connector.secret_values_refused';
    /** A response grew past the size limit; reading stopped and nothing was stored (Story 2.6). */
    case LimitExceeded = 'connector.limit_exceeded';
    /** A response was not JSON: wrong or missing Content-Type, or a body that does not parse (Story 2.6). */
    case NotJson = 'connector.not_json';
}
