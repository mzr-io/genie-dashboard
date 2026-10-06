<?php

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

Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
