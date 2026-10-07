<?php

namespace App\Http\Requests\Admin;

use App\Http\Responses\AdminApiError;
use App\Platform\Contracts\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Deactivate or reactivate a member (Story 1.24): the `revision` the Admin saw (required). The `admin` middleware has
 * already authorised the request (`users.manage`).
 */
final class MemberStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['revision' => ['required', 'integer', 'min:1', 'max:2147483647']];
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
