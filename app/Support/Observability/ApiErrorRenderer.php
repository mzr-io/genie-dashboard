<?php

namespace App\Support\Observability;

use App\Platform\Contracts\ErrorCode;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
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
        401 => ErrorCode::Unauthenticated, 403 => ErrorCode::Forbidden, 404 => ErrorCode::NotFound,
        405 => ErrorCode::MethodNotAllowed, 419 => ErrorCode::CsrfTokenMismatch, 422 => ErrorCode::ValidationFailed,
        429 => ErrorCode::TooManyRequests, 500 => ErrorCode::ServerError,
    ];

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        if ($e instanceof HttpResponseException) {
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
            'code' => (self::CODES[$status] ?? ErrorCode::HttpError)->value,
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
