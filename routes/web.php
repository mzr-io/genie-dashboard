<?php

use App\Http\Controllers\HelpController;
use App\Modules\Identity\Http\InvitationController;
use App\Modules\Identity\Http\InvitationResponseHeaders;
use App\Modules\Identity\Http\WorkspaceSwitchController;
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

// Help & support is open to guests: the sign-in page links to it. Signed-in people get it inside the shell
// as a placeholder until Story 1.18.
Route::get('help', HelpController::class)->name('help');

// Every navigation target of the two-area shell is a named placeholder page (Story 1.16). Admin pages are not
// gated here: Story 1.19 enforces the area and permission checks.
Route::middleware(['auth'])->group(function () {
    // Switch the active Workspace (Story 1.17). It opens the target Workspace's own transaction, so the
    // session Workspace's request transaction must not wrap it.
    Route::post('workspaces/switch', WorkspaceSwitchController::class)
        ->withoutMiddleware([WorkspaceTransaction::class])
        ->middleware('throttle:30,1')
        ->name('workspaces.switch');

    // The User Overview (Story 1.13). The old route name `dashboard` is kept as an alias that sends visitors
    // to it (a route name cannot point at the same URI twice).
    Route::inertia('dashboard', 'Placeholder', ['page' => 'overview'])->name('overview');
    Route::redirect('overview', '/dashboard')->name('dashboard');
    Route::inertia('dashboards', 'Placeholder', ['page' => 'my-dashboards'])->name('dashboards.index');
    Route::inertia('templates', 'Placeholder', ['page' => 'templates'])->name('templates.index');

    Route::prefix('admin')->group(function () {
        Route::inertia('/', 'Placeholder', ['page' => 'admin-overview'])->name('admin.overview');
        Route::inertia('blocks', 'Placeholder', ['page' => 'block-management'])->name('admin.blocks.index');
        Route::inertia('blocks/create', 'Placeholder', ['page' => 'create-block'])->name('admin.blocks.create');
        Route::inertia('blocks/drafts', 'Placeholder', ['page' => 'draft-blocks'])->name('admin.blocks.drafts');
        Route::inertia('blocks/published', 'Placeholder', ['page' => 'published-blocks'])->name('admin.blocks.published');
        Route::inertia('categories', 'Placeholder', ['page' => 'block-categories'])->name('admin.categories.index');
        Route::inertia('templates', 'Placeholder', ['page' => 'dashboard-templates'])->name('admin.templates.index');
        Route::inertia('data-sources', 'Placeholder', ['page' => 'data-sources'])->name('admin.data-sources.index');
        Route::inertia('users', 'Placeholder', ['page' => 'user-configuration'])->name('admin.users.index');
        Route::inertia('settings', 'Placeholder', ['page' => 'system-settings'])->name('admin.settings.index');
        Route::inertia('audit', 'Placeholder', ['page' => 'audit-log'])->name('admin.audit.index');
    });
});

require __DIR__.'/settings.php';
