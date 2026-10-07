<?php

use Symfony\Component\Yaml\Yaml;

const VALKEY_ROLES = ['web', 'realtime', 'scheduler', 'worker-connector', 'worker-compute'];

function valkeyCompose(): array
{
    return Yaml::parseFile(dirname(__DIR__, 2).'/compose.yaml');
}

function valkeyAcl(string $store): array
{
    $users = [];
    foreach (file(dirname(__DIR__, 2)."/docker/valkey/{$store}.acl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, 'user ')) {
            $parts = explode(' ', $line);
            $users[$parts[1]] = array_slice($parts, 2);
        }
    }

    return $users;
}

it('runs two Valkey instances with the right eviction policy and no persistence', function () {
    $services = valkeyCompose()['services'];

    expect($services)->not->toHaveKey('valkey');
    expect($services['valkey-queue']['environment']['VALKEY_MAXMEMORY_POLICY'])->toBe('noeviction');
    expect($services['valkey-cache']['environment']['VALKEY_MAXMEMORY_POLICY'])->toBe('allkeys-lru');
    expect($services['valkey-cache']['environment']['VALKEY_MAXMEMORY'])->not->toBeEmpty();

    $entrypoint = file_get_contents(dirname(__DIR__, 2).'/docker/valkey/entrypoint.sh');
    expect($entrypoint)->toContain('--save ""')->toContain('--appendonly no');
});

it('serves Valkey over TLS only', function () {
    $entrypoint = file_get_contents(dirname(__DIR__, 2).'/docker/valkey/entrypoint.sh');

    expect($entrypoint)->toContain('--port 0')->toContain('--tls-port 6379')
        ->toContain('--tls-cert-file')->toContain('--tls-key-file')->toContain('--tls-ca-cert-file');
    expect(valkeyCompose()['services']['valkey-queue']['healthcheck']['test'][1])->toContain('--tls');
    expect(valkeyCompose()['x-app-env']['VALKEY_QUEUE_SCHEME'])->toBe('tls')
        ->and(valkeyCompose()['x-app-env']['VALKEY_CACHE_SCHEME'])->toBe('tls');
});

it('defines an ACL user per role on both stores', function (string $store) {
    $users = valkeyAcl($store);

    // Reverb's client can only AUTH with a password, so on the queue store realtime is the `default` user.
    $names = $store === 'queue'
        ? [...array_diff(VALKEY_ROLES, ['realtime']), 'default', 'health']
        : [...VALKEY_ROLES, 'default', 'health'];
    expect(array_keys($users))->toEqualCanonicalizing($names);
    if ($store === 'cache') {
        expect($users['default'])->toBe(['off']);
    }

    foreach (VALKEY_ROLES as $role) {
        $user = $store === 'queue' && $role === 'realtime' ? 'default' : $role;
        expect($users[$user])->toContain('on');
        $placeholder = '>__PASSWORD_'.strtoupper(str_replace('-', '_', $role)).'__';
        expect($users[$user])->toContain($placeholder);
    }
})->with(['queue', 'cache']);

it('commits no password into the ACL files', function (string $store) {
    foreach (valkeyAcl($store) as $user => $rules) {
        foreach ($rules as $rule) {
            if (str_starts_with($rule, '>')) {
                expect($rule)->toMatch('/^>__PASSWORD_[A-Z_]+__$/', "{$store}/{$user}");
            }
        }
    }
})->with(['queue', 'cache']);

it('limits realtime to pub/sub on the queue store and reads on the cache store', function () {
    expect(valkeyAcl('queue')['default'])->toContain('resetkeys', '+@pubsub')->not->toContain('+@all');
    expect(valkeyAcl('cache')['realtime'])->toContain('+@read')->not->toContain('+@write', '+@all');
});

it('gives every role its own Valkey username and password, and the migrator none', function () {
    $services = valkeyCompose()['services'];
    $passwords = [];

    foreach (VALKEY_ROLES as $role) {
        $env = $services[$role]['environment'];
        expect($env['VALKEY_USERNAME'])->toBe($role);
        $passwords[] = $env['VALKEY_PASSWORD'];
        expect($services[$role]['depends_on'])->toHaveKeys(['valkey-queue', 'valkey-cache']);
    }

    expect(array_unique($passwords))->toHaveCount(count(VALKEY_ROLES));
    expect($services['migrator']['environment'])->not->toHaveKey('VALKEY_USERNAME');
    expect($services['migrator']['depends_on'])->not->toHaveKeys(['valkey-queue', 'valkey-cache']);
});

it('keeps the server key away from application roles', function () {
    $services = valkeyCompose()['services'];

    foreach (VALKEY_ROLES as $role) {
        expect(json_encode($services[$role]['volumes'] ?? []))->not->toContain('valkey-server-tls');
    }
    expect($services['valkey-queue']['volumes'])->toContain('valkey-server-tls:/tls:ro');
});

it('splits queue and cache ACLs only by role, never by shared passwords in the repo', function () {
    $compose = file_get_contents(dirname(__DIR__, 2).'/compose.yaml');

    expect($compose)->not->toMatch('/BEGIN (RSA |EC )?PRIVATE KEY/');
});

it('allows FLUSHDB for application roles on the cache store only', function () {
    foreach (['web', 'scheduler', 'worker-connector', 'worker-compute'] as $role) {
        expect(valkeyAcl('cache')[$role])->toContain('+flushdb');
        expect(valkeyAcl('queue')[$role])->not->toContain('+flushdb');
    }
    expect(valkeyAcl('cache')['realtime'])->not->toContain('+flushdb');
});
