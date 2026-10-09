<?php

use App\Modules\Connector\Application\EndpointFetchLadder;
use App\Modules\Connector\Application\RetryAfter;
use App\Modules\Connector\Contracts\Admission;
use App\Modules\Connector\Contracts\CallOutcome;
use App\Modules\Connector\Contracts\EgressTransportFailed;
use App\Modules\Connector\Contracts\FailureClass;
use App\Modules\Connector\Contracts\GovernorLimits;
use App\Modules\Connector\Contracts\NotJsonResponse;
use App\Modules\Connector\Contracts\PageFailed;
use App\Modules\Connector\Contracts\PaginationFailed;
use App\Modules\Connector\Contracts\SecretMissing;
use App\Modules\Connector\Infrastructure\RandomJitter;
use App\Modules\Connector\Infrastructure\ValkeySourceGovernor;
use App\Modules\Ingestion\Application\FetchJob;
use App\Modules\Ingestion\Infrastructure\RetryPolicy;
use App\Modules\Ingestion\Infrastructure\SyncSettings;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Tests\Unit\Support\FakeGovernor;

uses(TestCase::class);

// Story 2.17: the failure classes, Retry-After, the retry policy, the settings readers and the Valkey adapter's fail-open.

function ladderClass(Throwable $e, bool $post = false): FailureClass
{
    return (new EndpointFetchLadder)->classify($e, hrtime(true), [], 'test', $post)->class;
}

it('classifies transport errors: transient for a GET, ambiguous for a POST unless the request cannot have been sent, TLS is configuration', function () {
    expect(ladderClass(new EgressTransportFailed('x', 28)))->toBe(FailureClass::Transient)
        ->and(ladderClass(new EgressTransportFailed('x', 56)))->toBe(FailureClass::Transient)
        ->and(ladderClass(new EgressTransportFailed('x', null)))->toBe(FailureClass::Transient)
        ->and(ladderClass(new EgressTransportFailed('x', 28), true))->toBe(FailureClass::Ambiguous)
        ->and(ladderClass(new EgressTransportFailed('x', 56), true))->toBe(FailureClass::Ambiguous)
        ->and(ladderClass(new EgressTransportFailed('x', 6), true))->toBe(FailureClass::Transient)
        ->and(ladderClass(new EgressTransportFailed('x', 7), true))->toBe(FailureClass::Transient)
        ->and(ladderClass(new EgressTransportFailed('x', 35), true))->toBe(FailureClass::Transient)
        ->and(ladderClass(new EgressTransportFailed('x', 35)))->toBe(FailureClass::Transient)
        ->and(ladderClass(new EgressTransportFailed('x', 51), true))->toBe(FailureClass::Configuration)
        ->and(ladderClass(new EgressTransportFailed('x', 60)))->toBe(FailureClass::Configuration);
});

it('classifies bad bodies and limits as data, secrets as configuration, and judges a paged failure on its cause', function () {
    expect(ladderClass(new NotJsonResponse('parse')))->toBe(FailureClass::Data)
        ->and(ladderClass(new PaginationFailed('cursor')))->toBe(FailureClass::Data)
        ->and(ladderClass(new SecretMissing))->toBe(FailureClass::Configuration)
        ->and(ladderClass(new PageFailed(2, new EgressTransportFailed('x', 28))))->toBe(FailureClass::Transient)
        ->and(ladderClass(new PageFailed(2, new EgressTransportFailed('x', 28)), true))->toBe(FailureClass::Ambiguous)
        ->and(ladderClass(new PageFailed(2, new NotJsonResponse('parse'))))->toBe(FailureClass::Data)
        ->and(ladderClass(new RuntimeException('unexpected')))->toBe(FailureClass::Configuration);
});

it('classifies an HTTP status: 429 and a 503 with a valid Retry-After throttle, 408 and 5xx are transient, a POST 5xx is ambiguous, other 4xx are configuration', function () {
    $ladder = new EndpointFetchLadder;

    expect($ladder->classifyStatus(429, null, false))->toBe(FailureClass::Throttled)
        ->and($ladder->classifyStatus(429, 5, true))->toBe(FailureClass::Throttled)
        ->and($ladder->classifyStatus(503, 0, false))->toBe(FailureClass::Throttled)
        ->and($ladder->classifyStatus(503, null, false))->toBe(FailureClass::Transient)
        ->and($ladder->classifyStatus(500, null, false))->toBe(FailureClass::Transient)
        ->and($ladder->classifyStatus(502, 9, false))->toBe(FailureClass::Transient)
        ->and($ladder->classifyStatus(500, null, true))->toBe(FailureClass::Ambiguous)
        ->and($ladder->classifyStatus(408, null, false))->toBe(FailureClass::Transient)
        ->and($ladder->classifyStatus(408, null, true))->toBe(FailureClass::Ambiguous)
        ->and($ladder->classifyStatus(404, null, false))->toBe(FailureClass::Configuration)
        ->and($ladder->classifyStatus(401, null, false))->toBe(FailureClass::Configuration)
        ->and(FailureClass::Transient->retryable() && FailureClass::Throttled->retryable())->toBeTrue()
        ->and(FailureClass::Configuration->retryable() || FailureClass::Data->retryable() || FailureClass::Ambiguous->retryable())->toBeFalse();
});

it('parses Retry-After as delta-seconds or an HTTP-date, and treats anything else as absent', function () {
    $now = new DateTimeImmutable('2026-10-08 12:00:00', new DateTimeZone('UTC'));
    $one = fn (string $value): ?int => RetryAfter::seconds(['retry-after' => [$value]], $now);

    expect($one('9999'))->toBe(9999)
        ->and($one(' 30 '))->toBe(30)
        ->and($one('0'))->toBe(0)
        ->and($one('Thu, 08 Oct 2026 12:01:30 GMT'))->toBe(90)
        ->and($one('Thursday, 08-Oct-26 12:00:10 GMT'))->toBe(10)
        ->and($one('Thu Oct  8 12:00:20 2026'))->toBe(20)
        ->and($one('Thu, 08 Oct 2026 11:00:00 GMT'))->toBe(0)
        ->and($one('-5'))->toBeNull()
        ->and($one('1.5'))->toBeNull()
        ->and($one(''))->toBeNull()
        ->and($one('soon'))->toBeNull()
        ->and($one('Thu, 32 Oct 2026 12:00:00 GMT'))->toBeNull()
        ->and(RetryAfter::seconds([], $now))->toBeNull()
        ->and(RetryAfter::seconds(['retry-after' => ['5', '6']], $now))->toBeNull();
});

it('keeps the full-jitter delay within 0 and min(cap, base * 2^(attempt-1))', function () {
    $policy = new RetryPolicy(2, 60, 6);

    expect($policy->ceiling(1))->toBe(2)
        ->and($policy->ceiling(2))->toBe(4)
        ->and($policy->ceiling(5))->toBe(32)
        ->and($policy->ceiling(6))->toBe(60)
        ->and($policy->ceiling(500))->toBe(60);

    $jitter = new RandomJitter;

    foreach ([1, 2, 3, 6] as $attempt) {
        for ($i = 0; $i < 200; $i++) {
            expect($jitter->upTo($policy->ceiling($attempt)))->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual($policy->ceiling($attempt));
        }
    }

    expect($jitter->upTo(0))->toBe(0);
});

function fetchSettings(array $values): SyncSettings
{
    $wrap = fn (array $group): array => array_map(fn ($v) => ['value' => $v], $group);

    return new SyncSettings(new Repository(['dashflow' => [
        'tunables' => [
            'retry' => $wrap($values['retry'] ?? []),
            'circuit_breaker' => $wrap($values['circuit_breaker'] ?? []),
            'budgets' => $wrap($values['budgets'] ?? []),
            'guards' => $wrap($values['guards'] ?? []),
        ],
        'fetch' => $wrap($values['fetch'] ?? []),
    ]]));
}

it('keeps retry, breaker, bucket, concurrency and fairness inert unless their numbers are positive whole numbers', function () {
    $unset = fetchSettings([]);
    expect($unset->retry())->toBeNull()
        ->and($unset->governorLimits()->active())->toBeFalse()
        ->and($unset->workspaceFairShare())->toBeNull();

    // All three retry numbers are needed; the cap alone still serves the penalty.
    $partial = fetchSettings(['retry' => ['base' => '2', 'cap' => '60']]);
    expect($partial->retry())->toBeNull()
        ->and($partial->retryCap())->toBe(60)
        ->and($partial->governorLimits()->penaltyCapSeconds)->toBe(60);

    expect(fetchSettings(['retry' => ['base' => '2', 'cap' => '60', 'max_attempts' => '3']])->retry())->toEqual(new RetryPolicy(2, 60, 3))
        ->and(fetchSettings(['retry' => ['base' => '0', 'cap' => '60', 'max_attempts' => '3']])->retry())->toBeNull()
        ->and(fetchSettings(['retry' => ['base' => '1.5', 'cap' => '60', 'max_attempts' => '3']])->retry())->toBeNull()
        ->and(fetchSettings(['retry' => ['base' => '2', 'cap' => '-1', 'max_attempts' => '3']])->retry())->toBeNull();

    // The breaker needs both numbers.
    expect(fetchSettings(['circuit_breaker' => ['failure_count' => '5']])->governorLimits()->breakerActive())->toBeFalse()
        ->and(fetchSettings(['circuit_breaker' => ['failure_count' => '5', 'cool_down' => 'x']])->governorLimits()->breakerActive())->toBeFalse();

    $full = fetchSettings([
        'circuit_breaker' => ['failure_count' => '5', 'cool_down' => '120'], 'budgets' => ['max_fetch_rate_per_data_source' => '30'],
        'guards' => ['platform_timeout_ceiling' => '90'], 'fetch' => ['data_source_concurrency' => '2', 'workspace_fair_share' => '10'],
    ]);
    expect($full->governorLimits())->toEqual(new GovernorLimits(5, 120, 30, 2, 90, null))
        ->and($full->workspaceFairShare())->toBe(10)
        ->and(fetchSettings(['budgets' => ['max_fetch_rate_per_data_source' => '1.5']])->governorLimits()->ratePerMinute)->toBeNull();
});

it('does not touch Valkey when no guard is configured', function () {
    $redis = Mockery::mock(Factory::class);
    $redis->shouldNotReceive('connection');

    $governor = new ValkeySourceGovernor($redis);
    $none = new GovernorLimits;

    expect($governor->admit('w', 'd', $none)->admitted)->toBeTrue()
        ->and($governor->record('w', 'd', CallOutcome::Failed, $none))->toBeNull();
    $governor->penalize('w', 'd', 5, $none);
});

it('fails open when Valkey errors: the call is admitted and the log line names no value', function () {
    $logged = [];
    Log::shouldReceive('warning')->andReturnUsing(function (string $event, array $context = []) use (&$logged): void {
        $logged[] = [$event, $context];
    });
    $redis = Mockery::mock(Factory::class);
    $redis->shouldReceive('connection')->andThrow(new RuntimeException('connection refused to secret-host CANARY-gov'));

    $governor = new ValkeySourceGovernor($redis);
    $limits = new GovernorLimits(3, 60, 10, 2, 30, 60);

    expect($governor->admit('w', 'd', $limits))->toEqual(Admission::admitted())
        ->and($governor->record('w', 'd', CallOutcome::Failed, $limits))->toBeNull();
    $governor->penalize('w', 'd', 5, $limits);
    $governor->release('w', 'd');

    expect($logged)->toHaveCount(4)
        ->and($logged[0])->toBe(['connector.governor.unavailable', ['workspace_id' => 'w', 'exception' => RuntimeException::class]])
        ->and(json_encode($logged))->not->toContain('CANARY-gov');
});

it('maps the Lua reply codes to admissions and sends the limits as arguments', function () {
    $calls = [];
    $connection = new class($calls)
    {
        public function __construct(public array &$calls) {}

        public array $replies = [[1, 4200, 0], [3, 1500, 0], [4, 0, 0], [0, 0, 1], 1];

        public function eval(...$args): mixed
        {
            $this->calls[] = $args;

            return array_shift($this->replies);
        }
    };
    $redis = Mockery::mock(Factory::class);
    $redis->shouldReceive('connection')->with('queue')->andReturn($connection);
    $governor = new ValkeySourceGovernor($redis);
    $limits = new GovernorLimits(3, 60, 10, 2, 30, 60);

    $open = $governor->admit('W1', 'D1', $limits);
    $bucket = $governor->admit('W1', 'D1', $limits);
    $busy = $governor->admit('W1', 'D1', $limits);
    $probe = $governor->admit('W1', 'D1', $limits);

    expect([$open->admitted, $open->reason, $open->waitSeconds])->toBe([false, Admission::CIRCUIT_OPEN, 5])
        ->and([$bucket->reason, $bucket->waitSeconds])->toBe([Admission::RATE_LIMITED, 2])
        ->and($busy->reason)->toBe(Admission::CONCURRENCY)
        ->and([$probe->admitted, $probe->probe, $probe->slot])->toBe([true, true, true])
        ->and($governor->record('w', 'd', CallOutcome::Failed, $limits))->toBe('opened');

    // The script, the key count, four keys sharing one hash tag, then the numbers (milliseconds for the times).
    expect($calls[0][1])->toBe(4)
        ->and($calls[0][2])->toBe('sourcegov:{w1:d1}:breaker')
        ->and(array_slice($calls[0], 6, 5))->toBe([3, 60000, 10, 2, 30000]);
});

it('lets only the probe close an open breaker, and never pushes the concurrency lease out', function () {
    $governor = new FakeGovernor;
    $limits = new GovernorLimits(2, 60, null, 5, 30);

    $governor->record('w', 'd', CallOutcome::Failed, $limits);
    $governor->record('w', 'd', CallOutcome::Failed, $limits);
    expect($governor->breakers['w:d']['state'])->toBe('open');

    // A call admitted before the breaker opened answers late: the breaker stays open.
    expect($governor->record('w', 'd', CallOutcome::Responded, $limits))->toBeNull()
        ->and($governor->breakers['w:d']['state'])->toBe('open')
        ->and($governor->record('w', 'd', CallOutcome::Responded, $limits, true))->toBe('closed')
        ->and($governor->breakers)->toBe([]);

    // The lease is set when the counter is created: later admits do not extend it, so a leaked slot expires.
    $governor->admit('w', 'd', $limits);
    $governor->now += 20;
    $governor->admit('w', 'd', $limits);
    $governor->now += 11;
    expect($governor->inflight['w:d'])->toBe(2);
    $governor->admit('w', 'd', $limits);
    expect($governor->inflight['w:d'])->toBe(1);
});

it('keeps a job queued before Story 2.17, with no attempt property, at attempt 1', function () {
    $job = new FetchJob('11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222', 7, 3);
    $old = (string) preg_replace_callback(
        '/\AO:(\d+):"([^"]+)":(\d+):\{/',
        fn (array $m): string => 'O:'.$m[1].':"'.$m[2].'":'.((int) $m[3] - 1).':{',
        str_replace('s:7:"attempt";i:3;', '', serialize($job)),
    );

    $restored = unserialize($old);

    expect(str_contains($old, 'attempt'))->toBeFalse()
        ->and($restored)->toBeInstanceOf(FetchJob::class)
        ->and($restored->attempt)->toBe(1)
        ->and($restored->dispatchSeq)->toBe(7)
        ->and(unserialize(serialize($job))->attempt)->toBe(3);
});

it('reads the breaker state from the Lua reply, closed when the limits make it inert or the store fails', function () {
    $connection = new class
    {
        public array $replies = [0, 1, 2];

        public function eval(...$args): mixed
        {
            return array_shift($this->replies);
        }
    };
    $redis = Mockery::mock(Factory::class);
    $redis->shouldReceive('connection')->with('queue')->andReturn($connection);
    $governor = new ValkeySourceGovernor($redis);
    $limits = new GovernorLimits(3, 60, 10, 2, 30, 60);

    expect($governor->state('W1', 'D1', $limits))->toBe('closed')
        ->and($governor->state('W1', 'D1', $limits))->toBe('open')
        ->and($governor->state('W1', 'D1', $limits))->toBe('half_open')
        ->and($governor->state('W1', 'D1', new GovernorLimits))->toBe('closed');

    Log::shouldReceive('warning')->once();
    $down = Mockery::mock(Factory::class);
    $down->shouldReceive('connection')->andThrow(new RuntimeException('down'));

    expect((new ValkeySourceGovernor($down))->state('W1', 'D1', $limits))->toBe('closed');
});
