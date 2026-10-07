<?php

namespace App\Modules\Connector\Contracts;

/** Why an outbound URL was refused. The value is the audit `reason` and never carries an address. */
enum EgressReason: string
{
    case HostNotAllowlisted = 'host_not_allowlisted';
    case BlockedAddress = 'blocked_address';
    case SchemeNotAllowed = 'scheme_not_allowed';
    case InvalidUrl = 'invalid_url';
    case Unresolvable = 'unresolvable';
    case RedirectRefused = 'redirect_refused';

    /** The message catalogue key an Admin sees (`msg:host-not-allowlisted` or `msg:blocked-address`). */
    public function messageKey(): string
    {
        return $this === self::BlockedAddress ? 'blocked-address' : 'host-not-allowlisted';
    }
}
