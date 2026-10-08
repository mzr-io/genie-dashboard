<?php

namespace App\Http\Requests\Admin;

use App\Http\Responses\AdminApiError;
use App\Modules\Access\Contracts\AttributeKeyRow;
use App\Platform\Contracts\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Creating a user attribute key (`key_id`, `label`, `value_type`) or renaming one (`label` and the `revision` seen), Story
 * 2.12. A rename ignores `key_id` and `value_type`: they never change. The label is trimmed, 1 to 64 characters, with no
 * control, format or separator characters. The `admin` middleware has already authorised the request (`settings.manage`).
 */
final class AttributeKeyRequest extends FormRequest
{
    public const LABEL_MAX = 64;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $label = $this->input('label');

        if (is_string($label)) {
            $this->merge(['label' => trim($label)]);
        }
    }

    /** Whether this is a rename (a PUT): the key is already there, so only the label and the revision count. */
    private function renaming(): bool
    {
        return $this->isMethod('PUT');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $label = ['required', 'string', 'min:1', 'max:'.self::LABEL_MAX, function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value) === 1)) {
                $fail('The :attribute contains characters that are not allowed.');
            }
        }];

        if ($this->renaming()) {
            return ['label' => $label, 'revision' => ['required', 'integer', 'min:1', 'max:2147483647']];
        }

        return [
            'key_id' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_]{0,47}\z/D'],
            'label' => $label,
            'value_type' => ['required', 'string', 'in:'.implode(',', AttributeKeyRow::TYPES)],
        ];
    }

    public function keyId(): string
    {
        return (string) $this->input('key_id');
    }

    public function label(): string
    {
        return trim((string) $this->input('label'));
    }

    public function valueType(): string
    {
        return (string) $this->input('value_type');
    }

    public function revision(): int
    {
        return (int) $this->input('revision');
    }

    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(AdminApiError::json($this, ErrorCode::ValidationFailed->value, 422, errors: $errors));
    }
}
