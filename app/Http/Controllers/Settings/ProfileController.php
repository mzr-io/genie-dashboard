<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Modules\Identity\Application\AvatarLimit;
use App\Modules\Identity\Application\UpdateProfile;
use App\Modules\Identity\Contracts\SupportedLocales;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show Profile & settings: the profile form and the Change password section.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/Profile', [
            'locales' => SupportedLocales::all(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'avatarMaxBytes' => AvatarLimit::bytes() ?: null,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    /**
     * Save the profile. The success message is announced by the page, not flashed.
     */
    public function update(ProfileUpdateRequest $request, UpdateProfile $profile): RedirectResponse
    {
        $validated = $request->validated();

        $profile->handle($request->user(), [
            'name' => $validated['name'],
            'locale' => $validated['locale'],
            'timezone' => $validated['timezone'],
            'keyboard_shortcuts' => (bool) $validated['keyboard_shortcuts'],
        ], $request->file('avatar'));

        return to_route('profile.edit');
    }
}
