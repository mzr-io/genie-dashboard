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
 * Change a member's role and permissions (Story 1.22): the `revision` the editor saw (required), a `role` (`user` or
 * `admin`) and `permissions` (the complete desired set, catalogue values) when they change, and `confirm_password`
 * (the editor's current password), which the service asks for only when the permission set changes or the role becomes
 * `admin`. The `admin` middleware has already authorised the request (`users.manage`).
 */
final class UpdateMemberRequest extends FormRequest
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
            'revision' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'role' => ['sometimes', 'string', Rule::in(['user', 'admin'])],
            'permissions' => ['sometimes', 'array', 'max:'.count(Permission::cases())],
            'permissions.*' => ['string', Rule::in(Permission::values())],
            'confirm_password' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function revision(): int
    {
        return (int) $this->input('revision');
    }

    public function role(): ?string
    {
        $role = $this->input('role');

        return is_string($role) ? $role : null;
    }

    /** @return list<string>|null null when the request does not name the set */
    public function permissions(): ?array
    {
        $permissions = $this->input('permissions');

        return is_array($permissions) ? array_values(array_unique(array_filter($permissions, is_string(...)))) : null;
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
