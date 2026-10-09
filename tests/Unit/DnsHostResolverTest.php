<?php

use App\Modules\Connector\Infrastructure\DnsHostResolver;
use App\Modules\Connector\Infrastructure\NativeCurlClient;

it('maps A and AAAA rows to distinct addresses and ignores rows without one', function () {
    expect(DnsHostResolver::addresses([
        ['host' => 'a.example', 'type' => 'A', 'ip' => '93.184.216.34'],
        ['host' => 'a.example', 'type' => 'AAAA', 'ipv6' => '2606:4700:4700::1111'],
        ['host' => 'a.example', 'type' => 'A', 'ip' => '93.184.216.34'],
        ['host' => 'a.example', 'type' => 'CNAME', 'target' => 'b.example'],
        ['host' => 'a.example', 'type' => 'A', 'ip' => ''],
        ['host' => 'a.example', 'type' => 'A', 'ip' => 7],
        ['host' => 'a.example', 'type' => 'A', 'ip' => '10.0.0.1'],
    ]))->toBe(['93.184.216.34', '2606:4700:4700::1111', '10.0.0.1']);
});

it('returns no address for a failed or empty lookup', function () {
    expect(DnsHostResolver::addresses(false))->toBe([])->and(DnsHostResolver::addresses([]))->toBe([]);
});

it('asks for both A and AAAA records', function () {
    expect(file_get_contents(dirname(__DIR__, 2).'/app/Modules/Connector/Infrastructure/DnsHostResolver.php'))->toContain('DNS_A | DNS_AAAA');
});

it('keeps only the final response\'s headers when interim and redirect header blocks come first', function () {
    $headers = [];

    foreach (["HTTP/1.1 100 Continue\r\n", "X-Interim: yes\r\n", "Location: https://evil.example/\r\n", "\r\n", "HTTP/1.1 200 OK\r\n", "Content-Type: application/json\r\n", "Set-Cookie: a=1\r\n", "Set-Cookie: b=2\r\n", "\r\n"] as $line) {
        NativeCurlClient::collect($headers, $line);
    }

    expect($headers)->toBe(['content-type' => ['application/json'], 'set-cookie' => ['a=1', 'b=2']]);
});
