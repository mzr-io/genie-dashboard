<?php

namespace App\Modules\Connector\Contracts;

/** Where a refused outbound request is recorded: the security audit event and the SSRF alert count. Never given an address. */
interface EgressBlockLog
{
    public function record(string $workspaceId, EgressReason $reason, ?string $host, ?int $port): void;
}
