<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Retry, rate limit and circuit breaker (Story 2.17).
|
|  sync_runs.attempt  The attempt number of a scheduled fetch (1 for the first call, null before this story and for other kinds).
|  sync_runs.status   Two more values: `retrying` (a retryable failure that was queued again; the target is untouched) and `skipped`
|                     (no call was made: the breaker is open or the Data Source's rate limit denied it).
*/
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE sync_runs ADD COLUMN attempt smallint;
            ALTER TABLE sync_runs ADD CONSTRAINT sync_runs_attempt_check CHECK (attempt IS NULL OR attempt >= 1);
            ALTER TABLE sync_runs DROP CONSTRAINT sync_runs_status_check;
            ALTER TABLE sync_runs ADD CONSTRAINT sync_runs_status_check
                CHECK (status IN ('succeeded', 'failed', 'superseded', 'retrying', 'skipped'));
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE sync_runs DROP CONSTRAINT IF EXISTS sync_runs_status_check;
            ALTER TABLE sync_runs ADD CONSTRAINT sync_runs_status_check CHECK (status IN ('succeeded', 'failed', 'superseded')) NOT VALID;
            ALTER TABLE sync_runs DROP CONSTRAINT IF EXISTS sync_runs_attempt_check;
            ALTER TABLE sync_runs DROP COLUMN IF EXISTS attempt;
            SQL);
    }
};
