<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The soft lock's epoch (Story 2.8), an expand-only change owned by Connector.
|
|  data_sources.lock_epoch   a whole number, 1 for a new row and raised by one each time an edit lock is taken over
|                            (Platform\EditLock). It never decreases. The lock itself lives in the cache, which may evict
|                            or lose it, so a write is checked against this column and never against the cache alone.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->unsignedInteger('lock_epoch')->default(1);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE data_sources ADD CONSTRAINT data_sources_lock_epoch_check CHECK (lock_epoch >= 1);

            -- Never decreasing: an update may keep the epoch or raise it. `migrate:fresh` drops tables, not functions.
            DROP FUNCTION IF EXISTS data_sources_lock_epoch_guard();
            CREATE FUNCTION data_sources_lock_epoch_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.lock_epoch < OLD.lock_epoch THEN
                    RAISE EXCEPTION 'lock_epoch never decreases' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END
            $$;

            CREATE TRIGGER data_sources_lock_epoch_guard BEFORE UPDATE OF lock_epoch ON data_sources
                FOR EACH ROW EXECUTE FUNCTION data_sources_lock_epoch_guard();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS data_sources_lock_epoch_guard ON data_sources;
                DROP FUNCTION IF EXISTS data_sources_lock_epoch_guard();
                ALTER TABLE data_sources DROP CONSTRAINT IF EXISTS data_sources_lock_epoch_check;
                SQL);
        }

        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn('lock_epoch');
        });
    }
};
