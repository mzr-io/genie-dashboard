<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| block_versions.change_summary (Story 6.3): what a version changed against the one before it, as section names with counts, written in the freeze
| (draft -> prepared) and never again: the guard now also requires it unchanged on prepared -> published. Null for a first version.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE block_versions ADD COLUMN change_summary jsonb;

            CREATE OR REPLACE FUNCTION block_versions_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.id <> OLD.id OR NEW.workspace_id <> OLD.workspace_id OR NEW.block_id <> OLD.block_id THEN
                    RAISE EXCEPTION 'a version keeps its identity' USING ERRCODE = '23514';
                END IF;

                IF NEW.lock_epoch < OLD.lock_epoch THEN
                    RAISE EXCEPTION 'lock_epoch never decreases' USING ERRCODE = '23514';
                END IF;

                IF OLD.state = 'discarded' THEN
                    IF NEW.state <> 'discarded' THEN
                        RAISE EXCEPTION 'a discarded draft cannot be restored' USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END IF;

                IF OLD.state = 'draft' THEN
                    IF NEW.state IN ('draft', 'prepared', 'discarded') THEN
                        RETURN NEW;
                    END IF;

                    RAISE EXCEPTION 'a draft is prepared or discarded, nothing else' USING ERRCODE = '23514';
                END IF;

                IF OLD.state = 'prepared' THEN
                    IF NEW.state = 'published'
                        AND NEW.config = OLD.config
                        AND NEW.name = OLD.name
                        AND NEW.version = OLD.version
                        AND NEW.revision = OLD.revision
                        AND NEW.endpoint_id IS NOT DISTINCT FROM OLD.endpoint_id
                        AND NEW.endpoint_revision IS NOT DISTINCT FROM OLD.endpoint_revision
                        AND NEW.prepared_at IS NOT DISTINCT FROM OLD.prepared_at
                        AND NEW.prepared_by_membership_id IS NOT DISTINCT FROM OLD.prepared_by_membership_id
                        AND NEW.change_summary IS NOT DISTINCT FROM OLD.change_summary
                    THEN
                        RETURN NEW;
                    END IF;

                    RAISE EXCEPTION 'a prepared version is only published, unchanged' USING ERRCODE = '23514';
                END IF;

                RAISE EXCEPTION 'a published version is immutable' USING ERRCODE = '23514';
            END
            $$;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('ALTER TABLE block_versions DROP COLUMN change_summary');
    }
};
