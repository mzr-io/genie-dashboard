<?php

namespace App\Modules\Blocks\Contracts;

/** A Draft's preview: the Shaper's payload, why each unavailable slot is unavailable, and the sample revision it was computed from. */
final readonly class DraftPreview
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $unavailable  slot key => `missing`, `not_single_value` or `wrong_type`
     */
    public function __construct(public array $payload, public array $unavailable, public int $sampleRevision) {}
}
