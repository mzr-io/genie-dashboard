<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Creates the monthly partitions of `sync_runs` (Story 2.5) and `raw_observations` (Story 2.14): the current month and the next `--months` months. It is
 * idempotent: a month that already has a partition is skipped, so it is safe to run whenever, and Epic 9's scheduler only
 * has to run it (at least monthly, with `--months` of 1 or more so a partition always exists ahead of time). Every new
 * partition gets the same row-level security as the parent. Rows that arrive for a month without a partition are not lost:
 * they land in the DEFAULT partition, and a month whose rows sit there cannot get its partition (the command warns).
 */
#[Signature('dashflow:partitions:ensure {--months=1 : How many months after the current one to prepare (0 to 24)}')]
class EnsurePartitionsCommand extends Command
{
    /** Each partitioned table with the SECURITY DEFINER function that prepares its months. */
    private const PARTITIONED = [
        'sync_runs' => 'connector_ensure_sync_run_partitions',
        // The raw tier's immutable observations (Story 2.14).
        'raw_observations' => 'rawstore_ensure_raw_observation_partitions',
    ];

    protected $description = 'Create the current and upcoming monthly partitions of sync_runs and raw_observations (idempotent)';

    public function handle(): int
    {
        $months = $this->option('months');

        if (! is_string($months) || preg_match('/\A(?:0|[1-9][0-9]?)\z/D', $months) !== 1 || (int) $months > 24) {
            $this->components->error('--months must be a whole number from 0 to 24.');

            return self::INVALID;
        }

        $failed = false;

        foreach (self::PARTITIONED as $table => $function) {
            try {
                $row = DB::selectOne("select {$function}(?) as created", [(int) $months]);
            } catch (Throwable $e) {
                $this->components->error("The {$table} partitions could not be prepared: ".$e::class.'.');

                return self::FAILURE;
            }

            $created = (int) ($row->created ?? 0);

            // A month the function skipped (its rows already sit in the default partition) is a failure, not a success.
            $missing = [];

            for ($i = 0; $i <= (int) $months; $i++) {
                $first = new \DateTimeImmutable('first day of this month 00:00', new \DateTimeZone('UTC'));
                $month = $first->modify("+{$i} months");
                $name = $table.'_y'.$month->format('Y').'m'.$month->format('m');

                if (DB::selectOne('select to_regclass(?) as found', ['public.'.$name])->found === null) {
                    $missing[] = $name;
                }
            }

            if ($missing !== []) {
                $this->components->warn('Not created, because the default partition already holds rows for it: '.implode(', ', $missing).'.');
                $failed = true;

                continue;
            }

            $this->components->info($created === 0 ? "Every {$table} partition needed already exists." : "Created {$created} {$table} partition(s).");
        }

        if ($failed) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
