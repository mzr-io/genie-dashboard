<?php

namespace App\Http\Requests\Admin;

use App\Modules\Connector\Contracts\AllowlistQuery;
use App\Modules\Connector\Contracts\AllowlistSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The query string of the host allowlist: `q` (a search over hosts), `sort` (a whitelist: `host`, `scheme`, `port`,
 * `added`; anything else falls back to `host`) and `direction`. The `admin` middleware has authorised the request.
 */
final class ListHostAllowlistRequest extends FormRequest
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
            'sort' => ['nullable', 'string', 'max:30'],
            'direction' => ['nullable', 'in:asc,desc'],
        ];
    }

    public function allowlistQuery(): AllowlistQuery
    {
        $search = $this->input('q');

        return new AllowlistQuery(
            search: is_string($search) && trim($search) !== '' ? trim($search) : null,
            sort: AllowlistSort::fromInput($this->input('sort')),
            descending: $this->input('direction') === 'desc',
        );
    }
}
