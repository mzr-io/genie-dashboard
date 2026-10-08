<?php

use App\Http\Controllers\Admin\AttributeKeyController;
use App\Http\Controllers\Admin\DataSourceController;
use App\Http\Controllers\Admin\DataSourceLockController;
use App\Http\Controllers\Admin\EndpointController;
use App\Http\Controllers\Admin\GroupController;
use App\Http\Controllers\Admin\HostAllowlistController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\MemberAttributeController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\OperationController;
use App\Http\Middleware\RejectsSecretValues;
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

    // User attributes (Story 2.12): the catalogue of keys (`settings.manage`) and a member's values (`users.manage`), mapped in ShellNavigation::ADMIN_API_ROUTES.
    Route::get('user-attributes', [AttributeKeyController::class, 'index'])->middleware('admin')->name('api.admin.user-attributes.index');
    Route::post('user-attributes', [AttributeKeyController::class, 'store'])->middleware(['admin', 'throttle:30,1'])->name('api.admin.user-attributes.store');
    Route::put('user-attributes/{key}', [AttributeKeyController::class, 'update'])->middleware(['admin', 'throttle:30,1'])->name('api.admin.user-attributes.update');
    Route::get('members/{membership}/attributes', [MemberAttributeController::class, 'show'])->middleware('admin')->name('api.admin.members.attributes.show');
    Route::put('members/{membership}/attributes', [MemberAttributeController::class, 'update'])->middleware(['admin', 'throttle:30,1'])->name('api.admin.members.attributes.update');

    // The Workspace host allowlist (Story 2.1): `settings.manage`, mapped in ShellNavigation::ADMIN_API_ROUTES.
    Route::get('host-allowlist', [HostAllowlistController::class, 'index'])->middleware('admin')->name('api.admin.host-allowlist.index');
    Route::post('host-allowlist', [HostAllowlistController::class, 'store'])->middleware(['admin', 'throttle:30,1'])->name('api.admin.host-allowlist.store');
    Route::delete('host-allowlist/{entry}', [HostAllowlistController::class, 'destroy'])->middleware(['admin', 'throttle:30,1'])->name('api.admin.host-allowlist.destroy');
    Route::get('host-allowlist/{entry}/dependents', [HostAllowlistController::class, 'dependents'])->middleware('admin')->name('api.admin.host-allowlist.dependents');

    // Data Sources (Story 2.3): `data_sources.manage`, mapped in ShellNavigation::ADMIN_API_ROUTES. `check-url` is the Base URL blur check.
    Route::get('data-sources', [DataSourceController::class, 'index'])->middleware('admin')->name('api.admin.data-sources.index');
    Route::post('data-sources', [DataSourceController::class, 'store'])->middleware(['admin', 'throttle:30,1'])->name('api.admin.data-sources.store');
    Route::post('data-sources/check-url', [DataSourceController::class, 'checkUrl'])->middleware(['admin', RejectsSecretValues::class, 'throttle:60,1,data-source-check'])->name('api.admin.data-sources.check-url');
    // Test connection (Story 2.5): starts an Operation; nothing is saved, nothing is called from this tier. Typed secrets are accepted here.
    Route::post('data-sources/test-connection', [DataSourceController::class, 'testConnection'])->middleware(['admin', 'throttle:30,1,data-source-test'])->name('api.admin.data-sources.test-connection');
    Route::get('data-sources/{dataSource}', [DataSourceController::class, 'show'])->middleware('admin')->name('api.admin.data-sources.show');
    Route::put('data-sources/{dataSource}', [DataSourceController::class, 'update'])->middleware(['admin', 'throttle:30,1'])->name('api.admin.data-sources.update');

    // Endpoints of a Data Source (Story 2.9): same permission, mapped in ShellNavigation::ADMIN_API_ROUTES. Nothing here sends a request.
    Route::get('data-sources/{dataSource}/endpoints', [EndpointController::class, 'index'])->middleware('admin')->name('api.admin.data-sources.endpoints.index');
    Route::post('data-sources/{dataSource}/endpoints', [EndpointController::class, 'store'])->middleware(['admin', 'throttle:30,1,data-source-endpoints'])->name('api.admin.data-sources.endpoints.store');
    Route::get('data-sources/{dataSource}/endpoints/{endpoint}', [EndpointController::class, 'show'])->middleware('admin')->name('api.admin.data-sources.endpoints.show');
    Route::put('data-sources/{dataSource}/endpoints/{endpoint}', [EndpointController::class, 'update'])->middleware(['admin', 'throttle:30,1,data-source-endpoints'])->name('api.admin.data-sources.endpoints.update');
    // Test an Endpoint (Story 2.10): starts a `sample_fetch` Operation; the Sample Response is read back by the requester alone. Nothing is called from this tier.
    Route::post('data-sources/{dataSource}/endpoints/{endpoint}/test', [EndpointController::class, 'test'])->middleware(['admin', 'throttle:30,1,data-source-endpoint-test'])->name('api.admin.data-sources.endpoints.test');
    Route::get('data-sources/{dataSource}/endpoints/{endpoint}/samples/{operation}', [EndpointController::class, 'sample'])->middleware(['admin', 'throttle:120,1,data-source-endpoint-sample'])->name('api.admin.data-sources.endpoints.samples.show');

    // The Data source soft lock (Story 2.8): acquire, heartbeat, release (also by beacon), take over (request, then poll) and
    // the holder's flush acknowledgement. Same permission; none of them takes a secret value; the heartbeat and the polls send `X-Background: 1`.
    Route::post('data-sources/{dataSource}/lock', [DataSourceLockController::class, 'acquire'])->middleware(['admin', 'throttle:data-source-lock'])->name('api.admin.data-sources.lock.acquire');
    Route::put('data-sources/{dataSource}/lock', [DataSourceLockController::class, 'heartbeat'])->middleware(['admin', 'throttle:data-source-lock'])->name('api.admin.data-sources.lock.heartbeat');
    Route::post('data-sources/{dataSource}/lock/release', [DataSourceLockController::class, 'release'])->middleware(['admin', 'throttle:data-source-lock'])->name('api.admin.data-sources.lock.release');
    Route::post('data-sources/{dataSource}/lock/takeover', [DataSourceLockController::class, 'takeover'])->middleware(['admin', 'throttle:data-source-lock'])->name('api.admin.data-sources.lock.takeover');
    Route::get('data-sources/{dataSource}/lock/takeover', [DataSourceLockController::class, 'takeoverStatus'])->middleware(['admin', 'throttle:data-source-lock'])->name('api.admin.data-sources.lock.takeover.status');
    Route::post('data-sources/{dataSource}/lock/flush', [DataSourceLockController::class, 'flush'])->middleware(['admin', 'throttle:data-source-lock'])->name('api.admin.data-sources.lock.flush');
});

// Operations (Story 2.5): the summary of an asynchronous Operation, for the membership that started it and nobody else.
Route::middleware(['auth:sanctum', 'throttle:120,1,operation-status'])->get('operations/{operation}', [OperationController::class, 'show'])->name('api.operations.show');
