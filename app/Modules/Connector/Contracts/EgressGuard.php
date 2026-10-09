<?php

namespace App\Modules\Connector\Contracts;

/**
 * Decides every outbound URL (Story 2.2), from the Workspace allowlist and the binary form of every resolved A and AAAA
 * record. It sends nothing. A denial is error code `connector.ssrf_blocked`.
 */
interface EgressGuard
{
    /**
     * @param  bool  $record  false for a dry run (the operator's check): no audit event and no alert count
     */
    public function decide(string $workspaceId, string $url, bool $record = true): EgressVerdict;
}
