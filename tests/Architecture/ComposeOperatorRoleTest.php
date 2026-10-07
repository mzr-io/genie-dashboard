<?php

use Symfony\Component\Yaml\Yaml;

it('gives the operator database credentials to the one-off operator service only, never to web or workers', function () {
    $compose = Yaml::parseFile(dirname(__DIR__, 2).'/compose.yaml');

    foreach ($compose['services'] as $name => $service) {
        $keys = array_filter(array_keys($service['environment'] ?? []), fn ($key) => str_starts_with((string) $key, 'DB_OPERATOR_'));

        if ($name === 'operator') {
            expect($keys)->toContain('DB_OPERATOR_USERNAME')->toContain('DB_OPERATOR_PASSWORD')->toContain('DB_OPERATOR_HOST')
                ->and($service['profiles'])->toBe(['operator']);
        } else {
            expect($keys)->toBe([], "service {$name} must not hold DB_OPERATOR_* credentials");
        }
    }

    expect(array_filter(array_keys($compose['x-app-env']), fn ($key) => str_starts_with((string) $key, 'DB_OPERATOR_')))->toBe([]);
});
