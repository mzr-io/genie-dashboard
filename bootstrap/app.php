<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireActiveMembership;
use App\Http\Middleware\RequireAdminAccess;
use App\Modules\Identity\Http\IdleTimeout;
use App\Modules\Identity\Http\SendInvitationsAfterCommit;
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

        // `admin` / `admin:{permission}`: the Admin area and permission gate (Story 1.19).
        $middleware->alias(['admin' => RequireAdminAccess::class]);

        $middleware->encryptCookies(except: ['sidebar_state']);

        // The host allowlist refuses whitespace instead of silently trimming it (Story 2.1).
        $middleware->trimStrings(except: [fn (Request $request): bool => $request->is('api/v1/admin/host-allowlist', 'api/v1/admin/host-allowlist/*')]);
        // An empty scheme or host reaches the validation as the empty string and is refused, never repaired to a default.
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request): bool => $request->is('api/v1/admin/host-allowlist', 'api/v1/admin/host-allowlist/*')]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // After the session starts: signs out an idle session, then opens the request transaction and sets the Workspace context.
            IdleTimeout::class,
            // Every authenticated request re-checks that the session's Workspace membership is still active (Story 1.24).
            RequireActiveMembership::class,
            WorkspaceTransaction::class,
        ]);

        // Invitation emails go out after the transaction of the next middleware has committed.
        $middleware->api(append: [IdleTimeout::class, RequireActiveMembership::class, SendInvitationsAfterCommit::class, WorkspaceTransaction::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(ApiErrorRenderer::render(...));

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
