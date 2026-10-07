<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Modules\Identity\Application\AvatarLimit;
use App\Modules\Identity\Contracts\SupportedLocales;
use App\Modules\Identity\Infrastructure\AvatarStore;
use Closure;
use DateTimeZone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Profile & settings: name, locale, time zone, the Keyboard shortcuts switch and an optional avatar. The
 * avatar is PNG, JPEG or WebP only (SVG is refused), checked by file extension and by content, and no
 * larger than `AvatarLimit`. A refusal is a field error on `avatar`; nothing is saved, so the existing
 * avatar stays.
 */
class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => $this->nameRules(),
            'locale' => ['required', 'string', Rule::in(SupportedLocales::all())],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'keyboard_shortcuts' => ['required', 'boolean'],
            'avatar' => ['nullable', $this->avatarRule()],
        ];
    }

    /**
     * A failed upload has no temporary path, so Laravel skips rules for it; it is checked here so it still
     * shows on `avatar`. (A body over `post_max_size` never gets this far: see `bootstrap/app.php`.)
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('avatar')) {
                return;
            }

            $file = $this->file('avatar');

            if ($file instanceof UploadedFile && ! $file->isValid()) {
                $validator->errors()->add('avatar', $this->uploadErrorMessage($file));

                return;
            }

        }];
    }

    /** One rule, so the field shows one message whatever the reason. */
    private function avatarRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                $fail($this->wrongTypeMessage());

                return;
            }

            if (! $value->isValid()) {
                $fail($this->uploadErrorMessage($value));

                return;
            }

            $extension = strtolower($value->getClientOriginalExtension());

            if (! in_array($extension, ['png', 'jpg', 'jpeg', 'webp'], true) || AvatarStore::detect($value) === null) {
                $fail($this->wrongTypeMessage());

                return;
            }

            if (! AvatarStore::withinBounds($value)) {
                $fail('Choose an image no larger than '.AvatarStore::MAX_SIDE.' by '.AvatarStore::MAX_SIDE.' pixels and 16 megapixels.');

                return;
            }

            $limit = AvatarLimit::bytes();

            if ($limit > 0 && $value->getSize() > $limit) {
                $fail($this->tooLargeMessage());
            }
        };
    }

    private function uploadErrorMessage(UploadedFile $file): string
    {
        return in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? $this->tooLargeMessage()
            : 'The upload failed. Try again.';
    }

    private function wrongTypeMessage(): string
    {
        return 'Choose a PNG, JPEG or WebP image.';
    }

    private function tooLargeMessage(): string
    {
        return AvatarLimit::tooLargeMessage();
    }
}
