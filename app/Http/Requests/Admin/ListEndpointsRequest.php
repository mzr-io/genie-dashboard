<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The query string of the Endpoint list: `q`, a search over the path and the method. The `admin` middleware has authorised the request.
 */
final class ListEndpointsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string|\Closure>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && (str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8'))) {
                    $fail('The :attribute contains characters that are not allowed.');
                }
            }],
        ];
    }

    public function search(): ?string
    {
        $search = $this->input('q');

        return is_string($search) && trim($search) !== '' ? trim($search) : null;
    }
}
