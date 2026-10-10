<?php

namespace App\Http\Requests\Admin;

use App\Http\Responses\AdminApiError;
use App\Platform\Contracts\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A write of the slot checklist (Story 3.12a): re-run, confirm or override. It carries the edit-lock claim a Draft save carries (`lock_epoch`,
 * `lock_token`); an override also carries the `path` of the field, or null to clear the slot. The `admin` middleware has authorised the request.
 */
final class DraftSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = [
            'lock_epoch' => ['required', 'integer', 'min:1'],
            'lock_token' => ['nullable', 'string', 'max:128'],
        ];

        if ($this->isMethod('PUT')) {
            $rules['path'] = ['present', 'nullable', 'string', 'max:1024'];
        }

        return $rules;
    }

    public function slotPath(): ?string
    {
        $path = $this->input('path');

        return is_string($path) ? $path : null;
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

    /** An empty path is not a path: only an explicit null clears the slot. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->isMethod('PUT') && $this->input('path') === '') {
                $v->errors()->add('path', 'Choose a field, or send null to clear the slot.');
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(AdminApiError::json($this, ErrorCode::ValidationFailed->value, 422, errors: $errors));
    }
}
