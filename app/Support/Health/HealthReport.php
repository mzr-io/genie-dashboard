<?php

namespace App\Support\Health;

/**
 * The outcome of one role's checks: failing check name => reason.
 */
final readonly class HealthReport
{
    /**
     * @param  array<string, string>  $failures  check => full reason (for stderr and logs)
     * @param  array<string, string>  $kinds  check => exception class (safe to expose over HTTP)
     */
    public function __construct(public array $failures = [], public array $kinds = []) {}

    public function healthy(): bool
    {
        return $this->failures === [];
    }

    /**
     * @return array{status: string, failed?: list<array{check: string, reason: string}>}
     */
    public function toArray(): array
    {
        if ($this->healthy()) {
            return ['status' => 'ok'];
        }

        $failed = [];
        foreach ($this->failures as $check => $reason) {
            // Hostnames and SQL stay out of the HTTP body: expose the error kind only.
            $failed[] = ['check' => $check, 'reason' => $this->kinds[$check] ?? 'failed'];
        }

        return ['status' => 'unhealthy', 'failed' => $failed];
    }
}
