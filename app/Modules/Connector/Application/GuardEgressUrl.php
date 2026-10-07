<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\AddressClass;
use App\Modules\Connector\Contracts\AllowedHost;
use App\Modules\Connector\Contracts\BlockedAddress;
use App\Modules\Connector\Contracts\Cidr;
use App\Modules\Connector\Contracts\EgressBlockLog;
use App\Modules\Connector\Contracts\EgressGrants;
use App\Modules\Connector\Contracts\EgressGuard;
use App\Modules\Connector\Contracts\EgressReason;
use App\Modules\Connector\Contracts\EgressVerdict;
use App\Modules\Connector\Contracts\HostAllowlist;
use App\Modules\Connector\Contracts\HostResolver;
use App\Modules\Connector\Contracts\InvalidHost;
use App\Modules\Connector\Infrastructure\EgressSettings;

/**
 * EgressGuard (Story 2.2). In this order:
 *  1. the URL is parsed strictly and its scheme must be http or https;
 *  2. the host is read with Story 2.1's AllowedHost rules; a numeric IP spelling (decimal, octal, hex, short forms) is an
 *     IP literal, classified and never resolved, and a spelling that is not the canonical form is never allowed;
 *  3. the host and port must be on the Workspace allowlist;
 *  4. every A and AAAA record (through the HostResolver port) is classified from its `inet_pton` bytes: any undeniable or
 *     unparsable address, or one bad record in a mixed answer, denies the whole request as `blocked_address`; a
 *     grantable private address needs an active grant of this Workspace, else it is `host_not_allowlisted`; a name
 *     that does not resolve is denied.
 * An allowed verdict carries the address that was checked, which the transport pins (the name is never resolved twice).
 * The decision trail holds reason codes and counts, never an address. Allowlist and grants are read as the Workspace (RLS).
 */
final class GuardEgressUrl implements EgressGuard
{
    public function __construct(
        private readonly HostAllowlist $allowlist,
        private readonly HostResolver $resolver,
        private readonly EgressGrants $grants,
        private readonly EgressSettings $settings,
        private readonly EgressBlockLog $blocks,
    ) {}

    public function decide(string $workspaceId, string $url, bool $record = true): EgressVerdict
    {
        $verdict = $this->evaluate($workspaceId, $url);

        if (! $verdict->allowed && $record && $verdict->reason !== null) {
            $this->blocks->record($workspaceId, $verdict->reason, $verdict->host, $verdict->port);
        }

        return $verdict;
    }

    private function evaluate(string $workspaceId, string $url): EgressVerdict
    {
        // Control characters, spaces and backslashes are never part of a URL we send: parsers disagree about them.
        if (preg_match('~\A([A-Za-z][A-Za-z0-9+.-]*)://([^/?#]*)(?:[/?#][^\x00-\x20\x7f\\\\]*)?\z~D', $url, $m) !== 1) {
            return $this->denied(EgressReason::InvalidUrl, '', '', null, ['url:malformed']);
        }

        $scheme = strtolower($m[1]);
        $authority = strtolower($m[2]);

        if ($scheme !== 'http' && $scheme !== 'https') {
            return $this->denied(EgressReason::SchemeNotAllowed, $scheme, '', null, ['scheme:not_http']);
        }

        $trail = ["scheme:{$scheme}"];

        if (preg_match('~\A(?:(\[[0-9a-f:.]{2,45}\])|([a-z0-9.-]{1,253}))(?::([0-9]{1,5}))?\z~D', $authority, $p) !== 1) {
            return $this->denied(EgressReason::InvalidUrl, $scheme, '', null, [...$trail, 'url:bad_authority']);
        }

        $bracketedText = $p[1] ?? '';
        $hostText = $bracketedText !== '' ? $bracketedText : ($p[2] ?? '');
        $portText = ($p[3] ?? '') === '' ? null : $p[3];

        if ($portText !== null && (preg_match('/\A[1-9][0-9]{0,4}\z/D', $portText) !== 1 || (int) $portText > 65535)) {
            return $this->denied(EgressReason::InvalidUrl, $scheme, '', null, [...$trail, 'url:bad_port']);
        }

        $port = $portText === null ? ($scheme === 'https' ? 443 : 80) : (int) $portText;
        $deployment = $this->settings->deploymentCidrs();

        // The host: an IP literal (any spelling) or a name.
        $literal = null;

        if ($bracketedText !== '') {
            $bracketed = substr($hostText, 1, -1);
            $literal = filter_var($bracketed, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? null : (string) inet_pton($bracketed);

            if ($literal === null) {
                return $this->denied(EgressReason::InvalidUrl, $scheme, '', $port, [...$trail, 'host:malformed']);
            }

            $host = '['.inet_ntop($literal).']';
            $trail[] = 'host:ip_literal';
        } elseif (self::numericLastLabel($hostText)) {
            $literal = self::legacyIpv4($hostText);

            if ($literal === null) {
                return $this->denied(EgressReason::InvalidUrl, $scheme, '', $port, [...$trail, 'host:malformed_numeric']);
            }

            $host = (string) inet_ntop($literal);

            if ($host !== $hostText) {
                // An alternate spelling of an address is classified and never resolved, and never reaches the allowlist.
                $reason = BlockedAddress::classify($literal, $deployment) === AddressClass::Undeniable ? EgressReason::BlockedAddress : EgressReason::HostNotAllowlisted;

                return $this->denied($reason, $scheme, $hostText, $port, [...$trail, 'host:numeric_spelling']);
            }

            $trail[] = 'host:ip_literal';
        } else {
            try {
                $host = AllowedHost::parse($hostText, $scheme)->host;
            } catch (InvalidHost) {
                return $this->denied(EgressReason::InvalidUrl, $scheme, '', $port, [...$trail, 'host:invalid']);
            }

            $trail[] = 'host:name';
        }

        // An undeniable literal is refused before the allowlist is even read: no list entry can make it reachable.
        if ($literal !== null && BlockedAddress::classify($literal, $deployment) === AddressClass::Undeniable) {
            return $this->denied(EgressReason::BlockedAddress, $scheme, $host, $port, [...$trail, 'address:undeniable']);
        }

        if (! $this->allowlist->isAllowed($workspaceId, $scheme, $host, $port)) {
            return $this->denied(EgressReason::HostNotAllowlisted, $scheme, $host, $port, [...$trail, 'allowlist:missing']);
        }

        $trail[] = 'allowlist:matched';

        if ($literal !== null) {
            $addresses = [$literal];
        } else {
            $addresses = [];

            foreach ($this->resolver->resolve($host) as $text) {
                // An unparsable record is kept as an empty string, which classifies as undeniable.
                $addresses[] = @inet_pton($text) ?: '';
            }

            if ($addresses === []) {
                return $this->denied(EgressReason::Unresolvable, $scheme, $host, $port, [...$trail, 'dns:no_records']);
            }

            $trail[] = 'dns:records='.count($addresses);
        }

        $private = [];

        foreach ($addresses as $binary) {
            $class = BlockedAddress::classify($binary, $deployment);

            if ($class === AddressClass::Undeniable) {
                return $this->denied(EgressReason::BlockedAddress, $scheme, $host, $port, [...$trail, 'address:undeniable']);
            }

            if ($class === AddressClass::Grantable) {
                $private[] = $binary;
            }
        }

        if ($private !== []) {
            $granted = array_filter(array_map(Cidr::parse(...), $this->grants->activeCidrs($workspaceId)));

            foreach ($private as $binary) {
                if (array_filter($granted, fn (Cidr $grant): bool => $grant->contains($binary)) === []) {
                    return $this->denied(EgressReason::HostNotAllowlisted, $scheme, $host, $port, [...$trail, 'address:private_without_grant']);
                }
            }

            $trail[] = 'address:private_granted';
        } else {
            $trail[] = 'address:public';
        }

        $trail[] = 'pin:checked_record';

        return EgressVerdict::allowed($scheme, $host, $port, (string) inet_ntop($addresses[0]), $trail);
    }

    /** @param  list<string>  $trail */
    private function denied(EgressReason $reason, string $scheme, string $host, ?int $port, array $trail): EgressVerdict
    {
        return EgressVerdict::denied($reason, $scheme, $host, $port, $trail);
    }

    /** A last label of digits, or of `0x` and hex digits, makes the whole host a spelling of an IPv4 address. */
    private static function numericLastLabel(string $host): bool
    {
        $labels = explode('.', $host);

        return preg_match('/\A(?:[0-9]+|0x[0-9a-f]*)\z/D', end($labels)) === 1;
    }

    /** The 4 bytes of a classic `inet_aton` spelling (1 to 4 parts; decimal, octal or hex), or null when it is not one. */
    private static function legacyIpv4(string $host): ?string
    {
        $parts = explode('.', $host);

        if (count($parts) > 4) {
            return null;
        }

        $values = [];

        foreach ($parts as $part) {
            if (preg_match('/\A0x([0-9a-f]{0,8})\z/D', $part, $hex) === 1) {
                $values[] = $hex[1] === '' ? 0 : hexdec($hex[1]);
            } elseif (preg_match('/\A0[0-7]{0,11}\z/D', $part) === 1) {
                $values[] = octdec($part);
            } elseif (preg_match('/\A[1-9][0-9]{0,9}\z/D', $part) === 1) {
                $values[] = (int) $part;
            } else {
                return null;
            }
        }

        $last = array_pop($values);

        foreach ($values as $value) {
            if ($value > 255) {
                return null;
            }
        }

        if ($last >= 256 ** (4 - count($values))) {
            return null;
        }

        $number = $last;

        foreach ($values as $i => $value) {
            $number += $value * (256 ** (3 - $i));
        }

        return pack('N', $number);
    }
}
