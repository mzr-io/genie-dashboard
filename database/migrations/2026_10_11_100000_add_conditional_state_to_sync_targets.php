<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Conditional requests and unchanged data (Story 2.15).
|
|  sync_targets.payload_changed_at  Ingestion: when the current payload last changed ("Data as of"). A 304 or an equal body moves
|                                   `last_success_at` and `last_checked_at` and leaves this alone. Backfilled from `last_success_at`
|                                   where a payload exists (until now every success was a change).
|  sync_targets.content_hash        From now on the sha256 of the lossless-canonical body, not of the bytes (`raw_bodies.content_hash`
|                                   stays the byte hash, the content address). Old byte hashes are nulled: the first run after the
|                                   deploy is one `changed` run.
|  sync_runs.outcome                `changed`, `not_modified` or `unchanged` for a succeeded scheduled fetch; null before and elsewhere.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE sync_targets ADD COLUMN payload_changed_at timestamptz;

            ALTER TABLE sync_runs ADD COLUMN outcome varchar(16);
            ALTER TABLE sync_runs ADD CONSTRAINT sync_runs_outcome_check
                CHECK (outcome IS NULL OR outcome IN ('changed', 'not_modified', 'unchanged'));
            SQL);

        // Row-level security stays forced: the backfill enters each Workspace in turn, as the application does.
        foreach (DB::select('select id from workspaces') as $workspace) {
            DB::transaction(function () use ($workspace): void {
                DB::statement("select set_config('app.workspace_id', ?, true)", [(string) $workspace->id]);
                DB::update('update sync_targets set payload_changed_at = last_success_at where current_payload_id is not null');
                DB::update('update sync_targets set content_hash = null where content_hash is not null');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE sync_runs DROP CONSTRAINT IF EXISTS sync_runs_outcome_check;
            ALTER TABLE sync_runs DROP COLUMN IF EXISTS outcome;
            ALTER TABLE sync_targets DROP COLUMN IF EXISTS payload_changed_at;
            SQL);
    }
};
