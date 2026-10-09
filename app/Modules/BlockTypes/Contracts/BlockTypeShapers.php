<?php

namespace App\Modules\BlockTypes\Contracts;

/** Runs the Shaper of a registered Block Type package: the pure function from slot values to the render payload (Story 3.10). */
interface BlockTypeShapers
{
    /**
     * @param  array<string, mixed>  $slots  slot key => value (measures as decimal strings), null for a slot with no value
     * @param  array<string, mixed>  $options  the Shaper's options (state, formats, ...)
     * @return array<string, mixed> the render payload
     *
     * @throws UnknownBlockType the package has no Shaper registered
     */
    public function shape(BlockType $type, array $slots, array $options = []): array;
}
