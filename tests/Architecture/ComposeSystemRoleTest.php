<?php

use Symfony\Component\Yaml\Yaml;

it('gives the system database credentials to worker-compute only', function () {
    $compose = Yaml::parseFile(dirname(__DIR__, 2).'/compose.yaml');

    foreach ($compose['services'] as $name => $service) {
        $keys = array_filter(array_keys($service['environment'] ?? []), fn ($key) => str_starts_with((string) $key, 'DB_SYSTEM_'));

        if ($name === 'worker-compute') {
            expect($keys)->toContain('DB_SYSTEM_USERNAME')->toContain('DB_SYSTEM_PASSWORD')->toContain('DB_SYSTEM_HOST');
        } else {
            expect($keys)->toBe([], "service {$name} must not hold DB_SYSTEM_* credentials");
        }
    }

    // Shared environment anchors must not carry them either.
    expect(array_filter(array_keys($compose['x-app-env']), fn ($key) => str_starts_with((string) $key, 'DB_SYSTEM_')))->toBe([]);
});
