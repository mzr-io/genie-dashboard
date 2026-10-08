<?php

namespace App\Platform\Contracts;

/**
 * Error codes owned by the kernel. Every code is `{module}.{snake_case}` and starts with the owning
 * module's name; `php artisan dashflow:error-codes` generates the TypeScript enum from every module's
 * `Contracts/ErrorCode.php` (this one included).
 */
enum ErrorCode: string
{
    case Unauthenticated = 'platform.unauthenticated';
    case Forbidden = 'platform.forbidden';
    case NotFound = 'platform.not_found';
    case MethodNotAllowed = 'platform.method_not_allowed';
    case CsrfTokenMismatch = 'platform.csrf_token_mismatch';
    case ValidationFailed = 'platform.validation_failed';
    case TooManyRequests = 'platform.too_many_requests';
    case ServerError = 'platform.server_error';
    case HttpError = 'platform.http_error';
    /** A write was made with an old edit-lock epoch or by someone who no longer holds the lock (HTTP 423; Story 2.8). */
    case EditLockLost = 'platform.edit_lock_lost';
}
