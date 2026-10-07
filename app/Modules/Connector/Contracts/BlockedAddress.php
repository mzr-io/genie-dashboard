<?php

namespace App\Modules\Connector\Contracts;

/**
 * The one address classifier of the Connector (Stories 2.1 and 2.2): the allowlist refuses any literal that is not
 * public, and EgressGuard classifies every resolved address with the same lists, so the two cannot disagree.
 *
 * Classes, decided on the `inet_pton` bytes and never on text:
 *  - undeniable: loopback, link-local (which holds the metadata address 169.254.169.254), the IPv6 metadata address
 *    fd00:ec2::254, unspecified and the IPv4-compatible range, multicast, IPv4-mapped, NAT64, 6to4, Teredo, site-local,
 *    reserved IPv4 space, documentation and benchmarking ranges and the deployment's own CIDRs (passed in);
 *  - grantable: RFC 1918, CGNAT and unique local (ULA) space;
 *  - public: everything else.
 * Undeniable wins over grantable (the IPv6 metadata address sits inside ULA space).
 */
final class BlockedAddress
{
    private const UNDENIABLE = [
        // IPv4: "this network" and unspecified, loopback, link-local and metadata, IETF protocol assignments,
        // documentation (TEST-NET-1/2/3), the 6to4 relay anycast range, benchmarking, multicast, reserved and broadcast.
        '0.0.0.0/8', '127.0.0.0/8', '169.254.0.0/16', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        // Cloud metadata outside link-local: Alibaba Cloud (inside CGNAT, which is grantable) and the Azure wireserver (public space).
        '100.100.100.200/32', '168.63.129.16/32',
        // IPv6: ::/96 holds the unspecified and loopback addresses and the deprecated IPv4-compatible range.
        '::/96', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/32', '2001:2::/48',
        '2001:db8::/32', '2002::/16', '3fff::/20', 'fd00:ec2::254/128', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    private const GRANTABLE = ['10.0.0.0/8', '100.64.0.0/10', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7'];

    /** @var list<Cidr>|null */
    private static ?array $undeniable = null;

    /** @var list<Cidr>|null */
    private static ?array $grantable = null;

    /** True when the textual IPv4 or IPv6 address (no brackets) is not public; an unparsable one is blocked too. */
    public static function blocks(string $address): bool
    {
        return self::classifyText($address) !== AddressClass::Public;
    }

    /** @param  list<Cidr>  $deployment  the deployment's own networks, undeniable like the built-in ones */
    public static function classifyText(string $address, array $deployment = []): AddressClass
    {
        $binary = @inet_pton($address);

        return $binary === false ? AddressClass::Undeniable : self::classify($binary, $deployment);
    }

    /**
     * @param  string  $binary  4 or 16 bytes; anything else is undeniable
     * @param  list<Cidr>  $deployment
     */
    public static function classify(string $binary, array $deployment = []): AddressClass
    {
        if (strlen($binary) !== 4 && strlen($binary) !== 16) {
            return AddressClass::Undeniable;
        }

        foreach ([...self::undeniable(), ...$deployment] as $range) {
            if ($range->contains($binary)) {
                return AddressClass::Undeniable;
            }
        }

        foreach (self::grantable() as $range) {
            if ($range->contains($binary)) {
                return AddressClass::Grantable;
            }
        }

        return AddressClass::Public;
    }

    /** @return list<Cidr> */
    public static function undeniable(): array
    {
        return self::$undeniable ??= array_map(fn (string $cidr): Cidr => self::cidr($cidr), self::UNDENIABLE);
    }

    /** @return list<Cidr> */
    public static function grantable(): array
    {
        return self::$grantable ??= array_map(fn (string $cidr): Cidr => self::cidr($cidr), self::GRANTABLE);
    }

    private static function cidr(string $text): Cidr
    {
        return Cidr::parse($text) ?? throw new \LogicException("Bad built-in range {$text}.");
    }
}
