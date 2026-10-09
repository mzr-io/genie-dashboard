<?php

use App\Modules\Connector\Application\GuardEgressUrl;
use App\Modules\Connector\Contracts\EgressReason;
use App\Modules\Connector\Contracts\ErrorCode;
use App\Modules\Connector\Infrastructure\EgressSettings;
use Illuminate\Config\Repository;
use Tests\Unit\Support\FakeAllowlist;
use Tests\Unit\Support\FakeGrants;
use Tests\Unit\Support\FakeResolver;
use Tests\Unit\Support\RecordingBlocks;

// Story 2.2: the guard's decision table against fakes (no network, no database).

const EG_A = '018f0000-0000-7000-8000-00000000000a';
const EG_B = '018f0000-0000-7000-8000-00000000000b';

/** @param  array<string, list<string>>  $dns */
function guardFor(array $dns, array $allow = ['api.example.com:443', 'api.example.com:8443', 'plain.example.com:80', '93.184.216.34:443', '[2606:4700:4700::1111]:443'], array $grants = [], ?string $deployment = null, ?RecordingBlocks $blocks = null, ?FakeResolver $resolver = null): GuardEgressUrl
{
    $config = new Repository(['dashflow' => ['egress' => ['deployment_cidrs' => ['value' => $deployment]]]]);

    return new GuardEgressUrl(
        new FakeAllowlist([EG_A => $allow, EG_B => $allow]),
        $resolver ?? new FakeResolver($dns),
        new FakeGrants($grants),
        new EgressSettings($config),
        $blocks ?? new RecordingBlocks,
    );
}

it('allows an allowlisted host whose records are all public, pins the checked address and sends nothing', function () {
    $resolver = new FakeResolver(['api.example.com' => ['93.184.216.34', '2606:4700:4700::1111']]);
    $verdict = guardFor([], resolver: $resolver)->decide(EG_A, 'https://api.example.com/v1/items?x=1#top', record: false);

    expect($verdict->allowed)->toBeTrue()
        ->and($verdict->pinnedIp)->toBe('93.184.216.34')
        ->and([$verdict->scheme, $verdict->host, $verdict->port])->toBe(['https', 'api.example.com', 443])
        ->and($verdict->code())->toBeNull()
        ->and($verdict->trail)->toContain('scheme:https', 'allowlist:matched', 'address:public')
        ->and($resolver->lookups)->toBe(['api.example.com']);
});

it('keeps every address out of the decision trail and the denial', function () {
    $allowed = guardFor(['api.example.com' => ['93.184.216.34']])->decide(EG_A, 'https://api.example.com/', record: false);
    $denied = guardFor(['api.example.com' => ['127.0.0.1']])->decide(EG_A, 'https://api.example.com/', record: false);

    expect(implode(' ', $allowed->trail))->not->toContain('93.184')
        ->and(implode(' ', $denied->trail))->not->toContain('127.0')
        ->and(json_encode($denied, JSON_PARTIAL_OUTPUT_ON_ERROR))->not->toContain('127.0.0.1');
});

it('uses the default port of the scheme and the explicit one', function (string $url, int $port, bool $allowed) {
    $verdict = guardFor(['api.example.com' => ['93.184.216.34'], 'plain.example.com' => ['93.184.216.34']])->decide(EG_A, $url, record: false);

    expect($verdict->allowed)->toBe($allowed)->and($verdict->port)->toBe($port);
})->with([
    ['https://api.example.com/', 443, true],
    ['https://api.example.com:8443/', 8443, true],
    ['http://plain.example.com/', 80, true],
    ['http://api.example.com/', 80, false],
    ['https://api.example.com:444/', 444, false],
    ['HTTPS://API.EXAMPLE.COM/', 443, true],
    'https entry, http URL on its port' => ['http://api.example.com:443/', 443, false],
    'http entry, https URL on its port' => ['https://plain.example.com:80/', 80, false],
]);

it('denies a host that is not on this Workspace allowlist as host_not_allowlisted with code connector.ssrf_blocked', function () {
    $resolver = new FakeResolver(['evil.example.com' => ['93.184.216.34']]);
    $verdict = guardFor([], resolver: $resolver)->decide(EG_A, 'https://evil.example.com/', record: false);

    expect($verdict->allowed)->toBeFalse()
        ->and($verdict->reason)->toBe(EgressReason::HostNotAllowlisted)
        ->and($verdict->code())->toBe(ErrorCode::SsrfBlocked)
        ->and($verdict->messageKey())->toBe('host-not-allowlisted')
        ->and($resolver->lookups)->toBe([], 'a host that is not allowlisted is never resolved');
});

it('reads the allowlist of the Workspace asked about', function () {
    $guard = new GuardEgressUrl(
        new FakeAllowlist([EG_A => ['api.example.com:443']]),
        new FakeResolver(['api.example.com' => ['93.184.216.34']]),
        new FakeGrants,
        new EgressSettings(new Repository([])),
        new RecordingBlocks,
    );

    expect($guard->decide(EG_A, 'https://api.example.com/', record: false)->allowed)->toBeTrue()
        ->and($guard->decide(EG_B, 'https://api.example.com/', record: false)->reason)->toBe(EgressReason::HostNotAllowlisted);
});

it('denies the whole request as blocked_address when any resolved address is undeniable', function (array $records) {
    $verdict = guardFor(['api.example.com' => $records])->decide(EG_A, 'https://api.example.com/', record: false);

    expect($verdict->allowed)->toBeFalse()
        ->and($verdict->reason)->toBe(EgressReason::BlockedAddress)
        ->and($verdict->messageKey())->toBe('blocked-address')
        ->and($verdict->pinnedIp)->toBeNull();
})->with([
    'loopback' => [['127.0.0.1']],
    'link-local' => [['169.254.10.10']],
    'metadata' => [['169.254.169.254']],
    'IPv6 metadata' => [['fd00:ec2::254']],
    'unspecified' => [['0.0.0.0']],
    'multicast' => [['224.0.0.5']],
    'IPv4-mapped' => [['::ffff:93.184.216.34']],
    'IPv4-compatible' => [['::5db8:d822']],
    'NAT64' => [['64:ff9b::5db8:d822']],
    '6to4' => [['2002:5db8:d822::1']],
    'Teredo' => [['2001:0:4136:e378:8000:63bf:3fff:fdd2']],
    'documentation' => [['203.0.113.7']],
    'benchmarking' => [['198.18.0.9']],
    'IPv6 loopback' => [['::1']],
    'IPv6 link-local' => [['fe80::1']],
    'a record that does not parse' => [['93.184.216.34', 'garbage']],
    'mixed A and AAAA, one bad (public first)' => [['93.184.216.34', '2606:4700:4700::1111', '::1']],
    'mixed A and AAAA, one bad (bad first)' => [['127.0.0.1', '93.184.216.34']],
    'public and metadata' => [['93.184.216.34', '169.254.169.254']],
]);

it('classifies the deployment CIDRs as undeniable, even with a grant that covers them', function () {
    $dns = ['api.example.com' => ['10.99.1.1']];
    $grants = [EG_A => ['10.0.0.0/8']];

    expect(guardFor($dns, grants: $grants)->decide(EG_A, 'https://api.example.com/', record: false)->allowed)->toBeTrue()
        ->and(guardFor($dns, grants: $grants, deployment: '10.99.0.0/16, 172.20.0.0/14')->decide(EG_A, 'https://api.example.com/', record: false)->reason)->toBe(EgressReason::BlockedAddress);
});

it('denies a private address without a grant as host_not_allowlisted, and allows it for the Workspace holding the grant only', function (string $address, string $cidr) {
    $guard = guardFor(['api.example.com' => [$address]], grants: [EG_A => [$cidr]]);

    $mine = $guard->decide(EG_A, 'https://api.example.com/', record: false);
    $theirs = $guard->decide(EG_B, 'https://api.example.com/', record: false);

    expect($mine->allowed)->toBeTrue()
        ->and($mine->pinnedIp)->toBe($address)
        ->and($theirs->allowed)->toBeFalse()
        ->and($theirs->reason)->toBe(EgressReason::HostNotAllowlisted)
        ->and($theirs->messageKey())->toBe('host-not-allowlisted');
})->with([
    ['10.20.30.40', '10.20.0.0/16'],
    ['172.16.5.5', '172.16.0.0/12'],
    ['192.168.1.20', '192.168.1.0/24'],
    ['100.64.1.1', '100.64.0.0/10'],
    ['fd12:3456::7', 'fd12:3456::/32'],
]);

it('denies a private address outside the granted range, and every private address of a mixed answer must be covered', function () {
    $grants = [EG_A => ['10.20.0.0/16']];

    expect(guardFor(['api.example.com' => ['10.21.0.1']], grants: $grants)->decide(EG_A, 'https://api.example.com/', record: false)->reason)->toBe(EgressReason::HostNotAllowlisted)
        ->and(guardFor(['api.example.com' => ['10.20.0.1', '10.21.0.1']], grants: $grants)->decide(EG_A, 'https://api.example.com/', record: false)->reason)->toBe(EgressReason::HostNotAllowlisted)
        ->and(guardFor(['api.example.com' => ['10.20.0.1', '93.184.216.34']], grants: $grants)->decide(EG_A, 'https://api.example.com/', record: false)->allowed)->toBeTrue()
        ->and(guardFor(['api.example.com' => ['10.20.0.1', '127.0.0.1']], grants: [EG_A => ['0.0.0.0/0']])->decide(EG_A, 'https://api.example.com/', record: false)->reason)->toBe(EgressReason::BlockedAddress);
});

it('never lets a grant lift an undeniable class', function (string $address) {
    $guard = guardFor(['api.example.com' => [$address]], grants: [EG_A => ['0.0.0.0/0', '127.0.0.0/8', '169.254.0.0/16', '::/0', 'fd00::/8', 'fe80::/10']]);

    expect($guard->decide(EG_A, 'https://api.example.com/', record: false)->reason)->toBe(EgressReason::BlockedAddress);
})->with(['127.0.0.1', '169.254.169.254', '::1', 'fd00:ec2::254', 'fe80::1', '::ffff:10.0.0.1']);

it('denies a name that does not resolve or has no records', function () {
    expect(guardFor([])->decide(EG_A, 'https://api.example.com/', record: false)->reason)->toBe(EgressReason::Unresolvable);
});

it('resolves the name once and never for an IP literal', function () {
    $resolver = new FakeResolver(['api.example.com' => ['93.184.216.34']]);

    guardFor([], resolver: $resolver)->decide(EG_A, 'https://api.example.com/', record: false);
    $literal = guardFor([], resolver: $resolver)->decide(EG_A, 'https://93.184.216.34/', record: false);

    expect($resolver->lookups)->toBe(['api.example.com'])
        ->and($literal->allowed)->toBeTrue()
        ->and($literal->pinnedIp)->toBe('93.184.216.34');
});

it('allows a bracketed public IPv6 literal on the allowlist and pins it', function () {
    $verdict = guardFor([])->decide(EG_A, 'https://[2606:4700:4700:0:0:0:0:1111]/', record: false);

    expect($verdict->allowed)->toBeTrue()->and($verdict->pinnedIp)->toBe('2606:4700:4700::1111');
});

it('classifies every spelling of a blocked IP literal and never resolves it', function (string $url, EgressReason $reason) {
    $resolver = new FakeResolver(['127.0.0.1' => ['93.184.216.34']]);
    $verdict = guardFor([], allow: ['127.0.0.1:80', '127.0.0.1:443', '10.0.0.1:80', '[::1]:80', '2130706433:80'], resolver: $resolver)->decide(EG_A, $url, record: false);

    expect($verdict->allowed)->toBeFalse()
        ->and($verdict->reason)->toBe($reason)
        ->and($resolver->lookups)->toBe([]);
})->with([
    'canonical loopback' => ['http://127.0.0.1/', EgressReason::BlockedAddress],
    'decimal' => ['http://2130706433/', EgressReason::BlockedAddress],
    'octal' => ['http://0177.0.0.1/', EgressReason::BlockedAddress],
    'octal short' => ['http://017700000001/', EgressReason::BlockedAddress],
    'hex' => ['http://0x7f.0.0.1/', EgressReason::BlockedAddress],
    'whole hex' => ['http://0x7f000001/', EgressReason::BlockedAddress],
    'short form' => ['http://127.1/', EgressReason::BlockedAddress],
    'three parts' => ['http://127.0.1/', EgressReason::BlockedAddress],
    'zero' => ['http://0/', EgressReason::BlockedAddress],
    'metadata decimal' => ['http://2852039166/', EgressReason::BlockedAddress],
    'metadata hex' => ['http://0xa9fea9fe/', EgressReason::BlockedAddress],
    'metadata mixed' => ['http://169.254.43518/', EgressReason::BlockedAddress],
    'IPv6 loopback' => ['http://[::1]/', EgressReason::BlockedAddress],
    'IPv6 loopback long' => ['http://[0:0:0:0:0:0:0:1]/', EgressReason::BlockedAddress],
    'IPv4-mapped' => ['http://[::ffff:127.0.0.1]/', EgressReason::BlockedAddress],
    'IPv4-mapped hex' => ['http://[::ffff:7f00:1]/', EgressReason::BlockedAddress],
    'NAT64' => ['http://[64:ff9b::7f00:1]/', EgressReason::BlockedAddress],
    'metadata IPv6' => ['http://[fd00:ec2::254]/', EgressReason::BlockedAddress],
    'private spelled in decimal' => ['http://167772161/', EgressReason::HostNotAllowlisted],
    'private canonical (never on an allowlist)' => ['http://10.0.0.1/', EgressReason::HostNotAllowlisted],
    'public spelled in hex' => ['http://0x5db8d822/', EgressReason::HostNotAllowlisted],
]);

it('refuses a malformed URL, an other scheme and a hostile authority', function (string $url, EgressReason $reason) {
    $verdict = guardFor(['api.example.com' => ['93.184.216.34']])->decide(EG_A, $url, record: false);

    expect($verdict->allowed)->toBeFalse()->and($verdict->reason)->toBe($reason);
})->with([
    'ftp' => ['ftp://api.example.com/', EgressReason::SchemeNotAllowed],
    'file' => ['file:///etc/passwd', EgressReason::SchemeNotAllowed],
    'gopher' => ['gopher://api.example.com/', EgressReason::SchemeNotAllowed],
    'data' => ['data:text/plain,hi', EgressReason::InvalidUrl],
    'no scheme' => ['//api.example.com/', EgressReason::InvalidUrl],
    'relative' => ['/v1/items', EgressReason::InvalidUrl],
    'empty' => ['', EgressReason::InvalidUrl],
    'empty host' => ['https:///v1', EgressReason::InvalidUrl],
    'userinfo' => ['https://user:pw@api.example.com/', EgressReason::InvalidUrl],
    'userinfo trick' => ['https://api.example.com@evil.example/', EgressReason::InvalidUrl],
    'backslash trick' => ['https://evil.example\\@api.example.com/', EgressReason::InvalidUrl],
    'backslash in path' => ['https://api.example.com/a\\b', EgressReason::InvalidUrl],
    'space in host' => ['https://api.example.com /', EgressReason::InvalidUrl],
    'space in path' => ['https://api.example.com/a b', EgressReason::InvalidUrl],
    'newline' => ["https://api.example.com/\nHost: evil", EgressReason::InvalidUrl],
    'nul' => ["https://api.example.com/\0", EgressReason::InvalidUrl],
    'percent in host' => ['https://api%2eexample.com/', EgressReason::InvalidUrl],
    'non-ascii host' => ['https://bücher.example/', EgressReason::InvalidUrl],
    'trailing dot' => ['https://api.example.com./', EgressReason::InvalidUrl],
    'port zero' => ['https://api.example.com:0/', EgressReason::InvalidUrl],
    'port too high' => ['https://api.example.com:65536/', EgressReason::InvalidUrl],
    'empty port' => ['https://api.example.com:/', EgressReason::InvalidUrl],
    'port text' => ['https://api.example.com:https/', EgressReason::InvalidUrl],
    'zone id' => ['https://[fe80::1%25eth0]/', EgressReason::InvalidUrl],
    'unclosed bracket' => ['https://[::1/', EgressReason::InvalidUrl],
    'bracketed name' => ['https://[example.com]/', EgressReason::InvalidUrl],
    'bad numeric' => ['https://256.1.1.1.1/', EgressReason::InvalidUrl],
]);

it('records each denial through the block log with the reason and host, and nothing for an allowed or dry-run decision', function () {
    $blocks = new RecordingBlocks;
    $guard = guardFor(['api.example.com' => ['127.0.0.1']], blocks: $blocks);

    $guard->decide(EG_A, 'https://api.example.com/');
    $guard->decide(EG_A, 'https://other.example.com/');
    $guard->decide(EG_A, 'https://api.example.com/', record: false);

    expect($blocks->recorded)->toBe([
        [EG_A, EgressReason::BlockedAddress, 'api.example.com', 443],
        [EG_A, EgressReason::HostNotAllowlisted, 'other.example.com', 443],
    ]);

    $ok = new RecordingBlocks;
    guardFor(['api.example.com' => ['93.184.216.34']], blocks: $ok)->decide(EG_A, 'https://api.example.com/');

    expect($ok->recorded)->toBe([]);
});
