<?php

namespace App\Modules\Connector\Contracts;

/**
 * The only things an Admin is told about a failed connection test: three from Story 2.5 and, since Story 2.6, `not-json` and `response-too-large` and, since Story 2.7, `auth-failed` and, since Story 2.11, `too-many-pages`. The value is the message catalogue
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
    case AuthFailed = 'auth-failed';
    /** A Fetch as user (Story 2.13) had no value for a bound attribute or group, or one that cannot be sent; no request was made. Its message is a label. */
    case ContextMissing = 'access.context_missing';
    /** A paged run would need more pages than the cap allows (Story 2.11); its message is a label, the catalogue being closed. */
    case TooManyPages = 'too-many-pages';

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
