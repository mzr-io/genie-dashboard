<?php

namespace App\Support\Observability;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** `{error:{code, message, request_id, details}}`; `details` only in the Admin area. */
final class ApiErrorRenderer
{
    private const CODES = [
        401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed',
        419 => 'csrf_token_mismatch', 422 => 'validation_failed', 429 => 'too_many_requests',
        500 => 'server_error',
    ];

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        $headers = [];
        $details = null;
        if ($e instanceof ValidationException) {
            $status = 422;
            $details = $e->errors();
        } elseif ($e instanceof AuthenticationException) {
            $status = 401;
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $headers = $e->getHeaders();
            $details = $e->getMessage() !== '' ? $e->getMessage() : null;
        } else {
            $status = 500;
        }

        $error = [
            'code' => 'platform.'.(self::CODES[$status] ?? 'http_'.$status),
            'message' => Response::$statusTexts[$status] ?? 'Error',
            'request_id' => $request->attributes->get('request_id')
                ?? app(RequestContext::class)->requestId(),
        ];

        if ($request->attributes->get('area') === 'admin' && $details !== null) {
            $error['details'] = Scrubber::value($details);
        }

        return response()->json(['error' => $error], $status, $headers);
    }
}
