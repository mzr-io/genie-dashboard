<?php

namespace App\Modules\Connector\Contracts;

/**
 * The only way a Data Source is called (AR-26, AD-28): `direct` in the MVP (`agent` later). It takes a {@see FetchRequest}
 * (secret refs and a credential scheme, never a value), resolves each ref at egress, applies the credential scheme and
 * sends through the {@see EgressTransport}, so every hop passes the {@see EgressGuard}. Only `worker-connector` can resolve a
 * secret: on any other role a request that needs one fails with {@see KeyringUnavailable}.
 */
interface FetchTransport
{
    /**
     * @throws SsrfBlocked when the guard denies the URL or a redirect is refused
     * @throws EgressTransportFailed when the connection or the transfer fails
     * @throws SecretMissing|KeyringUnavailable|KeyringMismatch|SecretRefused when a credential cannot be resolved
     */
    public function fetch(FetchRequest $request): FetchResponse;
}
