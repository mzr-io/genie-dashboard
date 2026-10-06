<?php

use App\Support\Queue\JobSignatureGuard;
use App\Support\Queue\JobSigner;
use App\Support\Queue\SignedRedisConnector;
use App\Support\Queue\SignedRedisQueue;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Queue;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/** A job that records when it is unserialized or run. */
final class SignedJobProbe implements ShouldQueue
{
    public static int $unserialized = 0;

    public static int $ran = 0;

    public function __construct(public int $id = 1) {}

    public function __unserialize(array $data): void
    {
        self::$unserialized++;
        $this->id = $data['id'];
    }

    public function handle(): void
    {
        self::$ran++;
    }
}

beforeEach(function () {
    SignedJobProbe::$unserialized = 0;
    SignedJobProbe::$ran = 0;
    config(['queue.connections.redis.driver' => 'redis']);
});

/** Pushes a probe through the real Redis queue class with a fake Redis and returns what it stored. */
function pushedPayload(): string
{
    $stored = null;
    $connection = Mockery::mock();
    $connection->shouldReceive('isCluster')->andReturn(false);
    $connection->shouldReceive('eval')->andReturnUsing(function (...$args) use (&$stored) {
        // LuaScripts::push(): the payload is the first key argument after the queue keys.
        foreach ($args as $arg) {
            if (is_string($arg) && str_starts_with($arg, '{')) {
                $stored = $arg;
            }
        }

        return 1;
    });
    $factory = Mockery::mock(RedisFactory::class);
    $factory->shouldReceive('connection')->andReturn($connection);

    $queue = (new SignedRedisConnector($factory, app(JobSigner::class)))->connect(['queue' => 'default']);
    // A private container with its own dispatcher keeps Horizon's push listeners (which write to Redis) out.
    $container = new Container;
    $container->instance('events', new Dispatcher($container));
    $container->instance(Dispatcher::class, $container['events']);
    $queue->setContainer($container);
    $queue->push(new SignedJobProbe(7), '', 'compute');

    return $stored ?? throw new RuntimeException('nothing was pushed');
}

function runPayload(string $payload): void
{
    $job = new SyncJob(app(), $payload, 'redis', 'compute');

    app('queue.worker')->process('redis', $job, new WorkerOptions(maxTries: 3));
}

it('registers the signing connector for the redis queue driver, replacing Horizon\'s', function () {
    $manager = app(QueueManager::class);
    $connector = (new ReflectionMethod($manager, 'getConnector'))->invoke($manager, 'redis');

    expect($connector)->toBeInstanceOf(SignedRedisConnector::class);
    expect($connector->connect(['queue' => 'default']))->toBeInstanceOf(SignedRedisQueue::class);
});

it('carries IDs only and a signature on an enqueued job', function () {
    $payload = json_decode(pushedPayload(), true);

    expect($payload)->toHaveKey('signature')
        ->and($payload['signature'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($payload['data']['commandName'])->toBe(SignedJobProbe::class);
});

it('runs a signed job', function () {
    runPayload(pushedPayload());

    expect(SignedJobProbe::$ran)->toBe(1);
});

it('never unserializes or runs a tampered payload, logs a security event and does not retry', function () {
    $payload = json_decode(pushedPayload(), true);
    $payload['data']['command'] = str_replace('i:7;', 'i:99;', $payload['data']['command']);
    $failed = [];
    Event::listen(JobFailed::class, function (JobFailed $e) use (&$failed) {
        $failed[] = $e;
    });
    Log::spy();

    $job = new SyncJob(app(), json_encode($payload), 'redis', 'compute');
    app('queue.worker')->process('redis', $job, new WorkerOptions(maxTries: 3));

    expect(SignedJobProbe::$unserialized)->toBe(0)
        ->and(SignedJobProbe::$ran)->toBe(0)
        ->and($job->isDeleted())->toBeTrue()
        ->and($job->hasFailed())->toBeTrue()
        ->and($job->attempts())->toBeLessThanOrEqual(1)
        ->and($failed)->toHaveCount(1);

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) {
        return $message === JobSignatureGuard::SECURITY_EVENT
            && $context['reason'] === 'mismatch'
            && ! str_contains(json_encode($context), 'SignedJobProbe')
            && ! str_contains(json_encode($context), 'i:99');
    });
});

it('treats an unsigned payload like a tampered one', function () {
    $payload = json_decode(pushedPayload(), true);
    unset($payload['signature']);
    Log::spy();

    runPayload(json_encode($payload));

    expect(SignedJobProbe::$unserialized)->toBe(0)->and(SignedJobProbe::$ran)->toBe(0);
    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $m, array $c) => $c['reason'] === 'missing');
});

it('rejects a signature made with another key', function () {
    $payload = json_decode(pushedPayload(), true);
    config(['app.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);
    app()->forgetInstance(JobSigner::class);
    Log::spy();

    runPayload(json_encode($payload));

    expect(SignedJobProbe::$unserialized)->toBe(0)->and(SignedJobProbe::$ran)->toBe(0);
});

it('rejects a payload that is not an object', function () {
    Log::spy();

    runPayload('"just a string"');

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $m, array $c) => $c['reason'] === 'malformed');
});

it('leaves queues that do not sign untouched', function () {
    $payload = json_decode(pushedPayload(), true);
    unset($payload['signature']);

    $job = new SyncJob(app(), json_encode($payload), 'sync', 'default');
    app('queue.worker')->process('sync', $job, new WorkerOptions);

    expect(SignedJobProbe::$ran)->toBe(1);
});

it('keeps the request id hook beside the signer', function () {
    // The signer is not part of the createPayloadUsing hooks: it runs after serialization.
    $callbacks = (new ReflectionProperty(Queue::class, 'createPayloadCallbacks'))->getValue();

    foreach ($callbacks as $callback) {
        expect($callback('redis', 'compute', []))->not->toHaveKey('signature');
    }
});

/** Pushes a payload through pushRaw (as Horizon's retry does) and returns what was stored. */
function retriedPayload(array $payload): array
{
    $stored = null;
    $connection = Mockery::mock();
    $connection->shouldReceive('isCluster')->andReturn(false);
    $connection->shouldReceive('eval')->andReturnUsing(function (...$args) use (&$stored) {
        foreach ($args as $arg) {
            if (is_string($arg) && str_starts_with($arg, '{')) {
                $stored = $arg;
            }
        }

        return 1;
    });
    $factory = Mockery::mock(RedisFactory::class);
    $factory->shouldReceive('connection')->andReturn($connection);
    $queue = (new SignedRedisConnector($factory, app(JobSigner::class)))->connect(['queue' => 'default']);
    $queue->setContainer(new Container);
    $queue->pushRaw(json_encode($payload), 'compute');

    return json_decode($stored, true);
}

it('re-signs a retry whose original signature is valid', function () {
    $original = json_decode(pushedPayload(), true);
    $retry = array_merge($original, ['id' => 'new-id', 'uuid' => 'new-id', 'retry_of' => $original['uuid'], 'attempts' => 0]);

    $stored = retriedPayload($retry);

    expect($stored['uuid'])->toBe('new-id')
        ->and(app(JobSigner::class)->verify($stored))->toBeTrue();
});

it('does not re-sign a forged, unsigned or mislabelled retry', function (string $case) {
    $original = json_decode(pushedPayload(), true);
    $retry = array_merge($original, ['id' => 'new-id', 'uuid' => 'new-id', 'retry_of' => $original['uuid']]);

    match ($case) {
        'forged command' => $retry['data']['command'] = 'O:8:"stdClass":0:{}',
        'unsigned' => $retry['signature'] = null,
        'bad retry_of' => $retry['retry_of'] = 'someone-else',
        'empty retry_of' => $retry['retry_of'] = '',
    };
    if ($case === 'unsigned') {
        unset($retry['signature']);
    }

    $stored = retriedPayload($retry);

    expect(app(JobSigner::class)->verify($stored))->toBeFalse()
        ->and($stored['signature'] ?? null)->toBe($retry['signature'] ?? null)
        ->and($stored['uuid'])->toBe('new-id')
        ->and($stored['data'])->toBe($retry['data']);
})->with(['forged command', 'unsigned', 'bad retry_of', 'empty retry_of']);
