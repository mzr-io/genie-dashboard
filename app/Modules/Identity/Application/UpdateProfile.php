<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Infrastructure\AvatarStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Saves the person's profile: name, locale, time zone, the Keyboard shortcuts switch and, when one is
 * uploaded, a new avatar. The new file is stored first and the row updated second; the previous file is
 * deleted only after the row points at the new one, so a failure never leaves the person without a picture.
 * The input is already validated by `ProfileUpdateRequest`.
 */
final class UpdateProfile
{
    public function __construct(private readonly AvatarStore $avatars) {}

    /**
     * @param  array{name: string, locale: string, timezone: string, keyboard_shortcuts: bool}  $data
     */
    public function handle(User $user, array $data, ?UploadedFile $avatar): void
    {
        $previous = $user->avatar_path;
        $stored = $avatar instanceof UploadedFile ? $this->avatars->put($avatar) : null;

        try {
            $user->forceFill([
                'name' => $data['name'],
                'locale' => $data['locale'],
                'timezone' => $data['timezone'],
                'keyboard_shortcuts' => $data['keyboard_shortcuts'],
                ...($stored !== null ? ['avatar_path' => $stored] : []),
            ])->save();
        } catch (Throwable $e) {
            $this->avatars->delete($stored);

            throw $e;
        }

        if ($stored !== null && $previous !== null && $previous !== $stored) {
            try {
                $this->avatars->delete($previous);
            } catch (Throwable) {
                // The old file is only orphaned; the save itself succeeded.
                Log::warning('identity.avatar.delete_failed');
            }
        }
    }
}
