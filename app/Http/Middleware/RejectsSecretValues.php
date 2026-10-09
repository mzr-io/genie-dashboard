<?php

namespace App\Http\Middleware;

use App\Http\Responses\AdminApiError;
use App\Modules\Connector\Contracts\ErrorCode;
use App\Support\Observability\Scrubber;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The guard for form and Draft endpoints that must not carry secrets (Story 2.4): a payload with a secret-valued field is
 * refused with a 422 (`secret-values-refused`) before the controller sees it, so a credential never reaches a Draft, a
 * log line or a store that was not built for it. A field is secret-valued when it is named like one (the Scrubber's credential names, at any depth) or is flagged `secret` with a value.
 * Only the field names are inspected and reported: the value is never read into a message.
 *
 * Use it as route middleware: `->middleware(RejectsSecretValues::class)`.
 */
final class RejectsSecretValues
{
    private const MAX_DEPTH = 8;

    public function handle(Request $request, Closure $next): Response
    {
        if (self::containsSecretValue($request->all())) {
            return AdminApiError::json($request, ErrorCode::SecretValuesRefused->value, 422, 'This request cannot carry a secret value.', extra: ['reason' => 'secret-values-refused']);
        }

        return $next($request);
    }

    /** Whether any field, at any depth, is a secret-valued one. */
    public static function containsSecretValue(mixed $value, int $depth = 0): bool
    {
        if (! is_array($value)) {
            return false;
        }

        if ($depth > self::MAX_DEPTH) {
            // Too deep to inspect: refuse rather than let it through.
            return true;
        }

        if (filter_var($value['secret'] ?? false, FILTER_VALIDATE_BOOLEAN) && array_key_exists('value', $value) && $value['value'] !== null && $value['value'] !== '') {
            return true;
        }

        foreach ($value as $name => $inner) {
            if (is_string($name) && $name !== 'secret' && self::isSecretName($name) && $inner !== null && $inner !== '' && $inner !== [] && ! is_bool($inner)) {
                return true;
            }

            if (self::containsSecretValue($inner, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    public static function isSecretName(string $name): bool
    {
        // The same names the Scrubber redacts; `api_key_name`, `api_key_placement` and `oauth_token_url` are plain settings, not credentials.
        return preg_match(Scrubber::SENSITIVE_KEY, $name) === 1 && preg_match('/\A(?:api[_-]?key[_-]?(?:name|placement)|oauth[_-]?token[_-]?url)\z/i', $name) !== 1;
    }
}
