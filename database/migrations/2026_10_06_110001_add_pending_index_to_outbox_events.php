<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* The relay reads only unsent events, oldest first: a partial index keeps that cheap as the table grows. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('CREATE INDEX outbox_events_pending_idx ON outbox_events (occurred_at, subject_seq) WHERE sent_at IS NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP INDEX IF EXISTS outbox_events_pending_idx');
    }
};
