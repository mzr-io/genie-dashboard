<?php

use App\Modules\Connector\Application\FetchEndpoint;
use App\Modules\Connector\Contracts\FetchResponse;
use App\Modules\Connector\Infrastructure\NativeCurlClient;

// Story 2.15: the transport lower-cases header names as it collects them, and the validator lookup reads them by lower-case name.

it('reads a validator whatever case the source wrote its header name in', function () {
    $headers = [];

    foreach (["HTTP/1.1 200 OK\r\n", "Content-Type: application/json\r\n", "ETag: \"v1\"\r\n", "LAST-MODIFIED: Wed, 21 Oct 2015 07:28:00 GMT\r\n"] as $line) {
        NativeCurlClient::collect($headers, $line);
    }

    $response = new FetchResponse(200, $headers, '{}', 2, 1);
    $read = new ReflectionMethod(FetchEndpoint::class, 'validator');

    expect($read->invoke(null, $response, 'etag'))->toBe('"v1"')
        ->and($read->invoke(null, $response, 'last-modified'))->toBe('Wed, 21 Oct 2015 07:28:00 GMT');
});
