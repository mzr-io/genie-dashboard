<?php

use Illuminate\Console\Scheduling\Schedule;

/** Story 1.5: two Valkey stores, sessions on the database, optional PgBouncer switch. */
function loadConfig(string $file, array $env = []): array
{
    // Laravel's env() reads $_SERVER and $_ENV before getenv(), and phpunit.xml sets some of them.
    $previous = [];
    foreach ($env as $key => $value) {
        $previous[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    try {
        return require dirname(__DIR__, 2)."/config/{$file}.php";
    } finally {
        foreach ($previous as $key => [$fromEnv, $fromServer, $fromProcess]) {
            if ($fromEnv === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $fromEnv;
            }
            if ($fromServer === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $fromServer;
            }
            $fromProcess === false ? putenv($key) : putenv("{$key}={$fromProcess}");
        }
    }
}

it('defines the queue and cache connections and no default one', function () {
    $redis = config('database.redis');

    expect($redis)->toHaveKeys(['queue', 'cache'])
        ->not->toHaveKey('default');
    expect(config('queue.connections.redis.connection'))->toBe('queue');
    expect(config('horizon.use'))->toBe('queue');
    expect(config('cache.stores.redis.connection'))->toBe('cache');
    expect(config('cache.stores.redis.lock_connection'))->toBe('queue');
    expect(config('cache.stores.redis.driver'))->toBe('valkey-cache');
    expect(config('cache.stores.queue.connection'))->toBe('queue');
});

it('moves rate limits and schedule mutexes to the queue store when the cache store is Valkey', function () {
    $valkey = loadConfig('cache', ['CACHE_STORE' => 'redis']);
    $other = loadConfig('cache', ['CACHE_STORE' => 'database']);

    expect($valkey['limiter'])->toBe('queue')->and($valkey['schedule_store'])->toBe('queue');
    expect($other['limiter'])->toBeNull()->and($other['schedule_store'])->toBeNull();
});

it('keeps sessions on the database driver by default', function () {
    // phpunit.xml overrides SESSION_DRIVER, so read the default the config file ships with.
    expect(file_get_contents(dirname(__DIR__, 2).'/config/session.php'))->toContain("env('SESSION_DRIVER', 'database')");
    expect(file_get_contents(dirname(__DIR__, 2).'/compose.yaml'))->toContain('SESSION_DRIVER: database');
    expect(file_get_contents(dirname(__DIR__, 2).'/.env.example'))->toContain('SESSION_DRIVER=database');
});

it('connects each store over TLS with a verified CA when configured', function () {
    $redis = loadConfig('database', [
        'VALKEY_QUEUE_SCHEME' => 'tls',
        'VALKEY_QUEUE_HOST' => 'valkey-queue',
        'VALKEY_USERNAME' => 'web',
        'VALKEY_PASSWORD' => 'secret',
        'VALKEY_TLS_CA' => '/run/valkey-tls/ca.crt',
    ])['redis'];

    expect($redis['queue'])->toMatchArray(['scheme' => 'tls', 'host' => 'valkey-queue', 'username' => 'web', 'password' => 'secret'])
        ->and($redis['queue']['context']['stream'])->toMatchArray([
            'cafile' => '/run/valkey-tls/ca.crt',
            'verify_peer' => true,
            'verify_peer_name' => true,
        ]);
    expect($redis['cache']['scheme'])->toBe('tcp')->and($redis['cache']['context'])->toBe([]);
});

it('leaves the PostgreSQL connection unchanged when the PgBouncer switch is off', function () {
    $off = loadConfig('database', ['DB_PGBOUNCER' => 'false'])['connections']['pgsql'];
    $unset = loadConfig('database')['connections']['pgsql'];

    expect($off)->toBe($unset)
        ->and($off['options'])->not->toHaveKey(PDO::ATTR_EMULATE_PREPARES);
});

it('applies transaction-mode settings when the PgBouncer switch is on', function () {
    $on = loadConfig('database', ['DB_PGBOUNCER' => 'true'])['connections']['pgsql'];

    expect($on['options'][PDO::ATTR_EMULATE_PREPARES])->toBeTrue()
        ->and($on['options'][PDO::ATTR_TIMEOUT])->toBe(5);
});

it('registers the schedule mutex on the queue store when configured', function () {
    config(['cache.schedule_store' => 'queue']);

    require dirname(__DIR__, 2).'/routes/console.php';

    $schedule = app(Schedule::class);
    $mutex = (new ReflectionProperty($schedule, 'eventMutex'))->getValue($schedule);

    expect($mutex->store)->toBe('queue');
});

it('takes the Reverb scaling server from the queue store settings', function () {
    $server = loadConfig('reverb', [
        'VALKEY_QUEUE_HOST' => 'valkey-queue',
        'VALKEY_QUEUE_SCHEME' => 'tls',
        'VALKEY_QUEUE_PORT' => '6380',
        'VALKEY_USERNAME' => 'realtime',
        'VALKEY_PASSWORD' => 'pw-fallback',
    ])['servers']['reverb']['scaling']['server'];

    expect($server)->toMatchArray([
        'scheme' => 'tls', 'host' => 'valkey-queue', 'port' => '6380',
        'username' => 'realtime', 'password' => 'pw-fallback',
    ]);

    $override = loadConfig('reverb', [
        'VALKEY_USERNAME' => 'realtime',
        'VALKEY_PASSWORD' => 'pw-fallback',
        'VALKEY_QUEUE_USERNAME' => 'default',
        'VALKEY_QUEUE_PASSWORD' => 'pw-queue',
    ])['servers']['reverb']['scaling']['server'];

    expect($override)->toMatchArray(['username' => 'default', 'password' => 'pw-queue']);
});
