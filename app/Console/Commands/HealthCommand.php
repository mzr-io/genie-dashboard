<?php

namespace App\Console\Commands;

use App\Support\Health\HealthChecker;
use App\Support\Health\Role;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('dashflow:health {role : web, realtime, scheduler, worker-connector or worker-compute}')]
class HealthCommand extends Command
{
    protected $description = 'Check a process role\'s health; exits non-zero and names each failing check';

    public function handle(HealthChecker $checker): int
    {
        $role = Role::tryFrom((string) $this->argument('role'));

        if ($role === null) {
            $this->components->error('Unknown role. Use one of: '.implode(', ', array_column(Role::cases(), 'value')));

            return self::INVALID;
        }

        $report = $checker->check($role, local: true);

        if ($report->healthy()) {
            $this->line('ok');

            return self::SUCCESS;
        }

        foreach ($report->failures as $check => $reason) {
            fwrite(STDERR, "unhealthy: {$check}: {$reason}\n");
        }

        return self::FAILURE;
    }
}
