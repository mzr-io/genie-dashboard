<?php

// Story 2.10: a Sample Response is kept only as an encrypted, short-lived cache entry. The code that handles the body never
// touches PostgreSQL, and the one place a response body leaves the handler is the sealed store.

function sampleSource(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/app/Modules/Connector/'.$path);
}

it('keeps the sample store, the reader and the sample itself away from the database and the logs', function (string $path) {
    $source = sampleSource($path);

    expect($source)->not->toMatch('/\bDB::|Illuminate\\\\Support\\\\Facades\\\\DB|Eloquent|Log::|LoggerInterface|->log\(/');
})->with(['Infrastructure/SampleBlobStore.php', 'Application/ReadSample.php', 'Contracts/Sample.php']);

it('lets the Sample handler pass the response body to the sealed store and nowhere else', function () {
    $source = sampleSource('Application/RunSampleFetch.php');

    // The body is read once, as the argument of the sealed store; everything else about the response is a number or a code.
    expect(substr_count($source, '$response->body'))->toBe(1)
        ->and($source)->toMatch('/blobs->put\([^;]*\$response->body/s')
        ->and($source)->not->toMatch('/\$response->(?:headers|json\(\))/')
        ->and($source)->not->toMatch('/Log::\w+\([^;]*(?:body|values|\$request)/s');
});

it('seals with the data key and fails closed, never caching a sample in the clear', function () {
    $source = sampleSource('Infrastructure/SampleBlobStore.php');

    expect($source)->toContain('sodium_crypto_secretbox(')
        ->and($source)->toContain('dataKeyPath()')
        ->and($source)->toContain("SampleBlobUnavailable('key_unavailable')")
        ->and($source)->not->toContain('tokenKeyPath');
});

// Story 2.13: the code that handles a member's resolved values never logs, audits or stores them, and the worker never needs the digest key.
it('keeps the user-context resolver and the Fetch as user handler away from the logs, the audit and the database', function (string $path) {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/app/Modules/'.$path);

    expect($source)->not->toMatch('/Log::|LoggerInterface|->log\(|\baudit->|Outbox|AuditAction|\bDB::insert|\bDB::update/');
})->with(['Connector/Application/RunFetchAsUser.php', 'Access/Contracts/UserContextValues.php']);

it('resolves user context without the digest key', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/app/Modules/Access/Application/ResolveUserContext.php');

    expect($source)->toContain('assertReadable()')->and($source)->not->toContain('assertAvailable')->and($source)->not->toContain('blindIndex');
});
