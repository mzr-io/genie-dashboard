<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Blocks and their Drafts (Story 3.3), owned by Blocks.
|
|  blocks          the Block: its Block Type (a registry key and the pinned contract version), an optional category (null until
|                  categories exist) and the membership that owns it. A plain column holds the owner: no foreign key across modules.
|  block_versions  one row per version. Only the `draft` state exists yet, plus `discarded` (a Draft the web tier has given up: the
|                  state is the app role's UPDATE, and the `maintenance` role deletes the row later, so the web never holds DELETE).
|                  `version` is the prospective label ("1.0"). `revision` and `lock_epoch` start at 1; every save is a compare-and-set
|                  on both. `config` is the lenient Draft document (schema_version only for now). A Block has at most one Draft: a
|                  partial unique index on `block_id` where the state is `draft`. A discarded Draft never comes back, and the epoch
|                  never decreases (a trigger).
|  draft_samples   sample bodies of a Draft, never kept on the version row (filled by later stories).
|  draft_ui_state  where the wizard reopens a Draft: the step and a small document; one row per Draft.
|
|  Tenant tables: non-null workspace_id, UUIDv7 keys (made by the application), ENABLE and FORCE ROW LEVEL SECURITY and the
|  workspace_isolation policy. Role `app` keeps the default SELECT, INSERT and UPDATE and has no DELETE.
*/
return new class extends Migration
{
    private const WORKSPACE = "workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid";

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $present = array_column(DB::select("select rolname from pg_roles where rolname in ('app', 'maintenance')"), 'rolname');
        if (array_diff(['app', 'maintenance'], $present) !== []) {
            throw new RuntimeException('Missing database role(s) app and maintenance. Create them with docker/postgres/initdb.sh before migrating.');
        }

        $workspace = self::WORKSPACE;

        DB::unprepared(<<<'SQL'
            CREATE TABLE blocks (
                id uuid PRIMARY KEY,
                workspace_id uuid NOT NULL REFERENCES workspaces (id),
                type_key varchar(64) NOT NULL,
                contract_version smallint NOT NULL DEFAULT 1,
                category varchar(64),
                owner_membership_id uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT blocks_contract_version_check CHECK (contract_version >= 1)
            );
            CREATE INDEX blocks_workspace_index ON blocks (workspace_id);

            CREATE TABLE block_versions (
                id uuid PRIMARY KEY,
                workspace_id uuid NOT NULL REFERENCES workspaces (id),
                block_id uuid NOT NULL REFERENCES blocks (id),
                state varchar(16) NOT NULL DEFAULT 'draft',
                version varchar(16) NOT NULL DEFAULT '1.0',
                name varchar(120) NOT NULL,
                config jsonb NOT NULL DEFAULT '{"schema_version": 1}',
                revision integer NOT NULL DEFAULT 1,
                lock_epoch integer NOT NULL DEFAULT 1,
                created_by_membership_id uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                discarded_at timestamptz,
                CONSTRAINT block_versions_state_check CHECK (state IN ('draft', 'discarded')),
                CONSTRAINT block_versions_name_check CHECK (char_length(name) BETWEEN 1 AND 120 AND name = btrim(name) AND name !~ '[[:cntrl:]]'),
                CONSTRAINT block_versions_config_check CHECK (jsonb_typeof(config) = 'object'),
                CONSTRAINT block_versions_revision_check CHECK (revision >= 1),
                CONSTRAINT block_versions_lock_epoch_check CHECK (lock_epoch >= 1),
                CONSTRAINT block_versions_discarded_check CHECK ((state = 'discarded') = (discarded_at IS NOT NULL))
            );
            CREATE INDEX block_versions_workspace_state_index ON block_versions (workspace_id, state, updated_at DESC);
            CREATE INDEX block_versions_block_index ON block_versions (block_id);
            CREATE UNIQUE INDEX block_versions_one_draft_unique ON block_versions (block_id) WHERE state = 'draft';

            -- A discarded Draft never comes back to life, and the epoch never decreases. `migrate:fresh` drops tables, not functions.
            DROP FUNCTION IF EXISTS block_versions_guard();
            CREATE FUNCTION block_versions_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.lock_epoch < OLD.lock_epoch THEN
                    RAISE EXCEPTION 'lock_epoch never decreases' USING ERRCODE = '23514';
                END IF;

                IF OLD.state = 'discarded' AND NEW.state <> 'discarded' THEN
                    RAISE EXCEPTION 'a discarded draft cannot be restored' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END
            $$;

            CREATE TRIGGER block_versions_guard BEFORE UPDATE ON block_versions
                FOR EACH ROW EXECUTE FUNCTION block_versions_guard();

            CREATE TABLE draft_samples (
                id uuid PRIMARY KEY,
                workspace_id uuid NOT NULL REFERENCES workspaces (id),
                block_version_id uuid NOT NULL REFERENCES block_versions (id),
                source varchar(16) NOT NULL,
                body text NOT NULL,
                size_bytes integer NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT draft_samples_source_check CHECK (source IN ('fetch', 'paste')),
                CONSTRAINT draft_samples_size_check CHECK (size_bytes >= 0)
            );
            CREATE INDEX draft_samples_version_index ON draft_samples (block_version_id);

            CREATE TABLE draft_ui_state (
                id uuid PRIMARY KEY,
                workspace_id uuid NOT NULL REFERENCES workspaces (id),
                block_version_id uuid NOT NULL UNIQUE REFERENCES block_versions (id),
                step smallint NOT NULL DEFAULT 1,
                state jsonb NOT NULL DEFAULT '{}',
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT draft_ui_state_step_check CHECK (step BETWEEN 1 AND 5),
                CONSTRAINT draft_ui_state_state_check CHECK (jsonb_typeof(state) = 'object')
            );
            SQL);

        foreach (['blocks', 'block_versions', 'draft_samples', 'draft_ui_state'] as $table) {
            DB::unprepared(<<<SQL
                ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
                ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
                CREATE POLICY workspace_isolation ON {$table}
                    USING ({$workspace}) WITH CHECK ({$workspace});
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS draft_ui_state CASCADE;
            DROP TABLE IF EXISTS draft_samples CASCADE;
            DROP TRIGGER IF EXISTS block_versions_guard ON block_versions;
            DROP TABLE IF EXISTS block_versions CASCADE;
            DROP FUNCTION IF EXISTS block_versions_guard();
            DROP TABLE IF EXISTS blocks CASCADE;
            SQL);
    }
};
