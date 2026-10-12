<?php

namespace App\Modules\Blocks\Contracts;

/** A Draft's preview: the Shaper's payload, why each unavailable slot is unavailable, and the sample revision it was computed from, with the Draft's revision (Story 3.13c) and, per mapped slot, its rendered value (`slots`: slot => `{value, path, unavailable?}`; a decimal string, bounded text or a boolean). `period` is the Block default's resolved period (`{token, start, end}`, Workspace-local dates) or null when the Draft has none (Story 4.1). `asUserExpiresAt` is set when it was computed from a Fetch as user result (Story 3.17) and says when that result ends. */
final readonly class DraftPreview
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $unavailable  slot key => `missing`, `not_single_value` or `wrong_type`
     * @param  array<string, array<string, mixed>>  $slots
     * @param  array<string, mixed>|null  $period
     */
    public function __construct(public array $payload, public array $unavailable, public int $sampleRevision, public int $draftRevision = 0, public array $slots = [], public bool $planStale = false, public ?string $asUserExpiresAt = null, public ?array $period = null) {}
}
