<?php

use App\Modules\Identity\Http\InvitationController;
use App\Modules\Identity\Http\InvitationResponseHeaders;
use App\Platform\Tenancy\WorkspaceTransaction;
use App\Support\Health\HealthChecker;
use App\Support\Health\Role;
use Illuminate\Support\Facades\Route;

// Health probes skip the web middleware group: no session, so they never touch PostgreSQL themselves.
Route::withoutMiddleware('web')->group(function () {
    Route::get('health/live', fn () => response()->json(['status' => 'ok']))->name('health.live');
    Route::get('health/ready', function (HealthChecker $checker) {
        $report = $checker->check(Role::Web);

        return response()->json($report->toArray(), $report->healthy() ? 200 : 503);
    })->name('health.ready');
});

Route::inertia('/', 'Welcome')->name('home');

// Invitation links (Story 1.12). Public registration stays off and no route creates a Workspace:
// Workspaces are created only by `php artisan dashflow:workspace:create` (operator).
// No `guest` middleware: a signed-in user (an existing member of another Workspace) can accept too.
Route::middleware([InvitationResponseHeaders::class, 'throttle:30,1'])->prefix('invitations')
    // The accept flow opens the invited Workspace's own transaction; a signed-in user's session Workspace must not wrap it.
    ->withoutMiddleware([WorkspaceTransaction::class])->group(function () {
        Route::get('expired', [InvitationController::class, 'expired'])->name('invitations.expired');
        Route::get('{token}', [InvitationController::class, 'show'])->name('invitations.show');
        Route::post('{token}', [InvitationController::class, 'store'])->name('invitations.accept');
    });

// Help & support is open to guests: the sign-in page links to it. Placeholder until Story 1.18.
Route::inertia('help', 'auth/Help')->name('help');

Route::middleware(['auth'])->group(function () {
    // The User Overview (Story 1.13): the existing dashboard page under its new name. The old route name
    // `dashboard` is kept as an alias that sends visitors to it (a route name cannot point at the same URI twice).
    Route::inertia('dashboard', 'Dashboard')->name('overview');
    Route::redirect('overview', '/dashboard')->name('dashboard');

    // Placeholders until Stories 1.16 (shell) and 1.19 (Admin gating) replace them.
    Route::inertia('admin', 'admin/Overview')->name('admin.overview');
});

require __DIR__.'/settings.php';
