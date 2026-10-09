<?php

use App\Modules\Connector\Infrastructure\EgressSettings;
use Illuminate\Config\Repository;
use Symfony\Component\Yaml\Yaml;

// Story 2.2: the outbound guard's settings are pending_input with no default, kept outside the AR-57 tunables.

const EGRESS_SETTINGS = [
    'deployment_cidrs' => 'DASHFLOW_EGRESS_DEPLOYMENT_CIDRS',
    'alert_threshold' => 'DASHFLOW_EGRESS_ALERT_THRESHOLD',
    'alert_window' => 'DASHFLOW_EGRESS_ALERT_WINDOW',
    'operator_password_hash' => 'DASHFLOW_EGRESS_OPERATOR_PASSWORD_HASH',
];

it('declares every egress setting as an env-driven pending_input value with no default, outside tunables', function (string $key, string $env) {
    $setting = config("dashflow.egress.{$key}");

    expect($setting)->toBeArray()
        ->and($setting['env'])->toBe($env)
        ->and($setting['pending_input'])->toBeTrue()
        ->and($setting['value'])->toBeNull()
        ->and(config("dashflow.tunables.{$key}"))->toBeNull();
})->with(array_map(null, array_keys(EGRESS_SETTINGS), EGRESS_SETTINGS));

it('documents every egress setting in .env.example and the README', function (string $env) {
    $root = dirname(__DIR__, 2);

    expect(file_get_contents($root.'/.env.example'))->toContain($env)
        ->and(file_get_contents($root.'/README.md'))->toContain($env);
})->with(array_values(EGRESS_SETTINGS));

it('passes the egress settings to every role and the operator password hash to the operator service only', function () {
    $compose = Yaml::parseFile(dirname(__DIR__, 2).'/compose.yaml');

    foreach (['DASHFLOW_EGRESS_DEPLOYMENT_CIDRS', 'DASHFLOW_EGRESS_ALERT_THRESHOLD', 'DASHFLOW_EGRESS_ALERT_WINDOW'] as $env) {
        expect($compose['x-app-env'])->toHaveKey($env);
    }

    expect($compose['x-app-env'])->not->toHaveKey('DASHFLOW_EGRESS_OPERATOR_PASSWORD_HASH');

    foreach ($compose['services'] as $name => $service) {
        expect(array_key_exists('DASHFLOW_EGRESS_OPERATOR_PASSWORD_HASH', $service['environment'] ?? []))->toBe($name === 'operator', "service {$name}");
    }
});

it('reads the deployment CIDRs and treats an invalid one as a failure, not as nothing', function () {
    $settings = fn (?string $value) => new EgressSettings(new Repository(['dashflow' => ['egress' => ['deployment_cidrs' => ['value' => $value]]]]));

    expect($settings(null)->deploymentCidrs())->toBe([])
        ->and($settings('')->deploymentCidrs())->toBe([])
        ->and(array_map(fn ($cidr) => $cidr->text(), $settings('10.99.0.0/16, fd12:3456::/32')->deploymentCidrs()))->toBe(['10.99.0.0/16', 'fd12:3456::/32'])
        ->and(fn () => $settings('10.99.0.0/16, nope')->deploymentCidrs())->toThrow(InvalidArgumentException::class);
});

it('enables the alert only when the threshold and the window are both positive whole numbers', function (mixed $threshold, mixed $window, ?array $expected) {
    $settings = new EgressSettings(new Repository(['dashflow' => ['egress' => ['alert_threshold' => ['value' => $threshold], 'alert_window' => ['value' => $window]]]]));
    $rate = $settings->alert();

    expect($rate === null ? null : [$rate->threshold, $rate->windowSeconds])->toBe($expected);
})->with([
    [null, null, null],
    ['5', null, null],
    [null, '60', null],
    ['5', '60', [5, 60]],
    [5, 60, [5, 60]],
    ['0', '60', null],
    ['5', '0', null],
    ['-1', '60', null],
    ['five', '60', null],
    ['5.5', '60', null],
    ['', '', null],
]);
