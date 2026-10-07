<?php

use App\Modules\Connector\Contracts\DataSourceUrl;
use App\Modules\Connector\Contracts\HostProblem;
use App\Modules\Connector\Contracts\InvalidDataSourceUrl;
use App\Modules\Connector\Contracts\ReservedHeaders;
use App\Modules\Connector\Contracts\UrlProblem;

// Story 2.3: the Base URL's validation table. Every refusal names its reason; nothing is trimmed or repaired and
// parsing never resolves a name.

function urlProblemOf(mixed $input): ?UrlProblem
{
    try {
        DataSourceUrl::parse($input);
    } catch (InvalidDataSourceUrl $e) {
        return $e->problem;
    }

    return null;
}

it('accepts an http(s) URL and derives the scheme, host and resolved port', function (string $input, string $normal, string $scheme, string $host, int $port) {
    $url = DataSourceUrl::parse($input);

    expect([$url->baseUrl, $url->scheme, $url->host, $url->port])->toBe([$normal, $scheme, $host, $port]);
})->with([
    'https default port' => ['https://api.example.com', 'https://api.example.com', 'https', 'api.example.com', 443],
    'the default port is dropped' => ['https://api.example.com:443/v1', 'https://api.example.com/v1', 'https', 'api.example.com', 443],
    'http default port' => ['http://api.example.com:80', 'http://api.example.com', 'http', 'api.example.com', 80],
    'a port' => ['https://api.example.com:8443/v1/', 'https://api.example.com:8443/v1/', 'https', 'api.example.com', 8443],
    'scheme and host are lower-cased' => ['HTTPS://API.Example.COM/V1', 'https://api.example.com/V1', 'https', 'api.example.com', 443],
    'a path with encoded characters' => ['https://api.example.com/a%20b/c-d_e.f~g', 'https://api.example.com/a%20b/c-d_e.f~g', 'https', 'api.example.com', 443],
    'a public IPv4 literal' => ['http://93.184.216.34:8080', 'http://93.184.216.34:8080', 'http', '93.184.216.34', 8080],
    'an IPv6 literal' => ['https://[2606:4700:4700::1111]/x', 'https://[2606:4700:4700::1111]/x', 'https', '[2606:4700:4700::1111]', 443],
]);

it('refuses what is not a plain http(s) Base URL, with its reason', function (mixed $input, UrlProblem $problem) {
    expect(urlProblemOf($input))->toBe($problem);
})->with([
    'not text' => [['x'], UrlProblem::Empty],
    'empty' => ['', UrlProblem::Empty],
    'too long' => ['https://api.example.com/'.str_repeat('a', 2048), UrlProblem::TooLong],
    'a leading space' => [' https://api.example.com', UrlProblem::Whitespace],
    'a trailing newline' => ["https://api.example.com\n", UrlProblem::Whitespace],
    'a space in the path' => ['https://api.example.com/a b', UrlProblem::Whitespace],
    'a tab' => ["https://api.example.com/\t", UrlProblem::Whitespace],
    'a zero-width space' => ["https://api.example.com/\u{200B}", UrlProblem::Whitespace],
    'no scheme' => ['api.example.com', UrlProblem::Malformed],
    'a backslash' => ['https://api.example.com\\@evil.example', UrlProblem::Malformed],
    'non-ASCII in the path' => ["https://api.example.com/caf\u{e9}", UrlProblem::Malformed],
    'a bad percent escape' => ['https://api.example.com/%zz', UrlProblem::Malformed],
    'a quote in the path' => ['https://api.example.com/"x', UrlProblem::Malformed],
    'a dot segment' => ['https://api.example.com/v1/./x', UrlProblem::Malformed],
    'a dot-dot segment' => ['https://api.example.com/v1/../admin', UrlProblem::Malformed],
    'an encoded dot-dot' => ['https://api.example.com/v1/%2e%2E/admin', UrlProblem::Malformed],
    'a trailing dot-dot' => ['https://api.example.com/..', UrlProblem::Malformed],
    'an encoded slash' => ['https://api.example.com/a%2Fb', UrlProblem::Malformed],
    'an encoded backslash' => ['https://api.example.com/a%5cb', UrlProblem::Malformed],
    'ftp' => ['ftp://api.example.com', UrlProblem::Scheme],
    'file' => ['file:///etc/passwd', UrlProblem::Scheme],
    'gopher' => ['gopher://api.example.com', UrlProblem::Scheme],
    'javascript' => ['javascript://api.example.com', UrlProblem::Scheme],
    'userinfo' => ['https://user@api.example.com', UrlProblem::Userinfo],
    'user and password' => ['https://user:pass@api.example.com/', UrlProblem::Userinfo],
    'an @ hiding the host' => ['https://api.example.com@evil.example/', UrlProblem::Userinfo],
    'a query' => ['https://api.example.com/v1?a=1', UrlProblem::Query],
    'a query right after the host' => ['https://api.example.com?a=1', UrlProblem::Query],
    'a fragment' => ['https://api.example.com/v1#x', UrlProblem::Fragment],
    'a fragment right after the host' => ['https://api.example.com#x', UrlProblem::Fragment],
    'no host' => ['https:///v1', UrlProblem::InvalidHost],
    'an empty port' => ['https://api.example.com:/v1', UrlProblem::InvalidHost],
    'a port out of range' => ['https://api.example.com:65536', UrlProblem::InvalidHost],
    'a wildcard' => ['https://*.example.com', UrlProblem::InvalidHost],
    'a loopback literal' => ['http://127.0.0.1', UrlProblem::InvalidHost],
    'the metadata address' => ['http://169.254.169.254/latest', UrlProblem::InvalidHost],
    'a private literal' => ['http://10.0.0.1', UrlProblem::InvalidHost],
    'a decimal spelling of an address' => ['http://2130706433', UrlProblem::InvalidHost],
    'an IPv6 loopback' => ['http://[::1]', UrlProblem::InvalidHost],
    'a non-ASCII host' => ["https://b\u{fc}cher.example", UrlProblem::Malformed],
]);

it('reports the host rule that refused an address', function () {
    try {
        DataSourceUrl::parse('http://127.0.0.1');
    } catch (InvalidDataSourceUrl $e) {
        expect($e->host)->toBe(HostProblem::BlockedAddress);

        return;
    }

    throw new RuntimeException('Expected a refusal.');
});

it('reserves the transport headers and the credential headers, case-insensitively', function (string $name, bool $transport, bool $credential) {
    expect(ReservedHeaders::transport($name))->toBe($transport)->and(ReservedHeaders::credential($name))->toBe($credential);
})->with([
    ['Host', true, false],
    ['content-LENGTH', true, false],
    ['Transfer-Encoding', true, false],
    ['Proxy-Connection', true, false],
    ['Proxy-Authorization', true, true],
    ['Authorization', false, true],
    ['COOKIE', false, true],
    ['X-Api-Key', false, false],
    ['Accept', false, false],
]);
