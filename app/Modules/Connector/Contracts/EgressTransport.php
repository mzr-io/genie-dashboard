<?php

namespace App\Modules\Connector\Contracts;

/**
 * The only way Dashflow sends a request to an outside host (Story 2.2): every URL, and every redirect hop, goes through
 * the EgressGuard first, and the connection is pinned to the address the guard checked. Proxy environment variables are
 * ignored. Redirects are never followed by the HTTP client itself.
 */
interface EgressTransport
{
    /**
     * @throws SsrfBlocked when the guard denies the URL or a redirect is refused
     * @throws EgressTransportFailed when the connection or the transfer fails
     */
    public function send(string $workspaceId, EgressRequest $request): EgressResponse;
}
