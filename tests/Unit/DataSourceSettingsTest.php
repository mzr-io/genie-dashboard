<?php

use App\Modules\Connector\Infrastructure\DataSourceSettings;
use App\Platform\Tenancy\WorkspaceSettings;
use Illuminate\Config\Repository;

// Story 2.3: the platform ceilings and the deployment half of `require_https`. An unset ceiling is not checked (no
// number is invented); the deployment setting is off unless the environment turns it on.

function settingsWith(array $values): DataSourceSettings
{
    return new DataSourceSettings(new Repository(['dashflow' => ['tunables' => ['guards' => $values]]]), new WorkspaceSettings);
}

it('reads each ceiling that is a positive whole number and nothing else', function () {
    $ceilings = settingsWith([
        'platform_timeout_ceiling' => ['value' => '60'],
        'max_bytes' => ['value' => 5_000_000],
        'max_pages' => ['value' => 'many'],
    ])->ceilings();

    expect([$ceilings->timeoutSeconds, $ceilings->maxResponseBytes, $ceilings->maxPages])->toBe([60, 5_000_000, null]);
});

it('treats an unset, zero, negative or fractional ceiling as not set', function (mixed $value) {
    expect(settingsWith(['max_pages' => ['value' => $value]])->ceilings()->maxPages)->toBeNull();
})->with([null, '', '0', '-3', '1.5', ' ']);

it('turns the deployment require_https on only for a true value', function (mixed $value, bool $expected) {
    expect(settingsWith(['require_https' => ['value' => $value]])->deploymentRequiresHttps())->toBe($expected);
})->with([
    [false, false],
    [null, false],
    ['', false],
    ['false', false],
    ['0', false],
    [true, true],
    ['true', true],
    ['1', true],
    ['on', true],
]);
