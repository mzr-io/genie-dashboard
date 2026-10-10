<?php

namespace App\Http\Requests\Admin;

use App\Http\Responses\AdminApiError;
use App\Platform\Contracts\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A write of the slot checklist (Story 3.12a): re-run, confirm, override or undo (the `undo` payload an override returned). It carries the edit-lock claim a Draft save carries (`lock_epoch`,
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

        if ($this->routeIs('api.admin.blocks.drafts.mapping.undo')) {
            $rules += [
                'undo' => ['required', 'array'],
                'undo.slot' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_]{0,63}\z/'],
                'undo.slot_entry' => ['present', 'nullable', 'array'],
                'undo.slot_entry.path' => ['nullable', 'string', 'max:1024'],
                'undo.slot_entry.state' => ['required_with:undo.slot_entry', 'string', 'in:confirmed,overridden'],
                'undo.applied' => ['required', 'array'],
                'undo.applied.path' => ['nullable', 'string', 'max:1024'],
                'undo.applied.state' => ['required', 'string', 'in:overridden'],
                'undo.role' => ['present', 'nullable', 'array'],
                'undo.role.path' => ['required_with:undo.role', 'string', 'max:1024'],
                'undo.role.previous' => ['nullable', 'string', 'max:32'],
            ];
        }

        return $rules;
    }

    /**
     * The undo payload an override returned (Story 3.13a), as the service takes it.
     *
     * @return array<string, mixed>
     */
    public function undoPayload(): array
    {
        $undo = $this->input('undo');

        return is_array($undo) ? $undo : [];
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

            // A cleared slot is `{path: null, state}`: the path key must be there, null or not; an empty object is no entry.
            if ($this->routeIs('api.admin.blocks.drafts.mapping.undo') && is_array($this->input('undo'))) {
                foreach (['slot_entry', 'applied', 'role'] as $key) {
                    $value = $this->input("undo.{$key}");

                    if ($value === [] || (is_array($value) && in_array($key, ['slot_entry', 'applied'], true) && ! array_key_exists('path', $value))) {
                        $v->errors()->add("undo.{$key}", 'This part of the undo is incomplete.');
                    }
                }
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
