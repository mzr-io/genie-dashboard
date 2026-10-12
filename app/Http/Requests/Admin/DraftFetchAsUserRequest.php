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
            'range' => ['nullable', 'array'],
            'range.from' => ['required_with:range', 'string', 'date_format:Y-m-d'],
            'range.to' => ['required_with:range', 'string', 'date_format:Y-m-d', 'after_or_equal:range.from'],
            'period' => ['nullable', 'array'],
            'period.start' => ['required_with:period', 'string', 'date_format:Y-m-d'],
            'period.end' => ['required_with:period', 'string', 'date_format:Y-m-d', 'after_or_equal:period.start'],
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

    /** @return array{from: string, to: string}|null */
    public function range(): ?array
    {
        $range = $this->input('range');

        return is_array($range) && isset($range['from'], $range['to']) ? ['from' => (string) $range['from'], 'to' => (string) $range['to']] : null;
    }

    /** @return array{start: string, end: string}|null */
    public function period(): ?array
    {
        $period = $this->input('period');

        return is_array($period) && isset($period['start'], $period['end']) ? ['start' => (string) $period['start'], 'end' => (string) $period['end']] : null;
    }

    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(AdminApiError::json($this, ErrorCode::ValidationFailed->value, 422, errors: $errors));
    }
}
