<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Modules\Identity\Application\ChangePassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class PasswordController extends Controller
{
    /**
     * Change the password: the request has checked the current one. Other sessions end; this one stays.
     */
    public function update(PasswordUpdateRequest $request, ChangePassword $change): RedirectResponse
    {
        try {
            $change->handle($request, $request->user(), (string) $request->validated('password'));
        } catch (Throwable $e) {
            // Nothing changed (the transaction rolled back): the page shows its inline failure message.
            Log::error('identity.password.change_failed', ['exception' => $e::class]);

            return back()->withErrors(['form' => 'save-failed']);
        }

        return to_route('profile.edit');
    }
}
