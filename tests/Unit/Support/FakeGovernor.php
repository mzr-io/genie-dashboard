<?php

namespace Tests\Unit\Support;

use App\Modules\Connector\Contracts\Admission;
use App\Modules\Connector\Contracts\CallOutcome;
use App\Modules\Connector\Contracts\GovernorLimits;
use App\Modules\Connector\Contracts\SourceGovernor;

/** The {@see SourceGovernor} in memory, with the same rules as the Lua scripts and a clock the test moves (seconds). */
final class FakeGovernor implements SourceGovernor
{
    public float $now = 1000.0;

    /** @var array<string, array{state: ?string, failures: int, open_until: float, probe_until: float}> */
    public array $breakers = [];

    /** @var array<string, float> */
    public array $penalties = [];

    /** @var array<string, array{tokens: float, ts: float}> */
    public array $buckets = [];

    /** @var array<string, int> */
    public array $inflight = [];

    /** @var array<string, float> when the in-flight counter's lease ends (set when it is created, never pushed out) */
    public array $leases = [];

    /** @var list<string> */
    public array $admits = [];

    /** @var list<array{string, bool}> */
    public array $records = [];

    public function admit(string $workspaceId, string $dataSourceId, GovernorLimits $limits): Admission
    {
        $k = $workspaceId.':'.$dataSourceId;
        $this->admits[] = $k;
        $probe = false;
        $b = $this->breakers[$k] ?? null;

        if (isset($this->leases[$k]) && $this->now >= $this->leases[$k]) {
            unset($this->leases[$k]);
            $this->inflight[$k] = 0;
        }

        if ($limits->breakerActive() && $b !== null && $b['state'] === 'open') {
            if ($this->now < $b['open_until']) {
                return Admission::denied(Admission::CIRCUIT_OPEN, (int) ceil($b['open_until'] - $this->now));
            }
            if ($b['probe_until'] > $this->now) {
                return Admission::denied(Admission::CIRCUIT_OPEN, (int) ceil($b['probe_until'] - $this->now));
            }
            $probe = true;
        }

        if (($this->penalties[$k] ?? 0) > $this->now) {
            return Admission::denied(Admission::RATE_LIMITED, (int) ceil($this->penalties[$k] - $this->now));
        }

        $rate = $limits->ratePerMinute ?? 0;
        $tokens = 0.0;

        if ($rate > 0) {
            $bucket = $this->buckets[$k] ?? null;
            $tokens = $bucket === null ? (float) $rate : min($rate, $bucket['tokens'] + max(0, $this->now - $bucket['ts']) * $rate / 60);

            if ($tokens < 1) {
                return Admission::denied(Admission::RATE_LIMITED, (int) ceil((1 - $tokens) * 60 / $rate));
            }
        }

        if (($limits->concurrency ?? 0) > 0 && ($this->inflight[$k] ?? 0) >= $limits->concurrency) {
            return Admission::denied(Admission::CONCURRENCY, 0);
        }

        if ($rate > 0) {
            $this->buckets[$k] = ['tokens' => $tokens - 1, 'ts' => $this->now];
        }
        if (($limits->concurrency ?? 0) > 0) {
            $this->inflight[$k] = ($this->inflight[$k] ?? 0) + 1;

            if (($limits->leaseSeconds ?? 0) > 0 && ! isset($this->leases[$k])) {
                $this->leases[$k] = $this->now + $limits->leaseSeconds;
            }
        }
        if ($probe) {
            $this->breakers[$k]['probe_until'] = $this->now + ($limits->coolDownSeconds ?? 0);
        }

        return Admission::admitted($probe, $limits->concurrency !== null);
    }

    public function record(string $workspaceId, string $dataSourceId, CallOutcome $outcome, GovernorLimits $limits, bool $probe = false): ?string
    {
        $k = $workspaceId.':'.$dataSourceId;
        $this->records[] = [$outcome->value, $probe];

        if (! $limits->breakerActive()) {
            return null;
        }

        $b = $this->breakers[$k] ?? ['state' => null, 'failures' => 0, 'open_until' => 0.0, 'probe_until' => 0.0];

        if ($outcome === CallOutcome::Responded) {
            // Only the probe may close an open breaker.
            if ($b['state'] === 'open' && ! $probe) {
                return null;
            }

            unset($this->breakers[$k]);

            return $b['state'] === 'open' ? self::CLOSED : null;
        }

        if ($outcome === CallOutcome::Throttled) {
            if ($probe && $b['state'] === 'open') {
                $this->breakers[$k]['probe_until'] = 0.0;
            }

            return null;
        }

        if ($b['state'] === 'open') {
            if ($probe) {
                $this->breakers[$k]['open_until'] = $this->now + $limits->coolDownSeconds;
                $this->breakers[$k]['probe_until'] = 0.0;

                return self::OPENED;
            }

            return null;
        }

        $b['failures']++;

        if ($b['failures'] >= $limits->failureCount) {
            $b = ['state' => 'open', 'failures' => $b['failures'], 'open_until' => $this->now + $limits->coolDownSeconds, 'probe_until' => 0.0];
            $this->breakers[$k] = $b;

            return self::OPENED;
        }

        $this->breakers[$k] = $b;

        return null;
    }

    public function state(string $workspaceId, string $dataSourceId, ?GovernorLimits $limits = null): string
    {
        $b = $this->breakers[$workspaceId.':'.$dataSourceId] ?? null;

        if (($limits !== null && ! $limits->breakerActive()) || $b === null || $b['state'] !== 'open') {
            return self::STATE_CLOSED;
        }

        return $this->now < $b['open_until'] ? self::STATE_OPEN : self::STATE_HALF_OPEN;
    }

    public function penalize(string $workspaceId, string $dataSourceId, int $seconds, GovernorLimits $limits): void
    {
        $k = $workspaceId.':'.$dataSourceId;

        if ($seconds > 0) {
            $this->penalties[$k] = max($this->penalties[$k] ?? 0, $this->now + $seconds);
        }
        if (($limits->ratePerMinute ?? 0) > 0) {
            $this->buckets[$k] = ['tokens' => 0.0, 'ts' => $this->now];
        }
    }

    public function release(string $workspaceId, string $dataSourceId): void
    {
        $k = $workspaceId.':'.$dataSourceId;
        $this->inflight[$k] = max(0, ($this->inflight[$k] ?? 0) - 1);
    }
}
