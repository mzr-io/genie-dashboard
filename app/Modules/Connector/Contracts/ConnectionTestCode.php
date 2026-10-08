<?php

namespace App\Modules\Connector\Contracts;

/**
 * The only things an Admin is told about a failed connection test: three from Story 2.5 and, since Story 2.6, `not-json` and `response-too-large`. The value is the message catalogue
 * key. Everything finer (the HTTP status, the host, the reason code) is detail for the requester's technical disclosure and
 * the operator log, and never includes a resolved address.
 */
enum ConnectionTestCode: string
{
    case HostNotAllowlisted = 'host-not-allowlisted';
    case BlockedAddress = 'blocked-address';
    case FetchFailed = 'fetch-failed';
    case NotJson = 'not-json';
    case ResponseTooLarge = 'response-too-large';

    /** The user code for a refusal by the guard or the transport: only the two allowlist and address reasons keep their own. */
    public static function forEgress(EgressReason $reason): self
    {
        return match ($reason) {
            EgressReason::HostNotAllowlisted => self::HostNotAllowlisted,
            EgressReason::BlockedAddress => self::BlockedAddress,
            default => self::FetchFailed,
        };
    }
}
