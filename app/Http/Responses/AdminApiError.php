<?php

namespace App\Http\Responses;

use App\Support\Observability\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The error envelope of the Admin API (`{error: {code, message, request_id}}`) with the extra members a form needs:
 * `errors` (field => messages, like a Laravel validation failure) and any named extras. Nothing in it is a token or a
 * hash; an invitee's email is never echoed in an error.
 */
final class AdminApiError
{
    /**
     * @param  array<string, list<string>>  $errors  field errors
     * @param  array<string, mixed>  $extra  further top-level members (`reason`, `invitation_id`, `data`)
     */
    public static function json(Request $request, string $code, int $status, ?string $message = null, array $errors = [], array $extra = []): JsonResponse
    {
        $body = [
            'error' => [
                'code' => $code,
                'message' => $message ?? Response::$statusTexts[$status] ?? 'Error',
                'request_id' => $request->attributes->get('request_id') ?? app(RequestContext::class)->requestId(),
            ],
        ];

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return response()->json($body + $extra, $status, ['Cache-Control' => 'no-store, private']);
    }
}
