<?php

namespace App\Modules\Connector\Contracts;

/**
 * The IP literal classes no allowlist entry may name: loopback, link-local (which holds the cloud metadata
 * addresses), private, CGNAT, unspecified, multicast and reserved IPv4 space, and for IPv6 also IPv4-mapped and
 * -compatible, NAT64, 6to4, Teredo, unique local (ULA) and site-local space. A public literal is allowed.
 *
 * Story 2.2's EgressGuard classifies resolved addresses and supersedes this literal check; it must reuse this rule
 * (one list, so the allowlist and the guard cannot disagree).
 */
final class BlockedAddress
{
    private const IPV4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.168.0.0/16', '224.0.0.0/4', '240.0.0.0/4',
    ];

    private const IPV6 = [
        // ::/96 holds the unspecified and loopback addresses and the deprecated IPv4-compatible range.
        '::/96', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '2002::/16', '2001::/32',
        'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /** True when the textual IPv4 or IPv6 address (no brackets) is in a blocked class; an unparsable one is blocked too. */
    public static function blocks(string $address): bool
    {
        $binary = @inet_pton($address);

        if ($binary === false) {
            return true;
        }

        foreach (strlen($binary) === 4 ? self::IPV4 : self::IPV6 as $cidr) {
            if (self::within($binary, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function within(string $address, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $network = (string) inet_pton($network);
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
