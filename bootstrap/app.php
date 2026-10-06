<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Identity\Http\IdleTimeout;
use App\Platform\Tenancy\WorkspaceTransaction;
use App\Support\Observability\ApiErrorRenderer;
use App\Support\Observability\OtelBootstrap;
use App\Support\Observability\RequestContextMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

// Register the tracer before the framework starts so the request's root span is exported too.
OtelBootstrap::boot();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RequestContextMiddleware::class);

        $middleware->statefulApi();

        $middleware->encryptCookies(except: ['sidebar_state']);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // After the session starts: signs out an idle session, then opens the request transaction and sets the Workspace context.
            IdleTimeout::class,
            WorkspaceTransaction::class,
        ]);

        $middleware->api(append: [IdleTimeout::class, WorkspaceTransaction::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(ApiErrorRenderer::render(...));

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
