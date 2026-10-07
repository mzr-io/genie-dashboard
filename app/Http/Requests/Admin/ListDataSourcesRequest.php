<?php

namespace App\Http\Requests\Admin;

use App\Modules\Connector\Contracts\DataSourceQuery;
use App\Modules\Connector\Contracts\DataSourceSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The query string of the Data source list: `q` (a search over names and hosts), `sort` (a whitelist: `name`, `host`,
 * `auth_type`; anything else falls back to `name`) and `direction`. The `admin` middleware has authorised the request.
 */
final class ListDataSourcesRequest extends FormRequest
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

    public function dataSourceQuery(): DataSourceQuery
    {
        $search = $this->input('q');

        return new DataSourceQuery(
            search: is_string($search) && trim($search) !== '' ? trim($search) : null,
            sort: DataSourceSort::fromInput($this->input('sort')),
            descending: $this->input('direction') === 'desc',
        );
    }
}
