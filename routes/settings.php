<?php

use App\Http\Controllers\AvatarController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Change password is a section of Profile & settings; it needs the current password itself.
    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    // Profile pictures (Story 1.18): signed-in people only, from the private disk.
    Route::get('avatars/{user}', AvatarController::class)->whereNumber('user')->name('avatars.show');
});
