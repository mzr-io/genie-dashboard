<?php

namespace App\Console\Commands;

use App\Support\Health\Heartbeat;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('dashflow:heartbeat')]
class HeartbeatCommand extends Command
{
    protected $description = 'Record the scheduler heartbeat read by the scheduler health check';

    public function handle(): int
    {
        Heartbeat::beat();

        return self::SUCCESS;
    }
}
