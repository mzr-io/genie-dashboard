<?php

namespace App\Modules\Blocks\Contracts;

/**
 * Seals a Draft's sample under the workspace `data` key (Story 3.8). The sealed value names the purpose, the Workspace and the Draft and
 * all are checked on opening, so a ciphertext copied to another Draft or Workspace does not open. With no usable key nothing is sealed.
 */
interface SampleVault
{
    /** @throws SampleUnavailable */
    public function seal(string $workspaceId, string $draftId, #[\SensitiveParameter] string $text): string;

    /** @throws SampleUnavailable */
    public function open(string $workspaceId, string $draftId, string $sealed): string;
}
