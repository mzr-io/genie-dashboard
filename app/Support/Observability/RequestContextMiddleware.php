<?php

namespace App\Support\Observability;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class RequestContextMiddleware
{
    public const HEADER = 'X-Request-Id';

    public function __construct(private readonly RequestContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->context->begin($request->headers->get(self::HEADER));
        $request->attributes->set('request_id', $id);
        $request->attributes->set('observability.started_at', hrtime(true));

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $started = $request->attributes->get('observability.started_at');

        Log::info('http.request', [
            'method' => $request->getMethod(),
            'url' => Scrubber::url($request->getPathInfo()),
            'status' => $response->getStatusCode(),
            'duration_ms' => is_int($started) ? round((hrtime(true) - $started) / 1e6, 2) : null,
        ]);

        $this->context->clear();
    }
}
