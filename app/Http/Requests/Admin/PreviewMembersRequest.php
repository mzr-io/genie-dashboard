<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The list of members the wizard's "Fetch as user" can target (Story 3.17), optionally narrowed by `search`. Listing who may be previewed
 * is itself part of previewing as a user, so it needs `data.preview_as_user`: without it a 403 and a security event, as on the fetch.
 */
final class PreviewMembersRequest extends FormRequest
{
    use ChecksPreviewAsUser;

    public function authorize(): bool
    {
        return $this->mayPreviewAsUser();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:100']];
    }

    public function search(): ?string
    {
        $search = $this->query('search');

        return is_string($search) && $search !== '' ? mb_substr($search, 0, 100) : null;
    }
}
