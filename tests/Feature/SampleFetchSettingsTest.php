<?php

use App\Modules\Connector\Infrastructure\SampleFetchSettings;
use App\Platform\Operations\OperationOutcome;
use App\Platform\Operations\OperationStatus;
use Illuminate\Config\Repository;
use Symfony\Component\Yaml\Yaml;

// Story 2.10: the Fetch sample rate limits and the `data` key path are env-driven pending_input settings, outside the AR-57
// tunables, with no default (the key path defaults to the Compose mount), passed to every role and documented.

const SAMPLE_FETCH_SETTINGS = [
    'membership_limit' => 'DASHFLOW_SAMPLE_FETCH_MEMBERSHIP_LIMIT',
    'workspace_limit' => 'DASHFLOW_SAMPLE_FETCH_WORKSPACE_LIMIT',
    'window' => 'DASHFLOW_SAMPLE_FETCH_WINDOW_SECONDS',
];

it('maps every env name to dashflow.sample_fetch.* as a pending_input value with no default, outside tunables', function (string $key, string $env) {
    $setting = config("dashflow.sample_fetch.{$key}");

    expect($setting)->toBeArray()
        ->and($setting['env'])->toBe($env)
        ->and($setting['pending_input'])->toBeTrue()
        ->and($setting['value'])->toBeNull()
        ->and(config("dashflow.tunables.{$key}"))->toBeNull();
})->with(array_map(null, array_keys(SAMPLE_FETCH_SETTINGS), SAMPLE_FETCH_SETTINGS));

it('declares the data key path with the Compose secret mount as its default', function () {
    expect(config('dashflow.secrets.data_key_path.env'))->toBe('DASHFLOW_SECRETS_DATA_KEY_PATH')
        ->and(config('dashflow.secrets.data_key_path.value'))->toBe('/run/secrets/key-data');
});

it('passes the settings to every role and documents them in .env.example, the README and compose.yaml', function (string $env) {
    $root = dirname(__DIR__, 2);
    $compose = Yaml::parseFile($root.'/compose.yaml');

    expect($compose['x-app-env'])->toHaveKey($env)
        ->and(file_get_contents($root.'/.env.example'))->toContain($env)
        ->and(file_get_contents($root.'/README.md'))->toContain($env);
})->with([...array_values(SAMPLE_FETCH_SETTINGS), 'DASHFLOW_SECRETS_DATA_KEY_PATH']);

it('limits only with a window and a positive whole number, and invents nothing', function (array $config, ?int $window, ?int $member, ?int $workspace) {
    $settings = new SampleFetchSettings(new Repository(['dashflow' => ['sample_fetch' => $config]]));

    expect([$settings->window(), $settings->membershipLimit(), $settings->workspaceLimit()])->toBe([$window, $member, $workspace]);
})->with([
    'nothing set' => [[], null, null, null],
    'all set' => [['window' => ['value' => '60'], 'membership_limit' => ['value' => '5'], 'workspace_limit' => ['value' => 20]], 60, 5, 20],
    'limits without a window' => [['membership_limit' => ['value' => '5'], 'workspace_limit' => ['value' => '9']], null, null, null],
    'zero, negative and text do not count' => [['window' => ['value' => '60'], 'membership_limit' => ['value' => '0'], 'workspace_limit' => ['value' => 'many']], 60, null, null],
    'a window of zero is no window' => [['window' => ['value' => '0'], 'membership_limit' => ['value' => '5']], null, null, null],
]);

it('lets a handler end an Operation as stale, never as a success', function () {
    $stale = OperationOutcome::stale(['ok' => false, 'endpoint_revision' => 5]);

    expect($stale->stale)->toBeTrue()
        ->and($stale->succeeded)->toBeFalse()
        ->and($stale->status())->toBe(OperationStatus::Stale)
        ->and((new OperationOutcome(true, []))->status())->toBe(OperationStatus::Succeeded)
        ->and((new OperationOutcome(false, []))->status())->toBe(OperationStatus::Failed)
        ->and(fn () => new OperationOutcome(true, [], true))->toThrow(InvalidArgumentException::class);
});
