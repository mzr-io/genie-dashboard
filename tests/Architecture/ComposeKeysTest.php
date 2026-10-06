<?php

use Symfony\Component\Yaml\Yaml;

/** AR-50: which key purposes each process role may mount. */
const EXPECTED_KEY_MOUNTS = [
    'web' => ['data', 'digest'],
    'worker-connector' => ['cred', 'data', 'token'],
    'worker-compute' => ['data'],
    'realtime' => [],
    'scheduler' => [],
];

function composeFile(): array
{
    return Yaml::parseFile(dirname(__DIR__, 2).'/compose.yaml');
}

it('mounts key purposes only into the roles AR-50 allows', function () {
    $services = composeFile()['services'];

    foreach (EXPECTED_KEY_MOUNTS as $service => $expected) {
        $mounted = collect($services[$service]['secrets'] ?? [])
            ->map(fn ($secret) => is_array($secret) ? $secret['source'] : $secret)
            ->map(fn ($name) => str_starts_with($name, 'key-') ? substr($name, 4) : "other:{$name}")
            ->sort()->values()->all();

        expect($mounted)->toBe($expected, "service {$service}");
    }
});

it('mounts no key into any other service', function () {
    $services = composeFile()['services'];

    foreach (array_diff(array_keys($services), array_keys(EXPECTED_KEY_MOUNTS)) as $service) {
        expect($services[$service]['secrets'] ?? [])->toBe([], "service {$service}");
    }
});

it('backs every key secret with a placeholder file', function () {
    $secrets = composeFile()['secrets'];

    expect(array_keys($secrets))->toEqualCanonicalizing(['key-data', 'key-digest', 'key-cred', 'key-token']);
    foreach ($secrets as $secret) {
        expect($secret['file'])->toEndWith('.placeholder');
        expect(file_exists(dirname(__DIR__, 2).'/'.ltrim($secret['file'], './')))->toBeTrue();
    }
});

it('runs every role from the same image', function () {
    $services = composeFile()['services'];
    $roles = array_keys(EXPECTED_KEY_MOUNTS);

    expect(collect($roles)->map(fn ($role) => $services[$role]['image'])->unique()->count())->toBe(1);
    expect($services['migrator']['image'])->toBe($services['web']['image']);
});
