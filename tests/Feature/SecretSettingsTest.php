<?php

use Symfony\Component\Yaml\Yaml;

// Story 2.4: the secret settings are env-driven, kept outside the AR-57 tunables, passed to every role and documented.
// A typo here would make every credential save answer 503 in a deployed stack.

const SECRET_SETTINGS = [
    'cred_public_key' => 'DASHFLOW_SECRETS_CRED_PUBLIC_KEY',
    'cred_key_version' => 'DASHFLOW_SECRETS_CRED_KEY_VERSION',
    'cred_key_path' => 'DASHFLOW_SECRETS_CRED_KEY_PATH',
];

it('maps every env name to dashflow.secrets.* as a pending_input value, outside tunables', function (string $key, string $env) {
    $setting = config("dashflow.secrets.{$key}");

    expect($setting)->toBeArray()
        ->and($setting['env'])->toBe($env)
        ->and($setting['pending_input'])->toBeTrue()
        ->and(config("dashflow.tunables.{$key}"))->toBeNull();
})->with(array_map(null, array_keys(SECRET_SETTINGS), SECRET_SETTINGS));

it('has no default for the public key and version, and the Compose secret mount as the key path default', function () {
    expect(config('dashflow.secrets.cred_public_key.value'))->toBeNull()
        ->and(config('dashflow.secrets.cred_key_version.value'))->toBeNull()
        ->and(config('dashflow.secrets.cred_key_path.value'))->toBe('/run/secrets/key-cred');
});

it('passes all three secret settings to every role through the shared env block', function (string $env) {
    $compose = Yaml::parseFile(dirname(__DIR__, 2).'/compose.yaml');

    expect($compose['x-app-env'])->toHaveKey($env);
})->with(array_values(SECRET_SETTINGS));

it('documents every secret setting in .env.example and the README', function (string $env) {
    $root = dirname(__DIR__, 2);

    expect(file_get_contents($root.'/.env.example'))->toContain($env)
        ->and(file_get_contents($root.'/README.md'))->toContain($env);
})->with(array_values(SECRET_SETTINGS));
