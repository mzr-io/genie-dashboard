<?php

use App\Modules\Connector\Contracts\EgressReason;
use App\Modules\Connector\Contracts\EgressRequest;
use App\Modules\Connector\Contracts\EgressTransport;
use App\Modules\Connector\Contracts\EgressTransportFailed;
use App\Modules\Connector\Contracts\EgressVerdict;
use App\Modules\Connector\Contracts\ErrorCode;
use App\Modules\Connector\Contracts\HostResolver;
use App\Modules\Connector\Contracts\ResponseLimit;
use App\Modules\Connector\Contracts\ResponseLimitExceeded;
use App\Modules\Connector\Contracts\SsrfBlocked;
use App\Modules\Connector\Infrastructure\CurlClient;
use App\Modules\Connector\Infrastructure\CurlEgressTransport;
use App\Modules\Connector\Infrastructure\NativeCurlClient;
use App\Platform\Json\InvalidLimitSetting;
use Tests\Database\Support\Cluster;
use Tests\Unit\Support\FakeCurl;
use Tests\Unit\Support\FakeResolver;

// Story 2.2: the transport. The guard, the allowlist and the audit are real; the resolver and the curl handler are fakes
// (nothing here touches the network), except the last tests, which talk to a throwaway server on 127.0.0.1.

const ETR_HOSTS = ['api.example.com', 'cdn.example.com'];

/** @return array{0: string, 1: FakeResolver, 2: FakeCurl} */
function transportWorld(array $dns = ['api.example.com' => ['93.184.216.34'], 'cdn.example.com' => ['93.184.216.35']], array $answers = []): array
{
    $workspace = Cluster::workspace('A');

    foreach (ETR_HOSTS as $host) {
        Cluster::seedHostEntry($workspace, $host);
    }

    $resolver = new FakeResolver($dns);
    $curl = new FakeCurl($answers);
    app()->instance(HostResolver::class, $resolver);
    app()->instance(CurlClient::class, $curl);

    return [$workspace, $resolver, $curl];
}

function blockEvents(): array
{
    return array_map(
        fn (array $row): array => json_decode($row['after_state'], true),
        Cluster::rows(Cluster::superuser(), "select after_state from audit_events where action = 'connector.egress.blocked' order by occurred_at, id"),
    );
}

it('connects to exactly the address the guard checked: the host and port are pinned with CURLOPT_RESOLVE', function () {
    [$workspace, , $curl] = transportWorld(answers: [FakeCurl::answer(200, '{"ok":true}', ['content-type' => ['application/json']])]);

    $response = app(EgressTransport::class)->send($workspace, new EgressRequest('https://api.example.com/v1/items?page=2', headers: ['Accept' => 'application/json'], credentials: ['Authorization' => 'Bearer t0k3n']));

    $options = $curl->calls[0];
    expect($response->status)->toBe(200)
        ->and($response->body)->toBe('{"ok":true}')
        ->and($response->header('Content-Type'))->toBe('application/json')
        ->and($options[CURLOPT_URL])->toBe('https://api.example.com/v1/items?page=2')
        ->and($options[CURLOPT_RESOLVE])->toBe(['api.example.com:443:93.184.216.34'])
        ->and($options[CURLOPT_HTTPHEADER])->toBe(['Accept: application/json', 'Authorization: Bearer t0k3n'])
        ->and($options[CURLOPT_FOLLOWLOCATION])->toBeFalse()
        ->and($options[CURLOPT_MAXREDIRS])->toBe(0)
        ->and($options[CURLOPT_SSL_VERIFYPEER])->toBeTrue()
        ->and($options[CURLOPT_SSL_VERIFYHOST])->toBe(2)
        ->and($options[CURLOPT_HTTPGET])->toBeTrue();
});

it('pins an IPv6 address in brackets and uses the explicit port', function () {
    [$workspace, , $curl] = transportWorld(['api.example.com' => ['2606:4700:4700::1111']], [FakeCurl::answer()]);
    Cluster::seedHostEntry($workspace, 'api.example.com', 8443);

    app(EgressTransport::class)->send($workspace, new EgressRequest('https://api.example.com:8443/'));

    expect($curl->calls[0][CURLOPT_RESOLVE])->toBe(['api.example.com:8443:[2606:4700:4700::1111]']);
});

it('accepts only http and https, for the request and for any redirect, through the curl handler alone', function () {
    [$workspace, , $curl] = transportWorld(answers: [FakeCurl::answer()]);

    app(EgressTransport::class)->send($workspace, new EgressRequest('https://api.example.com/'));
    $options = $curl->calls[0];

    $protocols = defined('CURLOPT_PROTOCOLS_STR')
        ? [$options[CURLOPT_PROTOCOLS_STR] ?? null, $options[CURLOPT_REDIR_PROTOCOLS_STR] ?? null]
        : [$options[CURLOPT_PROTOCOLS] ?? null, $options[CURLOPT_REDIR_PROTOCOLS] ?? null];

    expect($protocols)->toBe(defined('CURLOPT_PROTOCOLS_STR') ? ['http,https', 'http,https'] : [CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLPROTO_HTTP | CURLPROTO_HTTPS])
        ->and(app(EgressTransport::class))->toBeInstanceOf(CurlEgressTransport::class);
});

it('resolves the name once: a name that answers public and then loopback never reaches loopback (rebinding)', function () {
    [$workspace, $resolver, $curl] = transportWorld(answers: [FakeCurl::answer()]);

    // The first answer is public; a second lookup would be loopback. There must be no second lookup.
    $rebinding = $resolver;
    $rebinding->sequences['api.example.com'] = [['93.184.216.34'], ['127.0.0.1']];

    app(EgressTransport::class)->send($workspace, new EgressRequest('https://api.example.com/'));

    expect($rebinding->lookups)->toBe(['api.example.com'])
        ->and($curl->calls[0][CURLOPT_RESOLVE])->toBe(['api.example.com:443:93.184.216.34'])
        ->and(json_encode($curl->calls[0]))->not->toContain('127.0.0.1');
});

it('refuses a name that resolves to loopback before curl is ever called', function () {
    [$workspace, , $curl] = transportWorld(['api.example.com' => ['93.184.216.34', '127.0.0.1']]);

    $send = fn () => app(EgressTransport::class)->send($workspace, new EgressRequest('https://api.example.com/'));

    expect($send)->toThrow(SsrfBlocked::class, 'connector.ssrf_blocked: blocked_address')
        ->and($curl->calls)->toBe([])
        ->and(blockEvents())->toEqual([['reason' => 'blocked_address', 'host' => 'api.example.com', 'port' => 443]]);
});

it('refuses a host that is not on the allowlist with connector.ssrf_blocked and audits it', function () {
    [$workspace, , $curl] = transportWorld();

    try {
        app(EgressTransport::class)->send($workspace, new EgressRequest('https://evil.example.com/'));
        $thrown = null;
    } catch (SsrfBlocked $e) {
        $thrown = $e;
    }

    expect($thrown?->reason)->toBe(EgressReason::HostNotAllowlisted)
        ->and($thrown?->verdict?->messageKey())->toBe('host-not-allowlisted')
        ->and($curl->calls)->toBe([])
        ->and(blockEvents())->toEqual([['reason' => 'host_not_allowlisted', 'host' => 'evil.example.com', 'port' => 443]]);
});

it('follows a same-origin redirect itself and runs the whole guard again for it, pinning each hop', function () {
    [$workspace, $resolver, $curl] = transportWorld(answers: [
        FakeCurl::redirect('/v2/items'),
        FakeCurl::redirect('items/final?x=1', 301),
        FakeCurl::answer(200, '{"done":true}'),
    ]);

    $response = app(EgressTransport::class)->send($workspace, new EgressRequest('https://api.example.com/v1/a', credentials: ['Authorization' => 'Bearer t0k3n']));

    expect($response->body)->toBe('{"done":true}')
        ->and($response->redirects)->toBe(2)
        ->and($response->url)->toBe('https://api.example.com/v2/items/final?x=1')
        ->and(array_column($curl->calls, CURLOPT_URL))->toBe(['https://api.example.com/v1/a', 'https://api.example.com/v2/items', 'https://api.example.com/v2/items/final?x=1'])
        ->and($resolver->lookups)->toBe(['api.example.com', 'api.example.com', 'api.example.com'])
        ->and(array_map(fn ($c) => $c[CURLOPT_HTTPHEADER], $curl->calls))->each->toBe(['Authorization: Bearer t0k3n'])
        ->and(array_map(fn ($c) => $c[CURLOPT_FOLLOWLOCATION], $curl->calls))->each->toBeFalse();
});

it('re-guards a same-origin redirect: a name that now answers loopback is refused on the second hop', function () {
    [$workspace, $resolver, $curl] = transportWorld(answers: [FakeCurl::redirect('/next'), FakeCurl::answer()]);

    $resolver->sequences['api.example.com'] = [['93.184.216.34'], ['169.254.169.254']];

    expect(fn () => app(EgressTransport::class)->send($workspace, new EgressRequest('https://api.example.com/', credentials: ['Authorization' => 'x'])))
        ->toThrow(SsrfBlocked::class, 'blocked_address')
        ->and(count($curl->calls))->toBe(1)
        ->and(blockEvents())->toEqual([['reason' => 'blocked_address', 'host' => 'api.example.com', 'port' => 443]]);
});

it('refuses a redirect to another origin, a non-allowlisted host, another port or an https-to-http downgrade, sends nothing more and audits it', function (string $location, ?string $host, ?int $port) {
    [$workspace, , $curl] = transportWorld(answers: [FakeCurl::redirect($location), FakeCurl::answer()]);
    Cluster::seedHostEntry($workspace, 'api.example.com', 80, 'http');

    try {
        app(EgressTransport::class)->send($workspace, new EgressRequest('https://api.example.com/start', credentials: ['Authorization' => 'Bearer t0k3n', 'X-Api-Key' => 'k']));
        $thrown = null;
    } catch (SsrfBlocked $e) {
        $thrown = $e;
    }

    expect($thrown?->reason)->toBe(EgressReason::RedirectRefused)
        ->and(count($curl->calls))->toBe(1, 'nothing is sent to the redirect target, so no credential can follow it')
        ->and(blockEvents())->toEqual([['reason' => 'redirect_refused', 'host' => $host, 'port' => $port]]);
})->with([
    'allowlisted other host' => ['https://cdn.example.com/x', 'cdn.example.com', 443],
    'non-allowlisted host' => ['https://evil.example.net/x', 'evil.example.net', 443],
    'protocol-relative other host' => ['//evil.example.net/x', 'evil.example.net', 443],
    'downgrade to http' => ['http://api.example.com/x', 'api.example.com', 80],
    'other port' => ['https://api.example.com:8443/x', 'api.example.com', 8443],
    'userinfo trick' => ['https://api.example.com@evil.example.net/x', null, null],
    'loopback literal' => ['http://127.0.0.1/x', '127.0.0.1', 80],
    'metadata literal' => ['http://169.254.169.254/latest', '169.254.169.254', 80],
    'decimal spelling' => ['http://2130706433/', '2130706433', 80],
    'other scheme' => ['ftp://api.example.com/x', null, null],
    'file scheme' => ['file:///etc/passwd', null, null],
]);

it('allows an http-to-https upgrade only as a different origin: it is refused too', function () {
    [$workspace, , $curl] = transportWorld(answers: [FakeCurl::redirect('https://api.example.com/x'), FakeCurl::answer()]);
    Cluster::seedHostEntry($workspace, 'api.example.com', 80, 'http');

    expect(fn () => app(EgressTransport::class)->send($workspace, new EgressRequest('http://api.example.com/')))->toThrow(SsrfBlocked::class);
    expect(count($curl->calls))->toBe(1);
});

it('refuses a redirect chain that does not end', function () {
    [$workspace, , $curl] = transportWorld(answers: array_fill(0, 10, FakeCurl::redirect('/again')));

    expect(fn () => app(EgressTransport::class)->send($workspace, new EgressRequest('https://api.example.com/')))
        ->toThrow(SsrfBlocked::class, 'redirect_refused')
        ->and(count($curl->calls))->toBe(CurlEgressTransport::MAX_REDIRECTS + 1);
});

it('treats a 3xx without a Location, and a 304, as a plain response', function () {
    [$workspace] = transportWorld(answers: [FakeCurl::answer(302), FakeCurl::answer(304)]);

    expect(app(EgressTransport::class)->send($workspace, new EgressRequest('https://api.example.com/'))->status)->toBe(302)
        ->and(app(EgressTransport::class)->send($workspace, new EgressRequest('https://api.example.com/'))->status)->toBe(304);
});

it('turns a POST into a GET on 303 and keeps it on 307', function () {
    [$workspace, , $curl] = transportWorld(answers: [FakeCurl::redirect('/a', 303), FakeCurl::redirect('/b', 307), FakeCurl::answer()]);

    app(EgressTransport::class)->send($workspace, new EgressRequest('https://api.example.com/', method: 'POST', body: '{"q":1}'));

    expect($curl->calls[0][CURLOPT_CUSTOMREQUEST])->toBe('POST')
        ->and($curl->calls[0][CURLOPT_POSTFIELDS])->toBe('{"q":1}')
        ->and($curl->calls[1][CURLOPT_HTTPGET])->toBeTrue()
        ->and(isset($curl->calls[1][CURLOPT_POSTFIELDS]))->toBeFalse()
        ->and($curl->calls[2][CURLOPT_HTTPGET])->toBeTrue();
});

it('ignores every proxy variable: the proxy option is empty and NO_PROXY is *', function () {
    $names = ['HTTP_PROXY', 'http_proxy', 'HTTPS_PROXY', 'https_proxy', 'ALL_PROXY', 'all_proxy', 'NO_PROXY', 'no_proxy'];
    $saved = array_map(fn (string $name) => getenv($name), $names);

    foreach ($names as $name) {
        putenv("{$name}=http://127.0.0.1:1");
        $_SERVER[$name] = 'http://127.0.0.1:1';
    }

    try {
        [$workspace, , $curl] = transportWorld(answers: [FakeCurl::answer()]);

        app(EgressTransport::class)->send($workspace, new EgressRequest('https://api.example.com/', headers: ['Accept' => 'application/json']));

        expect($curl->calls[0][CURLOPT_PROXY])->toBe('')
            ->and($curl->calls[0][CURLOPT_NOPROXY])->toBe('*')
            ->and(json_encode($curl->calls[0]))->not->toContain('127.0.0.1:1');
    } finally {
        foreach ($names as $i => $name) {
            $saved[$i] === false ? putenv($name) : putenv("{$name}={$saved[$i]}");
            unset($_SERVER[$name]);
        }
    }
});

it('refuses a header name or value that could split the request', function (string $name, string $value) {
    [$workspace, , $curl] = transportWorld(answers: [FakeCurl::answer()]);

    expect(fn () => app(EgressTransport::class)->send($workspace, new EgressRequest('https://api.example.com/', headers: [$name => $value])))->toThrow(InvalidArgumentException::class)
        ->and($curl->calls)->toBe([]);
})->with([
    ['X-A', "v\r\nX-Injected: 1"],
    ['X-A', "v\nX-Injected: 1"],
    ['X-A', "v\0"],
    ["X-A\r\nX-B", 'v'],
    ['X A', 'v'],
    ['', 'v'],
]);

it('refuses a reserved header name, in any case and also as a credential, and any method but GET and POST', function (string $name) {
    [$workspace, , $curl] = transportWorld(answers: [FakeCurl::answer()]);

    expect(fn () => app(EgressTransport::class)->send($workspace, new EgressRequest('https://api.example.com/', headers: [$name => 'x'])))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(EgressTransport::class)->send($workspace, new EgressRequest('https://api.example.com/', credentials: [$name => 'x'])))->toThrow(InvalidArgumentException::class)
        ->and($curl->calls)->toBe([]);
})->with(['Host', 'host', 'HOST', 'Content-Length', 'Transfer-Encoding', 'Connection', 'Expect', 'TE', 'Upgrade', 'Proxy-Authorization', 'proxy-connection']);

it('refuses a method other than GET and POST', function (string $method) {
    [$workspace, , $curl] = transportWorld(answers: [FakeCurl::answer()]);

    expect(fn () => app(EgressTransport::class)->send($workspace, new EgressRequest('https://api.example.com/', method: $method)))->toThrow(InvalidArgumentException::class)
        ->and($curl->calls)->toBe([]);
})->with(['PUT', 'DELETE', 'TRACE', 'CONNECT', 'GET HTTP/1.1', "GET\r\nX: y", '']);

it('sets the connect and total timeouts only from numeric settings and invents none', function () {
    [$workspace, , $curl] = transportWorld(answers: array_fill(0, 3, FakeCurl::answer()));
    $send = fn () => app(EgressTransport::class)->send($workspace, new EgressRequest('https://api.example.com/'));

    $send();
    config(['dashflow.tunables.timeouts.connect_timeout.value' => '3', 'dashflow.tunables.timeouts.total_timeout.value' => 20]);
    $send();
    config(['dashflow.tunables.timeouts.connect_timeout.value' => 'soon', 'dashflow.tunables.timeouts.total_timeout.value' => '0']);
    $send();

    expect(array_key_exists(CURLOPT_CONNECTTIMEOUT, $curl->calls[0]) || array_key_exists(CURLOPT_TIMEOUT, $curl->calls[0]))->toBeFalse()
        ->and($curl->calls[1][CURLOPT_CONNECTTIMEOUT])->toBe(3)
        ->and($curl->calls[1][CURLOPT_TIMEOUT])->toBe(20)
        ->and(array_key_exists(CURLOPT_CONNECTTIMEOUT, $curl->calls[2]) || array_key_exists(CURLOPT_TIMEOUT, $curl->calls[2]))->toBeFalse();
});

it('lets the Data Source timeout only shorten the total timeout: min(source, platform), or the source alone when there is no platform one', function (?int $platform, int $source, int $expected) {
    [$workspace, , $curl] = transportWorld(answers: [FakeCurl::answer()]);
    config(['dashflow.tunables.timeouts.total_timeout.value' => $platform]);

    app(EgressTransport::class)->send($workspace, new EgressRequest('https://api.example.com/', timeoutSeconds: $source));

    expect($curl->calls[0][CURLOPT_TIMEOUT])->toBe($expected);
})->with([
    'source below platform' => [20, 5, 5],
    'source above platform' => [20, 50, 20],
    'no platform timeout' => [null, 5, 5],
]);

// ---- the real curl handler, against a throwaway server on 127.0.0.1 (never through the guard)

/** @return array{0: resource, 1: int, 2: string} */
function localServer(): array
{
    $dir = sys_get_temp_dir().'/dashflow-egress-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/router.php', <<<'PHP'
        <?php
        if ($_SERVER['REQUEST_URI'] === '/redirect') {
            header('Location: http://example.test/elsewhere', true, 302);
            exit;
        }
        if (preg_match('~\A/len/(\d+)\z~', $_SERVER['REQUEST_URI'], $m)) {
            header('Content-Type: application/json');
            echo str_repeat('a', (int) $m[1]);
            exit;
        }
        if ($_SERVER['REQUEST_URI'] === '/error-big') {
            http_response_code(503);
            echo str_repeat('e', 200000);
            exit;
        }
        if ($_SERVER['REQUEST_URI'] === '/redirect-big') {
            header('Location: http://example.test/elsewhere', true, 302);
            echo str_repeat('r', 200000);
            exit;
        }
        if ($_SERVER['REQUEST_URI'] === '/big') {
            header('Content-Type: application/json');
            echo '["'.str_repeat('a', 200000).'"]';
            exit;
        }
        if ($_SERVER['REQUEST_URI'] === '/bomb') {
            // About 20 KB compressed, 20 MB decompressed.
            header('Content-Type: application/json');
            header('Content-Encoding: gzip');
            echo gzencode('["'.str_repeat('a', 20_000_000).'"]', 9);
            exit;
        }
        header('Content-Type: application/json');
        header('X-Seen-Host: '.($_SERVER['HTTP_HOST'] ?? ''));
        echo json_encode(['auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null, 'uri' => $_SERVER['REQUEST_URI']]);
        PHP);

    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);

    $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $dir, $dir.'/router.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);

    for ($i = 0; $i < 100; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);

        if ($socket !== false) {
            fclose($socket);

            return [$process, $port, $dir];
        }

        usleep(50_000);
    }

    throw new RuntimeException('The local test server did not start.');
}

function stopServer(mixed $process, string $dir): void
{
    proc_terminate($process);
    proc_close($process);
    @unlink($dir.'/router.php');
    @rmdir($dir);
}

it('really connects to the pinned address for a name that does not resolve, with the native curl handler', function () {
    [$process, $port, $dir] = localServer();

    try {
        $transport = app(EgressTransport::class);
        $request = new EgressRequest("http://pinned.example.test:{$port}/v1?x=1", credentials: ['Authorization' => 'Bearer t0k3n']);
        $verdict = EgressVerdict::allowed('http', 'pinned.example.test', $port, '127.0.0.1', []);

        // The transport builds its options for the verdict; the real handler runs them.
        $result = (new NativeCurlClient)->execute($transport->options($verdict, $request->url, 'GET', $request, null));

        expect($result->status)->toBe(200)
            ->and(json_decode($result->body, true))->toBe(['auth' => 'Bearer t0k3n', 'uri' => '/v1?x=1'])
            ->and($result->headers['x-seen-host'][0])->toBe("pinned.example.test:{$port}")
            ->and($result->headers['content-type'][0])->toBe('application/json');
    } finally {
        stopServer($process, $dir);
    }
});

it('does not follow a redirect inside curl and ignores proxy variables set in the environment (httpoxy)', function () {
    [$process, $port, $dir] = localServer();
    $names = ['http_proxy', 'HTTP_PROXY', 'all_proxy', 'ALL_PROXY'];

    foreach ($names as $name) {
        putenv("{$name}=http://127.0.0.1:1");
    }

    try {
        $transport = app(EgressTransport::class);
        $verdict = EgressVerdict::allowed('http', 'pinned.example.test', $port, '127.0.0.1', []);
        $request = new EgressRequest("http://pinned.example.test:{$port}/redirect");
        $options = $transport->options($verdict, $request->url, 'GET', $request, null);

        $result = (new NativeCurlClient)->execute($options);

        // With a proxy variable and no override, curl would send this through 127.0.0.1:1 and fail: the control.
        $unprotected = $options;
        unset($unprotected[CURLOPT_PROXY], $unprotected[CURLOPT_NOPROXY]);
        $control = null;

        try {
            (new NativeCurlClient)->execute($unprotected);
        } catch (EgressTransportFailed $e) {
            $control = $e;
        }

        expect($result->status)->toBe(302)
            ->and($result->headers['location'][0])->toBe('http://example.test/elsewhere')
            ->and($control)->toBeInstanceOf(EgressTransportFailed::class);
    } finally {
        foreach ($names as $name) {
            putenv($name);
        }

        stopServer($process, $dir);
    }
});

it('reports a transport failure with the curl error number and no address', function () {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);

    $transport = app(EgressTransport::class);
    $verdict = EgressVerdict::allowed('http', 'closed.example.test', $port, '127.0.0.1', []);
    $request = new EgressRequest("http://closed.example.test:{$port}/");

    try {
        (new NativeCurlClient)->execute($transport->options($verdict, $request->url, 'GET', $request, null));
        $message = null;
    } catch (EgressTransportFailed $e) {
        $message = $e->getMessage();
    }

    expect($message)->toMatch('/curl error \d+/')->and($message)->not->toContain('127.0.0.1');
});

it('stops reading at the limit on the decompressed stream, for a big body and for a gzip bomb, and returns nothing partial', function (string $path, int $limit) {
    [$process, $port, $dir] = localServer();

    try {
        $transport = app(EgressTransport::class);
        $verdict = EgressVerdict::allowed('http', 'pinned.example.test', $port, '127.0.0.1', []);
        $request = new EgressRequest("http://pinned.example.test:{$port}{$path}");
        $options = $transport->options($verdict, $request->url, 'GET', $request, null);

        expect($options[CURLOPT_ENCODING])->toBe('gzip');

        $caught = null;

        try {
            (new NativeCurlClient)->execute($options, $limit);
        } catch (ResponseLimitExceeded $e) {
            $caught = $e;
        }

        expect($caught)->toBeInstanceOf(ResponseLimitExceeded::class)
            ->and($caught->limit)->toBe($limit)
            ->and($caught->bytesRead)->toBeGreaterThan($limit)
            ->and($caught->bytesRead)->toBeLessThan(20_000_000)
            ->and($caught->retryable)->toBeFalse()
            ->and($caught->code())->toBe(ErrorCode::LimitExceeded);

        // Within the limit the same handler returns the whole body.
        expect((new NativeCurlClient)->execute($options, 25_000_000)->body)->not->toBe('');
    } finally {
        stopServer($process, $dir);
    }
})->with(['a big body' => ['/big', 1000], 'a gzip bomb' => ['/bomb', 100_000]]);

it('takes the smaller of the Data Source limit and the platform ceiling, each only when set', function (?int $source, mixed $platform, ?int $expected) {
    expect(ResponseLimit::effective($source, $platform))->toBe($expected);
})->with([
    'neither' => [null, null, null],
    'source only' => [500, null, 500],
    'platform only' => [null, '700', 700],
    'source smaller' => [500, 700, 500],
    'platform smaller' => [900, 700, 700],
]);

it('passes the effective limit to the curl handler', function () {
    [$workspace, , $curl] = transportWorld(answers: [FakeCurl::answer(), FakeCurl::answer()]);
    config(['dashflow.tunables.guards.max_bytes.value' => '700']);
    $transport = app(EgressTransport::class);

    $transport->send($workspace, new EgressRequest('https://api.example.com/', maxBytes: 500));
    $transport->send($workspace, new EgressRequest('https://api.example.com/'));

    expect($curl->limits)->toBe([500, 700]);
});

it('returns a 2xx body of exactly the limit in full and aborts one byte over it', function () {
    [$process, $port, $dir] = localServer();

    try {
        $transport = app(EgressTransport::class);
        $verdict = EgressVerdict::allowed('http', 'pinned.example.test', $port, '127.0.0.1', []);
        $options = fn (string $path): array => $transport->options($verdict, "http://pinned.example.test:{$port}{$path}", 'GET', new EgressRequest("http://pinned.example.test:{$port}{$path}"), null);

        expect(strlen((new NativeCurlClient)->execute($options('/len/1000'), 1000)->body))->toBe(1000)
            ->and(fn () => (new NativeCurlClient)->execute($options('/len/1001'), 1000))->toThrow(ResponseLimitExceeded::class);
    } finally {
        stopServer($process, $dir);
    }
});

it('enforces the limit only on a 2xx answer: an oversized error page or redirect comes back with its status and headers', function () {
    [$process, $port, $dir] = localServer();

    try {
        $transport = app(EgressTransport::class);
        $verdict = EgressVerdict::allowed('http', 'pinned.example.test', $port, '127.0.0.1', []);
        $options = fn (string $path): array => $transport->options($verdict, "http://pinned.example.test:{$port}{$path}", 'GET', new EgressRequest("http://pinned.example.test:{$port}{$path}"), null);

        $error = (new NativeCurlClient)->execute($options('/error-big'), 1000);
        $redirect = (new NativeCurlClient)->execute($options('/redirect-big'), 1000);

        expect($error->status)->toBe(503)
            ->and(strlen($error->body))->toBeLessThanOrEqual(1000)
            ->and($redirect->status)->toBe(302)
            ->and($redirect->headers['location'][0])->toBe('http://example.test/elsewhere')
            ->and(strlen($redirect->body))->toBeLessThanOrEqual(1000);
    } finally {
        stopServer($process, $dir);
    }
});

it('rejects a set but malformed platform ceiling or source limit instead of reading it as unset', function (mixed $platform, ?int $source, string $setting) {
    try {
        ResponseLimit::effective($source, $platform);
        $caught = null;
    } catch (InvalidLimitSetting $e) {
        $caught = $e->setting;
    }

    expect($caught)->toBe($setting);
})->with([
    'letters' => ['abc', null, 'max_bytes'], 'unit suffix' => ['10MB', null, 'max_bytes'], 'zero' => ['0', null, 'max_bytes'],
    'negative' => ['-5', null, 'max_bytes'], 'float' => [1.5, null, 'max_bytes'], 'zero int' => [0, null, 'max_bytes'],
    'source zero' => [null, 0, 'max_response_bytes'], 'source negative' => ['700', -1, 'max_response_bytes'],
]);

it('treats an empty or null ceiling as unset', function () {
    expect(ResponseLimit::effective(null, ''))->toBeNull()->and(ResponseLimit::effective(null, null))->toBeNull();
});

// Story 2.7: a token request follows nothing, not even to the same origin: any 3xx is a failure and nothing more is sent.
it('treats any 3xx answer to a request that refuses redirects as a failure and follows nothing, not even on the same origin', function (int $status) {
    [$workspace, , $curl] = transportWorld(answers: [FakeCurl::redirect('https://api.example.com/other', $status), FakeCurl::answer()]);

    expect(fn () => app(EgressTransport::class)->send($workspace, new EgressRequest('https://api.example.com/token', 'POST', body: 'a=b', refuseRedirects: true)))
        ->toThrow(EgressTransportFailed::class)
        ->and($curl->calls)->toHaveCount(1);
})->with([301, 302, 303, 307, 308]);

it('treats a 304 or a 3xx without a Location as a failure too when redirects are refused, and still sends an ordinary request as before', function () {
    [$workspace, , $curl] = transportWorld(answers: [FakeCurl::answer(304, ''), FakeCurl::answer(200, '{}')]);

    expect(fn () => app(EgressTransport::class)->send($workspace, new EgressRequest('https://api.example.com/token', 'POST', body: 'a=b', refuseRedirects: true)))
        ->toThrow(EgressTransportFailed::class);

    expect(app(EgressTransport::class)->send($workspace, new EgressRequest('https://api.example.com/token', 'POST', body: 'a=b', refuseRedirects: true))->status)->toBe(200)
        ->and($curl->calls[1][CURLOPT_POSTFIELDS])->toBe('a=b');
});

it('still guards the token URL: a host that is not allowlisted is refused and audited', function () {
    [$workspace, , $curl] = transportWorld(['auth.example.org' => ['93.184.216.50']], [FakeCurl::answer()]);

    expect(fn () => app(EgressTransport::class)->send($workspace, new EgressRequest('https://auth.example.org/token', 'POST', body: 'a=b', refuseRedirects: true)))
        ->toThrow(SsrfBlocked::class)
        ->and($curl->calls)->toBe([])
        ->and(blockEvents())->toHaveCount(1);
});
