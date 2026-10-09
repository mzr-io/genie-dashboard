<?php

namespace App\Modules\Mapping\Contracts;

use App\Platform\Json\SampleShape;

/** Field roles and record-path candidates of a sample (Story 3.11a): pure, over the structure {@see SampleShape::of()} made. */
interface FieldRoles
{
    /**
     * @param  array<string, mixed>  $shape  a `SampleShape::of()` result
     * @param  string|null  $recordPath  the Admin's chosen record path (`config.record_path`), or null to use the suggested one
     * @param  array<string, string>  $overrides  stored path => role value (`config.field_roles`); entries with an unknown role are ignored
     */
    public function infer(array $shape, ?string $recordPath, array $overrides): FieldRoleReport;
}
