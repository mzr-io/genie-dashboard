<?php

namespace App\Http\Requests\Admin;

use App\Http\Responses\AdminApiError;
use App\Platform\Contracts\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The name of a group to create or rename (Story 1.23): trimmed, 1 to 64 characters, no control characters. The
 * `admin` middleware has already authorised the request (`users.manage`). A name another group already has is refused
 * by the service with a field error.
 */
final class GroupNameRequest extends FormRequest
{
    public const MAX_LENGTH = 64;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        if (is_string($name)) {
            $this->merge(['name' => trim($name)]);
        }
    }

    /**
     * @return array<string, list<string|\Closure>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:'.self::MAX_LENGTH, function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value) === 1)) {
                    $fail('The :attribute contains characters that are not allowed.');
                }
            }],
        ];
    }

    public function groupName(): string
    {
        return trim((string) $this->input('name'));
    }

    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(AdminApiError::json($this, ErrorCode::ValidationFailed->value, 422, errors: $errors));
    }
}
