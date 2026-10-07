<?php

use App\Http\Controllers\Admin\GroupController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\MemberController;
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

    // User configuration (Story 1.20). The permission comes from ShellNavigation::ADMIN_API_ROUTES by route name.
    Route::get('members', [MemberController::class, 'index'])->middleware('admin')->name('api.admin.members');
    Route::get('members/{membership}', [MemberController::class, 'show'])->middleware('admin')->name('api.admin.members.show');
    Route::patch('members/{membership}', [MemberController::class, 'update'])->middleware(['admin', 'throttle:30,1'])->name('api.admin.members.update');
    // Deactivate and reactivate (Story 1.24): only the membership status changes.
    Route::post('members/{membership}/deactivate', [MemberController::class, 'deactivate'])->middleware(['admin', 'throttle:30,1'])->name('api.admin.members.deactivate');
    Route::post('members/{membership}/reactivate', [MemberController::class, 'reactivate'])->middleware(['admin', 'throttle:30,1'])->name('api.admin.members.reactivate');

    // Invitations (Story 1.21): create, re-send (replaces the token) and revoke. Same mapping, permission `users.manage`.
    Route::post('invitations', [InvitationController::class, 'store'])->middleware(['admin', 'throttle:30,1'])->name('api.admin.invitations.store');
    Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend'])->middleware(['admin', 'throttle:30,1'])->name('api.admin.invitations.resend');
    Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->middleware(['admin', 'throttle:30,1'])->name('api.admin.invitations.destroy');

    // Groups (Story 1.23): list, create, rename, delete and add or remove members. Permission `users.manage`.
    Route::get('groups', [GroupController::class, 'index'])->middleware('admin')->name('api.admin.groups.index');
    Route::post('groups', [GroupController::class, 'store'])->middleware(['admin', 'throttle:30,1'])->name('api.admin.groups.store');
    Route::patch('groups/{group}', [GroupController::class, 'update'])->middleware(['admin', 'throttle:30,1'])->name('api.admin.groups.update');
    Route::delete('groups/{group}', [GroupController::class, 'destroy'])->middleware(['admin', 'throttle:30,1'])->name('api.admin.groups.destroy');
    Route::post('groups/{group}/members/{membership}', [GroupController::class, 'addMember'])->middleware(['admin', 'throttle:60,1'])->name('api.admin.groups.members.store');
    Route::delete('groups/{group}/members/{membership}', [GroupController::class, 'removeMember'])->middleware(['admin', 'throttle:60,1'])->name('api.admin.groups.members.destroy');
});
