<?php

use App\Modules\Connector\Contracts\CallOutcome;
use App\Modules\Connector\Contracts\GovernorLimits;
use App\Modules\Connector\Contracts\SourceGovernor;
use App\Modules\Ingestion\Application\EvaluateSourceHealth;
use App\Modules\Ingestion\Application\HealthEvidence;
use App\Modules\Ingestion\Application\HealthRules;
use App\Modules\Ingestion\Contracts\SourceHealth;
use App\Modules\Ingestion\Infrastructure\HealthSettings;
use Illuminate\Config\Repository;
use Tests\Unit\Support\FakeGovernor;

// Story 2.18: the pure decision of a Data Source's health, and the settings that feed it. First matching rule wins; a rule whose settings are
// unset or malformed is inert, so with none set the probe and the breaker alone decide.

/** The rules of a deployment that set everything: unreachable after 3 failed runs, healthy from 80 %, degraded from 50 %, a one-hour window. */
function fullRules(): HealthRules
{
    return new HealthRules(3, 3600, 80, 50);
}

function evidence(array $with = []): HealthEvidence
{
    $with += ['probed' => false, 'probeOk' => false, 'hasFinalRun' => false, 'breaker' => 'closed', 'trailing' => 0, 'succeeded' => 0, 'failed' => 0];

    return new HealthEvidence($with['probed'], $with['probeOk'], $with['hasFinalRun'], $with['breaker'], $with['trailing'], $with['succeeded'], $with['failed']);
}

function healthSettings(array $health): HealthSettings
{
    $config = [];

    foreach ($health as $name => $value) {
        $config[$name] = ['value' => $value];
    }

    return new HealthSettings(new Repository(['dashflow' => ['tunables' => ['health' => $config]]]));
}

it('is Checking, never Healthy, with no probe result and no final run (rule 1)', function (HealthRules $rules) {
    expect(EvaluateSourceHealth::decide(evidence(), $rules))->toBe(SourceHealth::CHECKING)
        // Even an open breaker cannot outrank "no evidence yet".
        ->and(EvaluateSourceHealth::decide(evidence(['breaker' => 'open']), $rules))->toBe(SourceHealth::CHECKING);
})->with([[fn () => new HealthRules], [fn () => fullRules()]]);

it('is Unreachable while the breaker is open or half-open (rule 2), whatever the runs say', function (string $state) {
    $e = evidence(['hasFinalRun' => true, 'succeeded' => 10, 'breaker' => $state]);

    expect(EvaluateSourceHealth::decide($e, fullRules()))->toBe(SourceHealth::UNREACHABLE)
        ->and(EvaluateSourceHealth::decide($e, new HealthRules))->toBe(SourceHealth::UNREACHABLE);
})->with(['open', 'half_open']);

it('is Unreachable when the trailing failed runs reach the threshold (rule 3) and only then', function () {
    $base = ['hasFinalRun' => true, 'succeeded' => 9, 'failed' => 3];

    expect(EvaluateSourceHealth::decide(evidence($base + ['trailing' => 3]), fullRules()))->toBe(SourceHealth::UNREACHABLE)
        // Two trailing failures: rule 3 does not match, rule 4 reads 9 of 12 = 75 %.
        ->and(EvaluateSourceHealth::decide(evidence($base + ['trailing' => 2]), fullRules()))->toBe(SourceHealth::DEGRADED);
});

it('maps the success percentage of the window to Healthy, Degraded or Unreachable (rule 4)', function (int $succeeded, int $failed, string $expected) {
    $e = evidence(['hasFinalRun' => true, 'succeeded' => $succeeded, 'failed' => $failed, 'trailing' => 1]);

    expect(EvaluateSourceHealth::decide($e, fullRules()))->toBe($expected);
})->with([
    'nine of ten, two of them 304s, is healthy' => [9, 1, SourceHealth::HEALTHY],
    'exactly the healthy threshold' => [4, 1, SourceHealth::HEALTHY],
    'sixty percent is degraded' => [3, 2, SourceHealth::DEGRADED],
    'exactly the degraded threshold' => [1, 1, SourceHealth::DEGRADED],
    'below the degraded threshold' => [1, 2, SourceHealth::UNREACHABLE],
    'whole percent: 79.9 is not 80' => [799, 201, SourceHealth::DEGRADED],
]);

it('falls to the latest probe when the window holds no run (rules 4 and 5)', function (bool $ok, string $expected) {
    $e = evidence(['probed' => true, 'probeOk' => $ok, 'hasFinalRun' => true]);

    expect(EvaluateSourceHealth::decide($e, fullRules()))->toBe($expected);
})->with([[true, SourceHealth::HEALTHY], [false, SourceHealth::UNREACHABLE]]);

it('lets the probe decide a source with no Endpoints and no runs (rule 5)', function (bool $ok, string $expected) {
    expect(EvaluateSourceHealth::decide(evidence(['probed' => true, 'probeOk' => $ok]), fullRules()))->toBe($expected)
        ->and(EvaluateSourceHealth::decide(evidence(['probed' => true, 'probeOk' => $ok]), new HealthRules))->toBe($expected);
})->with([[true, SourceHealth::HEALTHY], [false, SourceHealth::UNREACHABLE]]);

it('skips rules 3 and 4 with unset settings: no number is invented, runs outrank a stale probe only by their latest outcome', function () {
    $failing = evidence(['probed' => true, 'probeOk' => true, 'hasFinalRun' => true, 'failed' => 50, 'trailing' => 50]);
    $passing = evidence(['probed' => true, 'probeOk' => true, 'hasFinalRun' => true, 'succeeded' => 5, 'trailing' => 0]);

    expect(EvaluateSourceHealth::decide($failing, new HealthRules))->toBe(SourceHealth::DEGRADED)
        ->and(EvaluateSourceHealth::decide($passing, new HealthRules))->toBe(SourceHealth::HEALTHY)
        // No probe either: runs exist but nothing may judge them, so it stays Checking.
        ->and(EvaluateSourceHealth::decide(evidence(['hasFinalRun' => true, 'succeeded' => 3]), new HealthRules))->toBe(SourceHealth::CHECKING);
});

it('reads each setting as a positive whole number, and the percentages as whole 1-100 with healthy above degraded', function () {
    $rules = healthSettings(['threshold_unreachable' => '3', 'window' => '3600', 'threshold_healthy' => '80', 'threshold_degraded' => 50])->rules();

    expect($rules->unreachableAfter)->toBe(3)->and($rules->windowSeconds)->toBe(3600)
        ->and($rules->healthyPercent)->toBe(80)->and($rules->degradedPercent)->toBe(50)->and($rules->percentRuleActive())->toBeTrue();
});

it('turns a rule off when its settings are unset or malformed', function (array $health, bool $unreachable, bool $percent) {
    $rules = healthSettings($health)->rules();

    expect($rules->unreachableAfter !== null)->toBe($unreachable)->and($rules->percentRuleActive())->toBe($percent);
})->with([
    'nothing set' => [[], false, false],
    'window missing' => [['threshold_healthy' => '80', 'threshold_degraded' => '50'], false, false],
    'healthy missing' => [['window' => '60', 'threshold_degraded' => '50'], false, false],
    'healthy not above degraded' => [['window' => '60', 'threshold_healthy' => '50', 'threshold_degraded' => '50'], false, false],
    'healthy below degraded' => [['window' => '60', 'threshold_healthy' => '40', 'threshold_degraded' => '50'], false, false],
    'percentage above 100' => [['window' => '60', 'threshold_healthy' => '101', 'threshold_degraded' => '50'], false, false],
    'percentage zero' => [['window' => '60', 'threshold_healthy' => '80', 'threshold_degraded' => '0'], false, false],
    'percentage fraction' => [['window' => '60', 'threshold_healthy' => '80.5', 'threshold_degraded' => '50'], false, false],
    'window zero' => [['window' => '0', 'threshold_healthy' => '80', 'threshold_degraded' => '50'], false, false],
    'window text' => [['window' => '1h', 'threshold_healthy' => '80', 'threshold_degraded' => '50'], false, false],
    'unreachable alone' => [['threshold_unreachable' => '3'], true, false],
    'unreachable zero' => [['threshold_unreachable' => '0'], false, false],
    'unreachable fraction' => [['threshold_unreachable' => '2.5'], false, false],
    'all valid' => [['threshold_unreachable' => '2', 'window' => '60', 'threshold_healthy' => '90', 'threshold_degraded' => '60'], true, true],
]);

it('reads the probe interval as a positive whole number of seconds, else null', function (mixed $value, ?int $expected) {
    expect(healthSettings(['probe_interval' => $value])->probeInterval())->toBe($expected);
})->with([['300', 300], [60, 60], [null, null], ['', null], ['0', null], ['-5', null], ['1.5', null], ['soon', null]]);

it('invents no number: with nothing configured every rule is off', function () {
    expect(healthSettings([])->rules())->toEqual(new HealthRules)->and(healthSettings([])->probeInterval())->toBeNull();
});

it('reports the breaker read-only: closed with nothing, open inside the cool-down, half-open after it, closed when the breaker is inert', function () {
    $governor = new FakeGovernor;
    $limits = new GovernorLimits(2, 30);

    expect($governor->state('w', 'd', $limits))->toBe(SourceGovernor::STATE_CLOSED);

    $governor->record('w', 'd', CallOutcome::Failed, $limits);
    $governor->record('w', 'd', CallOutcome::Failed, $limits);
    expect($governor->state('w', 'd', $limits))->toBe(SourceGovernor::STATE_OPEN);

    $governor->now += 31;
    expect($governor->state('w', 'd', $limits))->toBe(SourceGovernor::STATE_HALF_OPEN)
        // Reading changed nothing: still half-open, and an inert breaker reads closed.
        ->and($governor->state('w', 'd', $limits))->toBe(SourceGovernor::STATE_HALF_OPEN)
        ->and($governor->state('w', 'd', new GovernorLimits))->toBe(SourceGovernor::STATE_CLOSED);
});
