<?php

use App\Support\Observability\MetricName;
use App\Support\Observability\RequestContext;
use App\Support\Observability\Scrubber;

it('removes query strings, fragments and userinfo from urls', function () {
    expect(Scrubber::url('https://user:pw@example.test/a/b?token=CANARY#CANARY'))->toBe('https://example.test/a/b')
        ->and(Scrubber::url('/a/b?x=CANARY'))->toBe('/a/b');
});

it('scrubs urls inside free text', function () {
    $text = Scrubber::text('GET /callback?code=CANARY failed for https://h.test/p?k=CANARY#CANARY2 again');

    expect($text)->not->toContain('CANARY')
        ->and($text)->toContain('/callback')->toContain('https://h.test/p');
});

it('keeps only allowlisted headers', function () {
    $headers = Scrubber::headers([
        'Authorization' => 'Bearer CANARY',
        'Cookie' => 'CANARY',
        'X-Custom' => 'CANARY',
        'Content-Type' => 'application/json',
        'X-Request-Id' => 'abcdefgh',
    ]);

    expect($headers)->toBe(['content-type' => 'application/json', 'x-request-id' => 'abcdefgh']);
});

it('scrubs nested log context', function () {
    $out = Scrubber::value([
        'url' => 'https://h.test/p?k=CANARY',
        'headers' => ['authorization' => 'CANARY', 'accept' => 'text/html'],
        'nested' => ['api_key' => 'CANARY', 'message' => 'see /x?y=CANARY'],
        'exception' => new RuntimeException('failed https://h.test/z?s=CANARY'),
    ]);

    expect(json_encode($out))->not->toContain('CANARY')
        ->and($out['headers'])->toBe(['accept' => 'text/html']);
});

it('drops span attributes that are not allowlisted and scrubs the rest', function () {
    $out = Scrubber::spanAttributes([
        'url.full' => 'https://h.test/p?k=CANARY#f',
        'url.query' => 'k=CANARY',
        'http.request.header.authorization' => 'CANARY',
        'http.request.header.content_type' => 'text/plain',
        'db.query.text' => 'select CANARY',
        'dashflow.request_id' => 'req-12345678',
        'exception.message' => 'boom at /a?b=CANARY',
    ]);

    expect(json_encode($out))->not->toContain('CANARY')
        ->and(array_keys($out))->toBe(['url.full', 'http.request.header.content_type', 'dashflow.request_id', 'exception.message']);
});

it('scrubs metric labels', function () {
    $labels = MetricName::labels(['route' => '/a?x=CANARY', 'authorization' => 'CANARY', 'obj' => ['CANARY']]);

    expect(json_encode($labels))->not->toContain('CANARY')->and($labels)->toBe(['route' => '/a']);
});

it('accepts well formed metric names and rejects others', function () {
    expect(MetricName::make('connectors', 'fetch_duration'))->toBe('dashflow.connectors.fetch_duration');

    foreach (['dashflow.Foo bar', 'foo.bar.baz', 'dashflow.only', 'dashflow.a.b.c'] as $bad) {
        expect(fn () => MetricName::assert($bad))->toThrow(InvalidArgumentException::class);
    }
});

it('keeps valid request ids and replaces the rest', function () {
    expect(RequestContext::sanitize('abc.DEF_123-xyz'))->toBe('abc.DEF_123-xyz');

    foreach ([null, 'short', 'has space in it', str_repeat('a', 65), "bad\x01controlchar", "newline\nnewline1"] as $bad) {
        $id = RequestContext::sanitize($bad);
        expect($id)->toHaveLength(26)->not->toBe($bad);
    }
});
