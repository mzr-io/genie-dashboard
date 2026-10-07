<?php

namespace App\Http\Requests\Admin;

use App\Modules\Access\Contracts\GroupQuery;
use App\Modules\Access\Contracts\GroupSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The query string of the group list: `q` (a search over names), `sort` (a whitelist: `name`, `members`, `created`;
 * anything else falls back to `name`) and `direction`. The `admin` middleware has already authorised the request.
 */
final class ListGroupsRequest extends FormRequest
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
            'direction' => ['nullable', 'string', 'max:10'],
        ];
    }

    public function groupQuery(): GroupQuery
    {
        $search = $this->input('q');

        return new GroupQuery(
            search: is_string($search) && trim($search) !== '' ? trim($search) : null,
            sort: GroupSort::fromInput($this->input('sort')),
            descending: $this->input('direction') === 'desc',
        );
    }
}
