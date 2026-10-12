<?php

namespace App\Http\Requests\Admin;

use App\Http\Responses\AdminApiError;
use App\Platform\Contracts\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A validation of a Draft against the real API (Story 3.18, wizard step 5): the edit-lock claim of a Draft save (`lock_epoch`, `lock_token`). Dates are never
 * sent: the default range and the Block's period come from the Draft, like the Data Source, the Endpoint and the Fixed parameters, none of which is
 * taken from the client. The `admin` middleware has authorised the request (`blocks.edit`).
 */
final class DraftValidationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // Resolved dates are never accepted from a client (AD-27): the server derives them from the Draft.
            'range' => ['prohibited'],
            'period' => ['prohibited'],
            'lock_epoch' => ['required', 'integer', 'min:1'],
            'lock_token' => ['nullable', 'string', 'max:128'],
        ];
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
