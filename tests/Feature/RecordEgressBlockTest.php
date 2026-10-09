<?php

use App\Modules\Connector\Application\RecordEgressBlock;
use App\Modules\Connector\Contracts\EgressReason;
use App\Modules\Connector\Infrastructure\EgressSettings;
use App\Platform\Audit\Audit;
use App\Support\Observability\MetricEmitter;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as ArrayCache;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;

// Story 2.2: a failing audit write or alert counter never turns a denial into another error.

const REB_WS = '018f0000-0000-7000-8000-00000000000a';

function blockLog(Audit $audit, Repository $cache, ?MetricEmitter $metrics = null, bool $alert = true): RecordEgressBlock
{
    $config = new Illuminate\Config\Repository(['dashflow' => ['egress' => $alert
        ? ['alert_threshold' => ['value' => '1'], 'alert_window' => ['value' => '60']]
        : []]]);

    return new RecordEgressBlock($audit, new EgressSettings($config), $cache, $metrics ?? new class implements MetricEmitter
    {
        public function increment(string $name, array $labels = [], int $by = 1): void {}
    });
}

it('logs a failing audit write without an address and does not throw', function () {
    $audit = Mockery::mock(Audit::class);
    $audit->shouldReceive('recordSecurityEvent')->once()->andThrow(new InvalidArgumentException('boom 127.0.0.1'));
    Log::shouldReceive('error')->once()->withArgs(fn (string $message, array $context) => $message === 'connector.egress.audit_failed'
        && $context['exception'] === InvalidArgumentException::class && ! str_contains(json_encode($context), '127.0.0.1'));

    blockLog($audit, new ArrayCache(new ArrayStore))->record(REB_WS, EgressReason::BlockedAddress, 'a.example', 443);
});

it('still audits an unresolvable name but does not count it towards the alert', function () {
    $audit = Mockery::mock(Audit::class);
    $audit->shouldReceive('recordSecurityEvent')->twice();
    $spy = new class implements MetricEmitter
    {
        public int $fired = 0;

        public function increment(string $name, array $labels = [], int $by = 1): void
        {
            $this->fired++;
        }
    };
    $log = blockLog($audit, new ArrayCache(new ArrayStore), $spy);

    $log->record(REB_WS, EgressReason::Unresolvable, 'a.example', 443);
    $log->record(REB_WS, EgressReason::Unresolvable, 'a.example', 443);

    expect($spy->fired)->toBe(0);

    $audit->shouldReceive('recordSecurityEvent')->twice();
    $log->record(REB_WS, EgressReason::BlockedAddress, 'a.example', 443);
    $log->record(REB_WS, EgressReason::BlockedAddress, 'a.example', 443);

    expect($spy->fired)->toBe(1);
});

it('audits the denial and logs alert_failed when the cache throws', function () {
    $audit = Mockery::mock(Audit::class);
    $audit->shouldReceive('recordSecurityEvent')->once();
    $cache = Mockery::mock(Repository::class);
    $cache->shouldReceive('add')->andThrow(new RuntimeException('cache down'));
    Log::shouldReceive('error')->once()->withArgs(fn (string $message, array $context) => $message === 'connector.ssrf_blocked.alert_failed' && $context['exception'] === RuntimeException::class);

    blockLog($audit, $cache)->record(REB_WS, EgressReason::BlockedAddress, 'a.example', 443);
});
