<?php

namespace App\Http\Requests\Admin;

use App\Http\Responses\AdminApiError;
use App\Platform\Contracts\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Setting a member's user attribute values (Story 2.12): `values`, a map of key id to value. The shape of each value is
 * the key's value type, checked by the service (a 422 that names `values.{key id}`). The value never enters an error
 * message. The `admin` middleware has already authorised the request (`users.manage`).
 */
final class MemberAttributesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['values' => ['required', 'array', 'max:200']];
    }

    /** @return array<array-key, mixed> */
    public function values(): array
    {
        $values = $this->input('values');

        return is_array($values) ? $values : [];
    }

    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(AdminApiError::json($this, ErrorCode::ValidationFailed->value, 422, errors: $errors));
    }
}
