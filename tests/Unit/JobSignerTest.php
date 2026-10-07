<?php

use App\Support\Queue\JobSigner;

function samplePayload(): array
{
    return [
        'uuid' => '6f1d3c1e-0000-4000-8000-000000000001',
        'job' => 'Illuminate\Queue\CallQueuedHandler@call',
        'attempts' => 0,
        'maxTries' => 3,
        'timeout' => 60,
        'backoff' => '1,5',
        'data' => ['commandName' => 'App\Jobs\Demo', 'command' => 'O:12:"App\Jobs\Demo":0:{}'],
    ];
}

it('signs and verifies a payload', function () {
    $signer = new JobSigner('base64:'.base64_encode(str_repeat('k', 32)));

    expect($signer->verify($signer->sign(samplePayload())))->toBeTrue();
});

it('rejects a payload whose signed fields changed', function (string $field) {
    $signer = new JobSigner('base64:'.base64_encode(str_repeat('k', 32)));
    $payload = $signer->sign(samplePayload());

    match ($field) {
        'command' => $payload['data']['command'] = 'O:8:"Evil":0:{}',
        'commandName' => $payload['data']['commandName'] = 'Evil',
        'job' => $payload['job'] = 'Evil@handle',
        'uuid' => $payload['uuid'] = 'other',
        'data' => $payload['data']['arguments'] = ['x' => 1],
        'maxTries' => $payload['maxTries'] = 99,
        'timeout' => $payload['timeout'] = 9999,
        'backoff' => $payload['backoff'] = '0',
    };

    expect($signer->verify($payload))->toBeFalse();
})->with(['command', 'commandName', 'job', 'uuid', 'data', 'maxTries', 'timeout', 'backoff']);

it('ignores fields that the queue rewrites after enqueueing', function () {
    $signer = new JobSigner('base64:'.base64_encode(str_repeat('k', 32)));
    $payload = $signer->sign(samplePayload());
    $payload['attempts'] = 3;
    $payload['tags'] = ['x'];
    $payload['pushedAt'] = '1.5';
    $payload['retryUntil'] = 123;
    $payload['retry_of'] = 'abc';
    // The Lua scripts re-encode the payload: key order inside `data` may change.
    $payload['data'] = array_reverse($payload['data'], true);

    expect($signer->verify($payload))->toBeTrue();
});

it('rejects unsigned, empty and non-string signatures', function (mixed $signature) {
    $signer = new JobSigner('secret');
    $payload = samplePayload();
    if ($signature !== '__absent__') {
        $payload['signature'] = $signature;
    }

    expect($signer->verify($payload))->toBeFalse();
})->with(['__absent__', '', null, 123, ['x']]);

it('does not verify with another key and never signs with the raw APP_KEY', function () {
    $payload = (new JobSigner('key-one'))->sign(samplePayload());

    expect((new JobSigner('key-two'))->verify($payload))->toBeFalse();

    $derived = hash_hmac('sha256', JobSigner::CONTEXT, 'key-one', true);
    $data = $payload['data'];
    ksort($data);
    $canonical = json_encode([
        $payload['job'], $payload['uuid'], $payload['maxTries'], $payload['timeout'], $payload['backoff'], $data,
    ], JSON_UNESCAPED_SLASHES);
    expect($payload['signature'])->toBe(hash_hmac('sha256', $canonical, $derived))
        ->not->toBe(hash_hmac('sha256', $canonical, 'key-one'));
});

it('refuses an empty key', function () {
    new JobSigner('');
})->throws(InvalidArgumentException::class);
