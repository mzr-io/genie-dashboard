<?php

namespace App\Http\Requests\Admin;

use App\Http\Responses\AdminApiError;
use App\Platform\Contracts\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A validation of a Draft against the real API (Story 3.18, wizard step 5): the edit-lock claim of a Draft save (`lock_epoch`, `lock_token`) and the
 * dates the default range and the Block's period stand for. The Data Source, the Endpoint and the Fixed parameters are the Draft's own and are
 * never taken from the client. The `admin` middleware has authorised the request (`blocks.edit`).
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
            'range' => ['nullable', 'array'],
            'range.from' => ['required_with:range', 'string', 'date_format:Y-m-d'],
            'range.to' => ['required_with:range', 'string', 'date_format:Y-m-d', 'after_or_equal:range.from'],
            'period' => ['nullable', 'array'],
            'period.start' => ['required_with:period', 'string', 'date_format:Y-m-d'],
            'period.end' => ['required_with:period', 'string', 'date_format:Y-m-d', 'after_or_equal:period.start'],
            'lock_epoch' => ['required', 'integer', 'min:1'],
            'lock_token' => ['nullable', 'string', 'max:128'],
        ];
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
