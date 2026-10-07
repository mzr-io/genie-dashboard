<?php

use App\Modules\Identity\Http\SessionController;
use Illuminate\Support\Facades\Route;

// Served under /api/v1 (see bootstrap/app.php). Same-origin SPA cookie auth via Sanctum.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('ping', fn () => response()->json(['status' => 'ok']))->name('api.ping');
    // State-changing probe so the CSRF contract (X-XSRF-TOKEN) is testable.
    Route::post('ping', fn () => response()->json(['status' => 'ok']))->name('api.ping.store');
});

// The session's idle clock (Story 1.15). Status never extends; extend is user-initiated and CSRF-protected.
Route::middleware('auth')->prefix('session')->group(function () {
    Route::get('/', [SessionController::class, 'status'])->name('api.session.status');
    Route::post('extend', [SessionController::class, 'extend'])->middleware('throttle:30,1')->name('api.session.extend');
});

// Admin APIs (Story 1.19): the `admin` middleware requires the Admin area and the permission, after the stateful
// group's CSRF check. The probes prove the gate until the Admin features add their endpoints.
Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
    Route::get('ping', fn () => response()->json(['status' => 'ok']))->middleware('admin:audit.view')->name('api.admin.ping');
    Route::post('ping', fn () => response()->json(['status' => 'ok']))->middleware('admin:settings.manage')->name('api.admin.ping.store');
});
