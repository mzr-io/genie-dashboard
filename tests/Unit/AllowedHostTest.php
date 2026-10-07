<?php

use App\Modules\Connector\Contracts\AllowedHost;
use App\Modules\Connector\Contracts\BlockedAddress;
use App\Modules\Connector\Contracts\HostProblem;
use App\Modules\Connector\Contracts\InvalidHost;

// Story 2.1: the host allowlist's validation table. Every refusal names its reason; nothing is trimmed or repaired.

function problemOf(mixed $input, mixed $scheme = null): ?HostProblem
{
    try {
        AllowedHost::parse($input, $scheme);
    } catch (InvalidHost $e) {
        return $e->problem;
    }

    return null;
}

it('accepts a host and resolves its port from the scheme', function (string $input, ?string $scheme, string $host, string $storedScheme, int $port) {
    $parsed = AllowedHost::parse($input, $scheme);

    expect([$parsed->host, $parsed->scheme, $parsed->port])->toBe([$host, $storedScheme, $port]);
})->with([
    'plain host defaults to https 443' => ['api.example.com', null, 'api.example.com', 'https', 443],
    'http resolves 80' => ['api.example.com', 'http', 'api.example.com', 'http', 80],
    'explicit port' => ['api.example.com:8443', 'https', 'api.example.com', 'https', 8443],
    'lower-cased' => ['API.Example.COM', null, 'api.example.com', 'https', 443],
    'punycode label' => ['xn--bcher-kva.example', null, 'xn--bcher-kva.example', 'https', 443],
    'inner hyphens and digits' => ['a-1.b2-c.example', null, 'a-1.b2-c.example', 'https', 443],
    'single label' => ['intranet', null, 'intranet', 'https', 443],
    'public IPv4' => ['93.184.216.34', null, '93.184.216.34', 'https', 443],
    'public IPv4 with port' => ['93.184.216.34:8080', 'http', '93.184.216.34', 'http', 8080],
    'bracketed public IPv6' => ['[2606:4700:4700::1111]', null, '[2606:4700:4700::1111]', 'https', 443],
    'bracketed IPv6 is canonical' => ['[2606:4700:4700:0:0:0:0:1111]:8443', null, '[2606:4700:4700::1111]', 'https', 8443],
    'highest port' => ['example.com:65535', null, 'example.com', 'https', 65535],
    'lowest port' => ['example.com:1', null, 'example.com', 'https', 1],
    'label of 63 characters' => [str_repeat('a', 63).'.example', null, str_repeat('a', 63).'.example', 'https', 443],
]);

it('refuses a value that is not a plain host or host:port, with the reason', function (mixed $input, HostProblem $problem) {
    expect(problemOf($input))->toBe($problem);
})->with([
    'null' => [null, HostProblem::Empty],
    'empty' => ['', HostProblem::Empty],
    'not a string' => [['a.example'], HostProblem::Empty],
    'space' => ['a.example ', HostProblem::Whitespace],
    'leading space' => [' a.example', HostProblem::Whitespace],
    'inner space' => ['a b.example', HostProblem::Whitespace],
    'tab' => ["a.example\t", HostProblem::Whitespace],
    'newline' => ["a.example\n", HostProblem::Whitespace],
    'nul' => ["a.example\0", HostProblem::Whitespace],
    'no-break space' => ["a.example\u{00A0}", HostProblem::Whitespace],
    'only spaces' => ['   ', HostProblem::Whitespace],
    'scheme' => ['https://a.example', HostProblem::ForbiddenCharacter],
    'path' => ['a.example/path', HostProblem::ForbiddenCharacter],
    'backslash' => ['a.example\\path', HostProblem::ForbiddenCharacter],
    'query' => ['a.example?x=1', HostProblem::ForbiddenCharacter],
    'fragment' => ['a.example#x', HostProblem::ForbiddenCharacter],
    'userinfo' => ['user@a.example', HostProblem::ForbiddenCharacter],
    'wildcard' => ['*.example.com', HostProblem::ForbiddenCharacter],
    'bare star' => ['*', HostProblem::ForbiddenCharacter],
    'percent' => ['a%2eexample', HostProblem::ForbiddenCharacter],
    'zone id' => ['[fe80::1%eth0]', HostProblem::ForbiddenCharacter],
    'non-ascii' => ['bücher.example', HostProblem::NonAscii],
    'invalid utf-8' => ["a\xFF.example", HostProblem::NonAscii],
    'leading hyphen' => ['-a.example', HostProblem::InvalidLabel],
    'trailing hyphen' => ['a-.example', HostProblem::InvalidLabel],
    'underscore' => ['a_b.example', HostProblem::InvalidLabel],
    'empty label' => ['a..example', HostProblem::InvalidLabel],
    'trailing dot' => ['a.example.', HostProblem::InvalidLabel],
    'leading dot' => ['.example', HostProblem::InvalidLabel],
    'label over 63' => [str_repeat('a', 64).'.example', HostProblem::TooLong],
    'name over 253' => [implode('.', array_fill(0, 64, 'abcd')), HostProblem::TooLong],
    'empty host with port' => [':443', HostProblem::Malformed],
    'unbracketed IPv6' => ['2606:4700::1111', HostProblem::Malformed],
    'unclosed bracket' => ['[2606:4700::1111', HostProblem::Malformed],
    'bracketed non-IPv6' => ['[example.com]', HostProblem::Malformed],
    'bracketed IPv4' => ['[93.184.216.34]', HostProblem::Malformed],
    'text after bracket' => ['[2606:4700::1111]x', HostProblem::Malformed],
    'empty port' => ['a.example:', HostProblem::InvalidPort],
    'port zero' => ['a.example:0', HostProblem::InvalidPort],
    'port too high' => ['a.example:65536', HostProblem::InvalidPort],
    'port text' => ['a.example:https', HostProblem::InvalidPort],
    'port with sign' => ['a.example:+443', HostProblem::InvalidPort],
    'port with leading zero' => ['a.example:0443', HostProblem::InvalidPort],
    'port too long' => ['a.example:123456', HostProblem::InvalidPort],
    'two ports' => ['a.example:80:80', HostProblem::Malformed],
]);

it('refuses a numeric spelling of an IP other than canonical dotted-quad IPv4', function (string $input) {
    expect(problemOf($input))->toBe(HostProblem::NumericAddress);
})->with([
    'integer' => ['2130706433'],
    'short form' => ['127.1'],
    'three parts' => ['10.0.1'],
    'octal' => ['0177.0.0.1'],
    'hex' => ['0x7f.0.0.1'],
    'whole hex' => ['0x7f000001'],
    'leading zeros' => ['093.184.216.034'],
    'out of range' => ['256.1.1.1'],
    'five parts' => ['1.2.3.4.5'],
    'digits-only name' => ['12345'],
]);

it('refuses an IP literal in a blocked class and allows a public one', function (string $input) {
    expect(problemOf($input))->toBe(HostProblem::BlockedAddress);
})->with([
    'loopback' => ['127.0.0.1'],
    'loopback elsewhere' => ['127.255.255.254'],
    'unspecified' => ['0.0.0.0'],
    'this network' => ['0.1.2.3'],
    'private 10' => ['10.1.2.3'],
    'private 172' => ['172.16.0.1'],
    'private 172 top' => ['172.31.255.255'],
    'private 192' => ['192.168.1.1'],
    'cgnat' => ['100.64.0.1'],
    'cgnat top' => ['100.127.255.255'],
    'link-local' => ['169.254.1.1'],
    'cloud metadata' => ['169.254.169.254'],
    'alibaba metadata (cgnat)' => ['100.100.100.200'],
    'multicast' => ['224.0.0.1'],
    'reserved' => ['240.0.0.1'],
    'broadcast' => ['255.255.255.255'],
    'ietf protocol assignments' => ['192.0.0.192'],
    'IPv6 loopback' => ['[::1]'],
    'IPv6 unspecified' => ['[::]'],
    'IPv6 link-local' => ['[fe80::1]'],
    'IPv6 site-local' => ['[fec0::1]'],
    'IPv6 ULA' => ['[fd12:3456:789a::1]'],
    'IPv6 ULA fc' => ['[fc00::1]'],
    'IPv6 metadata (ULA)' => ['[fd00:ec2::254]'],
    'IPv6 multicast' => ['[ff02::1]'],
    'IPv4-mapped' => ['[::ffff:8.8.8.8]'],
    'IPv4-mapped loopback' => ['[::ffff:127.0.0.1]'],
    'IPv4-compatible' => ['[::8.8.8.8]'],
    'NAT64' => ['[64:ff9b::8.8.8.8]'],
    'NAT64 local-use' => ['[64:ff9b:1::1]'],
    '6to4' => ['[2002:808:808::1]'],
    'Teredo' => ['[2001:0:4136:e378:8000:63bf:3fff:fdd2]'],
    'blocked with a port' => ['10.0.0.1:8443'],
    'IPv6 with a port' => ['[::1]:8443'],
]);

it('allows public literals just outside the blocked ranges', function (string $input) {
    expect(problemOf($input))->toBeNull();
})->with([
    ['100.63.255.255'], ['100.128.0.0'], ['172.15.255.255'], ['172.32.0.0'], ['169.253.1.1'], ['169.255.1.1'],
    ['223.255.255.255'], ['11.0.0.1'], ['126.255.255.255'], ['128.0.0.1'], ['192.0.1.1'], ['192.169.0.1'],
    ['[2001:4860:4860::8888]'], ['[2606:4700:4700::1111]'],
]);

it('refuses a scheme other than http and https', function (mixed $scheme) {
    expect(problemOf('a.example', $scheme))->toBe(HostProblem::InvalidScheme)
        ->and(HostProblem::InvalidScheme->field())->toBe('scheme');
})->with([['ftp'], ['HTTPS'], [''], [['https']], [443]]);

it('reports the same verdict through BlockedAddress that the entry validation uses', function () {
    expect(BlockedAddress::blocks('10.0.0.1'))->toBeTrue()
        ->and(BlockedAddress::blocks('93.184.216.34'))->toBeFalse()
        ->and(BlockedAddress::blocks('::ffff:10.0.0.1'))->toBeTrue()
        ->and(BlockedAddress::blocks('not an address'))->toBeTrue();
});
