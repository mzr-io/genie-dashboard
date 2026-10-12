<?php

namespace App\Modules\Blocks\Contracts;

/**
 * A Block's render payload with generated sample values (Story 4.8), for the Add-blocks Panel's preview. The values are synthesised from the published
 * version's field snapshot and presentation alone: nothing is read from a source, a payload or a result, and nothing is stored or subscribed. The same
 * version always gives the same values. Read-only, in the caller's Workspace transaction.
 */
interface BlockSamples
{
    /**
     * @return array{type: string, version: string, render_payload: array<string, mixed>}|null null when the Block has no published version or no sample
     *                                                                                         could be made for it (the caller shows a labelled placeholder)
     */
    public function forBlock(string $workspaceId, string $blockId): ?array;
}
