<?php

namespace App\Http\Requests\Admin;

use App\Http\Responses\AdminApiError;
use App\Platform\Contracts\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A pasted sample to check or store (Story 3.8), or a fetch of one from the API (Story 3.9: the Data Source and Endpoint, the Fixed parameters' values, the dates, the request key, the acknowledgement and the lock claim): the `text` as pasted and, on a store, the "contains personal data" acknowledgement and the
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
        if ($this->routeIs('api.admin.blocks.drafts.sample.fetch')) {
            return [
                'data_source_id' => ['required', 'uuid'],
                'endpoint_id' => ['required', 'uuid'],
                'bindings' => ['nullable', 'array', 'max:100'],
                'bindings.*' => ['nullable', 'string', 'max:2048'],
                // The Default date range is a choice (a preset, or the Admin's own dates for `custom`); the server resolves it in the Workspace time zone.
                'default_date_range' => ['nullable', 'array'],
                'default_date_range.preset' => ['required_with:default_date_range', 'string', 'max:32'],
                'default_date_range.from' => ['nullable', 'string', 'date_format:Y-m-d'],
                'default_date_range.to' => ['nullable', 'string', 'date_format:Y-m-d', 'after_or_equal:default_date_range.from'],
                // Resolved dates are never accepted from a client (AD-27).
                'range' => ['prohibited'],
                'period' => ['prohibited'],
                'request_key' => ['required', 'string', 'max:16384'],
                'contains_personal_data' => ['required', 'boolean'],
                'lock_epoch' => ['required', 'integer', 'min:1'],
                'lock_token' => ['nullable', 'string', 'max:128'],
            ];
        }

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

    public function dataSourceId(): string
    {
        return strtolower((string) $this->input('data_source_id'));
    }

    public function endpointId(): string
    {
        return strtolower((string) $this->input('endpoint_id'));
    }

    /** @return array<string, string|null> */
    public function bindings(): array
    {
        $out = [];

        foreach (is_array($this->input('bindings')) ? $this->input('bindings') : [] as $name => $value) {
            $out[(string) $name] = is_string($value) ? $value : null;
        }

        return $out;
    }

    /** @return array{preset: string, from?: string, to?: string}|null the form's Default date range choice */
    public function defaultDateRange(): ?array
    {
        $range = $this->input('default_date_range');

        if (! is_array($range) || ! is_string($range['preset'] ?? null)) {
            return null;
        }

        return ['preset' => $range['preset']]
            + (is_string($range['from'] ?? null) ? ['from' => $range['from']] : [])
            + (is_string($range['to'] ?? null) ? ['to' => $range['to']] : []);
    }

    public function requestKey(): string
    {
        return (string) $this->input('request_key');
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
