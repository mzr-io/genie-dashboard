<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Raw history retention (Story 2.16), an expand-only change.
|
|  data_sources.retention_mode / retention_days   Connector: `latest` (the default, days null) or `window` (days a whole number >= 1).
|                                                 The upper bound is the deployment setting `dashflow.retention.max_window_days`,
|                                                 checked by the application; the CHECK only keeps the pair consistent.
|  sync_targets.retention_mode / retention_days   Ingestion: a copy the outbox consumer keeps equal to its Data Source's setting, so the
|                                                 sweep reads Ingestion and RawStore tables only. Changing it never touches the fetch key,
|                                                 `current_payload_id` or `payload_seq`.
|
| Existing rows are `latest`. The sweep deletes as role `maintenance`, whose DELETE comes from the default privileges; no grant changes.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->string('retention_mode', 8)->default('latest');
            $table->unsignedInteger('retention_days')->nullable();
        });

        // sync_targets and the raw tier exist on PostgreSQL only.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE sync_targets ADD COLUMN retention_mode varchar(8) NOT NULL DEFAULT 'latest';
            ALTER TABLE sync_targets ADD COLUMN retention_days integer;

            ALTER TABLE data_sources ADD CONSTRAINT data_sources_retention_check CHECK (
                (retention_mode = 'latest' AND retention_days IS NULL)
                OR (retention_mode = 'window' AND retention_days IS NOT NULL AND retention_days >= 1));

            ALTER TABLE sync_targets ADD CONSTRAINT sync_targets_retention_check CHECK (
                (retention_mode = 'latest' AND retention_days IS NULL)
                OR (retention_mode = 'window' AND retention_days IS NOT NULL AND retention_days >= 1));

            CREATE INDEX sync_targets_retired_index ON sync_targets (retired_at) WHERE retired_at IS NOT NULL;
            -- The sweep asks whether a body still has an observation.
            CREATE INDEX raw_observations_payload_index ON raw_observations (workspace_id, payload_id);
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP INDEX IF EXISTS raw_observations_payload_index;
                DROP INDEX IF EXISTS sync_targets_retired_index;
                ALTER TABLE sync_targets DROP CONSTRAINT IF EXISTS sync_targets_retention_check;
                ALTER TABLE sync_targets DROP COLUMN IF EXISTS retention_days;
                ALTER TABLE sync_targets DROP COLUMN IF EXISTS retention_mode;
                ALTER TABLE data_sources DROP CONSTRAINT IF EXISTS data_sources_retention_check;
                SQL);
        }

        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn(['retention_mode', 'retention_days']);
        });
    }
};
