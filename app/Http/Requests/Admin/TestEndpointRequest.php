<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The body of an Endpoint test (Story 2.10): `values`, a map of parameter name to test value (a header bound to a date is keyed
 * `header:{name}`). The client sends values only; the server renders the request from the Endpoint's current revision and
 * checks each value with the rules of a save, so a refusal is a 422 naming the parameter. Nothing is trimmed or repaired. The
 * `admin` middleware has already authorised the request (`data_sources.manage`).
 */
final class TestEndpointRequest extends FormRequest
{
    public const MAX_VALUES = 100;

    public const KEY_MAX = 128;

    public const VALUE_MAX = 2048;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        // Bounded: an Endpoint has at most 25 parameters and 25 headers; unknown keys are ignored but still capped.
        return [
            'values' => ['nullable', 'array', 'max:'.self::MAX_VALUES],
            'values.*' => ['nullable', 'string', 'max:'.self::VALUE_MAX],
        ];
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_keys($this->values()) as $key) {
                if (strlen((string) $key) > self::KEY_MAX) {
                    $validator->errors()->add('values', 'The test values are not valid.');

                    return;
                }
            }
        }];
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        $values = $this->input('values');

        return is_array($values) ? $values : [];
    }
}
