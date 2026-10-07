<?php

namespace App\Http\Requests\Admin;

use App\Modules\Access\Contracts\MemberQuery;
use App\Modules\Access\Contracts\MemberSort;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The query string of the user list. The `admin` middleware has already authorised the request. The sort column
 * is a whitelist (an unknown one is ignored, the default applies) and the page size is capped by the tunable
 * `lists.max_page_size`.
 */
final class ListMembersRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:100', $this->cleanText(...)],
            'sort' => ['nullable', 'string', 'max:30'],
            'direction' => ['nullable', 'string', 'max:10'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'cursor' => ['nullable', 'string', 'max:512', $this->cleanText(...)],
        ];
    }

    /** Rejects a NUL byte or invalid UTF-8, which PostgreSQL cannot take in text. */
    private function cleanText(string $attribute, mixed $value, \Closure $fail): void
    {
        if (is_string($value) && (str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8'))) {
            $fail('The :attribute contains characters that are not allowed.');
        }
    }

    public function memberQuery(): MemberQuery
    {
        $search = $this->input('q');
        $cursor = $this->input('cursor');

        return new MemberQuery(
            search: is_string($search) && trim($search) !== '' ? trim($search) : null,
            sort: MemberSort::fromInput($this->input('sort')),
            descending: $this->input('direction') === 'desc',
            pageSize: $this->pageSize(),
            cursor: is_string($cursor) && $cursor !== '' ? $cursor : null,
        );
    }

    /**
     * The framework's default while the cap is unset (a requested size is then ignored); with a cap, the
     * requested size (default 15) clamped to it.
     */
    public function pageSize(): int
    {
        $cap = config('dashflow.tunables.lists.max_page_size.value');

        if (! is_scalar($cap) || ! ctype_digit((string) $cap) || strlen((string) $cap) > 9 || (int) $cap < 1) {
            return MemberQuery::DEFAULT_PAGE_SIZE;
        }

        $requested = $this->filled('per_page') ? $this->integer('per_page') : MemberQuery::DEFAULT_PAGE_SIZE;

        return max(1, min($requested, (int) $cap));
    }
}
