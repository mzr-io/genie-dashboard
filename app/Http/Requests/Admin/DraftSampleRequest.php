<?php

namespace App\Http\Requests\Admin;

use App\Http\Responses\AdminApiError;
use App\Platform\Contracts\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A pasted sample to check or store (Story 3.8): the `text` as pasted and, on a store, the "contains personal data" acknowledgement and the
 * edit-lock claim a Draft save carries (`lock_epoch`, `lock_token`). The text is the only free-form field: it is never read by the secret
 * guard (which looks at field names, not at what a sample says) and is never logged, audited or echoed. Its size and shape are judged by the
 * application against the platform limits, not here. The `admin` middleware has authorised the request (`blocks.edit`).
 */
final class DraftSampleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        if ($this->isMethod('PUT')) {
            return [
                // `present`, not `required`: a whitespace-only paste is invalid JSON, not a missing field.
                'text' => ['present', 'string'],
                'contains_personal_data' => ['required', 'boolean'],
                'lock_epoch' => ['required', 'integer', 'min:1'],
                'lock_token' => ['nullable', 'string', 'max:128'],
            ];
        }

        return ['text' => ['nullable', 'string']];
    }

    public function text(): string
    {
        $text = $this->input('text');

        return is_string($text) ? $text : '';
    }

    public function containsPersonalData(): bool
    {
        return $this->boolean('contains_personal_data');
    }

    public function lockEpoch(): int
    {
        return (int) $this->input('lock_epoch');
    }

    public function lockToken(): string
    {
        $value = $this->input('lock_token');

        return is_string($value) ? $value : '';
    }

    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(AdminApiError::json($this, ErrorCode::ValidationFailed->value, 422, errors: $errors));
    }
}
