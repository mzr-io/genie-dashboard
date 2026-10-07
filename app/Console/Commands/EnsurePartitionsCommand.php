<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Creates the monthly partitions of `sync_runs` (Story 2.5): the current month and the next `--months` months. It is
 * idempotent: a month that already has a partition is skipped, so it is safe to run whenever, and Epic 9's scheduler only
 * has to run it (at least monthly, with `--months` of 1 or more so a partition always exists ahead of time). Every new
 * partition gets the same row-level security as the parent. Rows that arrive for a month without a partition are not lost:
 * they land in the DEFAULT partition, and a month whose rows sit there cannot get its partition (the command warns).
 */
#[Signature('dashflow:partitions:ensure {--months=1 : How many months after the current one to prepare (0 to 24)}')]
class EnsurePartitionsCommand extends Command
{
    protected $description = 'Create the current and upcoming monthly partitions of sync_runs (idempotent)';

    public function handle(): int
    {
        $months = $this->option('months');

        if (! is_string($months) || preg_match('/\A(?:0|[1-9][0-9]?)\z/D', $months) !== 1 || (int) $months > 24) {
            $this->components->error('--months must be a whole number from 0 to 24.');

            return self::INVALID;
        }

        try {
            $row = DB::selectOne('select connector_ensure_sync_run_partitions(?) as created', [(int) $months]);
        } catch (Throwable $e) {
            $this->components->error('The partitions could not be prepared: '.$e::class.'.');

            return self::FAILURE;
        }

        $created = (int) ($row->created ?? 0);

        // A month the function skipped (its rows already sit in the default partition) is a failure, not a success.
        $missing = [];

        for ($i = 0; $i <= (int) $months; $i++) {
            $first = new \DateTimeImmutable('first day of this month 00:00', new \DateTimeZone('UTC'));
            $month = $first->modify("+{$i} months");
            $name = 'sync_runs_y'.$month->format('Y').'m'.$month->format('m');

            if (DB::selectOne('select to_regclass(?) as found', ['public.'.$name])->found === null) {
                $missing[] = $name;
            }
        }

        if ($missing !== []) {
            $this->components->warn('Not created, because the default partition already holds rows for it: '.implode(', ', $missing).'.');

            return self::FAILURE;
        }

        $this->components->info($created === 0 ? 'Every sync_runs partition needed already exists.' : "Created {$created} sync_runs partition(s).");

        return self::SUCCESS;
    }
}
