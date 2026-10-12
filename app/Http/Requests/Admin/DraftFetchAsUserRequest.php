<?php

namespace App\Http\Requests\Admin;

use App\Http\Responses\AdminApiError;
use App\Platform\Contracts\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/**
 * A Fetch as user from the Create block wizard (Story 3.17): `membership` (the member whose data is fetched), the Data Source and Endpoint,
 * the Fixed parameters' values and the dates, as a Draft's Fetch from API carries them. The `admin` middleware has run (`blocks.edit`);
 * `authorize()` adds `data.preview_as_user`: without it the request is a 403 and a security event is recorded before any field is read. A
 * value for a user-bound name is never accepted, because no field of the body can carry one.
 */
final class DraftFetchAsUserRequest extends FormRequest
{
    use ChecksPreviewAsUser;

    public function authorize(): bool
    {
        return $this->mayPreviewAsUser();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'membership' => ['required', 'string', 'uuid'],
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
        ];
    }

    public function membership(): string
    {
        return Str::lower((string) $this->input('membership'));
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

    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(AdminApiError::json($this, ErrorCode::ValidationFailed->value, 422, errors: $errors));
    }
}
