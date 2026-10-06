<?php

use Illuminate\Support\Facades\Route;

// Served under /api/v1 (see bootstrap/app.php). Same-origin SPA cookie auth via Sanctum.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('ping', fn () => response()->json(['status' => 'ok']))->name('api.ping');
    // State-changing probe so the CSRF contract (X-XSRF-TOKEN) is testable.
    Route::post('ping', fn () => response()->json(['status' => 'ok']))->name('api.ping.store');
});
