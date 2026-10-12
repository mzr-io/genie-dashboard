<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| The publish lifecycle of a Block version (Story 3.19). Expand-only: new states, columns and one new table; no column is dropped.
|
|  block_versions.state       gains `prepared` and `published`. A Draft becomes `prepared` in the first publish transaction (its `config` is replaced by
|                            the frozen document and the Endpoint revision is pinned) and `published` in the second (`ActivateVersion`).
|  block_versions.endpoint_id, endpoint_revision
|                            the Endpoint and the revision the passed ValidationReport was made against, pinned on the version at the freeze.
|  block_versions.prepared_at, published_at, prepared_by_membership_id, published_by_membership_id
|                            when the version was frozen and activated and by whom. The member who froze it is kept so that an activation by the
|                            maintenance job (which has no actor of its own) is still attributed to the person who published.
|  block_versions_guard      the trigger now rejects every UPDATE except a Draft's content edits (and its discard), draft -> prepared and
|                            prepared -> published; the content of a prepared version cannot change on its way to published, and a published
|                            version takes no UPDATE at all. `id`, `workspace_id` and `block_id` never change.
|  block_versions_published_unique
|                            one prepared or published row per (Block, version label).
|  blocks.current_version_id the published version users see; switched by activation.
|  access_subjects           Access's registry of what can be limited to groups (Story 3.19; `access_grants` and the group mode come in Epic 6): a
|                            Block (later a Template) is registered in the transaction that publishes it, `mode` `all` ("All users") and
|                            `visible` true. No grant is keyed by a version.
|
|  Tenant table: non-null workspace_id, UUIDv7 key (made by the application), ENABLE and FORCE ROW LEVEL SECURITY and the workspace_isolation policy.
|  Role `app` keeps the default SELECT, INSERT and UPDATE and has no DELETE.
*/
return new class extends Migration
{
    private const WORKSPACE = "workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid";

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $workspace = self::WORKSPACE;

        DB::unprepared(<<<'SQL'
            ALTER TABLE block_versions DROP CONSTRAINT block_versions_state_check;

            ALTER TABLE block_versions
                ADD COLUMN endpoint_id uuid,
                ADD COLUMN endpoint_revision integer,
                ADD COLUMN prepared_at timestamptz,
                ADD COLUMN published_at timestamptz,
                ADD COLUMN prepared_by_membership_id uuid,
                ADD COLUMN published_by_membership_id uuid,
                ADD CONSTRAINT block_versions_state_check CHECK (state IN ('draft', 'prepared', 'published', 'discarded')),
                ADD CONSTRAINT block_versions_prepared_check CHECK ((state IN ('prepared', 'published')) = (prepared_at IS NOT NULL)),
                ADD CONSTRAINT block_versions_published_check CHECK ((state = 'published') = (published_at IS NOT NULL)),
                ADD CONSTRAINT block_versions_endpoint_revision_check CHECK (endpoint_revision IS NULL OR endpoint_revision >= 1);

            CREATE UNIQUE INDEX block_versions_published_unique ON block_versions (block_id, version) WHERE state IN ('prepared', 'published');
            CREATE INDEX block_versions_prepared_index ON block_versions (prepared_at) WHERE state = 'prepared';

            ALTER TABLE blocks ADD COLUMN current_version_id uuid REFERENCES block_versions (id);

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
                    -- Content edits, the discard and the freeze (draft -> prepared).
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
                    THEN
                        RETURN NEW;
                    END IF;

                    RAISE EXCEPTION 'a prepared version is only published, unchanged' USING ERRCODE = '23514';
                END IF;

                RAISE EXCEPTION 'a published version is immutable' USING ERRCODE = '23514';
            END
            $$;

            CREATE TABLE access_subjects (
                id uuid PRIMARY KEY,
                workspace_id uuid NOT NULL REFERENCES workspaces (id),
                subject_type varchar(16) NOT NULL,
                subject_id uuid NOT NULL,
                visible boolean NOT NULL DEFAULT true,
                mode varchar(16) NOT NULL DEFAULT 'all',
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT access_subjects_type_check CHECK (subject_type IN ('block', 'template')),
                CONSTRAINT access_subjects_mode_check CHECK (mode IN ('all', 'groups'))
            );
            CREATE UNIQUE INDEX access_subjects_subject_unique ON access_subjects (workspace_id, subject_type, subject_id);
            SQL);

        DB::unprepared(<<<SQL
            ALTER TABLE access_subjects ENABLE ROW LEVEL SECURITY;
            ALTER TABLE access_subjects FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON access_subjects
                USING ({$workspace}) WITH CHECK ({$workspace});
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS access_subjects CASCADE;

            ALTER TABLE blocks DROP COLUMN IF EXISTS current_version_id;

            DROP INDEX IF EXISTS block_versions_prepared_index;
            DROP INDEX IF EXISTS block_versions_published_unique;

            CREATE OR REPLACE FUNCTION block_versions_guard() RETURNS trigger LANGUAGE plpgsql AS $$
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

            ALTER TABLE block_versions
                DROP CONSTRAINT IF EXISTS block_versions_endpoint_revision_check,
                DROP CONSTRAINT IF EXISTS block_versions_published_check,
                DROP CONSTRAINT IF EXISTS block_versions_prepared_check,
                DROP CONSTRAINT IF EXISTS block_versions_state_check,
                DROP COLUMN IF EXISTS published_by_membership_id,
                DROP COLUMN IF EXISTS prepared_by_membership_id,
                DROP COLUMN IF EXISTS published_at,
                DROP COLUMN IF EXISTS prepared_at,
                DROP COLUMN IF EXISTS endpoint_revision,
                DROP COLUMN IF EXISTS endpoint_id,
                ADD CONSTRAINT block_versions_state_check CHECK (state IN ('draft', 'discarded'));
            SQL);
    }
};
