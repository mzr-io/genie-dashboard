<?php

namespace App\Http\Requests\Admin;

use App\Http\Responses\AdminApiError;
use App\Modules\Access\Contracts\Permission;
use App\Platform\Contracts\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Invite a person (Story 1.21): email, role `user` or `admin` and, for `admin`, permissions from the closed
 * catalogue. The `admin` middleware has already authorised the request (`users.manage`). A permission the inviter
 * does not hold is not a validation matter: the controller answers it with 403 `access.permission_not_held`.
 * `confirm_password` is the inviter's current password, checked by the controller for every `admin` invitation (after the permission check, which answers 403 first).
 */
final class InviteMemberRequest extends FormRequest
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
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254', 'regex:/\A[^\p{C}\s]+\z/u'],
            'role' => ['required', 'string', Rule::in(['user', 'admin'])],
            'permissions' => ['nullable', 'array', 'max:'.count(Permission::cases())],
            'permissions.*' => ['string', Rule::in(Permission::values())],
            'confirm_password' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('role') === 'user' && $this->permissions() !== []) {
                $validator->errors()->add('permissions', 'A user invitation carries no permissions.');
            }
        });
    }

    public function email(): string
    {
        return strtolower(trim((string) $this->input('email')));
    }

    public function role(): string
    {
        return (string) $this->input('role');
    }

    /** @return list<string> */
    public function permissions(): array
    {
        $permissions = $this->input('permissions');

        return is_array($permissions) ? array_values(array_unique(array_filter($permissions, is_string(...)))) : [];
    }

    public function confirmation(): string
    {
        $value = $this->input('confirm_password');

        return is_string($value) ? $value : '';
    }

    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(AdminApiError::json($this, ErrorCode::ValidationFailed->value, 422, errors: $errors));
    }
}
