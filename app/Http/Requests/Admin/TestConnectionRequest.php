<?php

namespace App\Http\Requests\Admin;

/**
 * The form of a Data Source to test (Story 2.5): the same fields and the same validation as register and update, without the
 * revision and without the unique-name rule (that one needs the database and belongs to saving), plus the optional
 * `data_source_id` of the saved Data Source the form edits. Typed secret values are accepted here: they are sealed into
 * transient rows of the Operation and never stored on the Data Source.
 */
final class TestConnectionRequest extends DataSourceRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['data_source_id' => ['nullable', 'uuid']];
    }

    public function dataSourceId(): ?string
    {
        $id = $this->input('data_source_id');

        return is_string($id) && $id !== '' ? strtolower($id) : null;
    }
}
