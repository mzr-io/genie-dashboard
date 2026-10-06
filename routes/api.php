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
