<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| ValidationReports (Story 3.18), owned by Blocks. Expand-only: one new tenant table. A report is the result of validating a Draft against the real
| API (the `publish_validation` Operation) and is bound to the Draft's revision (`subject_revision`): Publish (Story 3.19) accepts only a `passed`
| report whose revision is the Draft's current one, so any later autosave makes it stale without a write.
|
|  status      passed (the live response matched), failed (strict-schema errors or mismatching paths), unreachable (the live API could not be
|              fetched) or stale (the revision moved while the Operation ran, or the Endpoint changed).
|  reason      a short slug (strict_schema, shape_mismatch, unbound_publishable, context_missing, ... or a connector code for unreachable).
|  mismatches  a list of {path, slot, type, expected}: stored paths, slot keys and types only. Never a sample or a response value.
|  operation_id  the Operation that produced it: one report per Operation (unique).
|
|  Tenant table: non-null workspace_id, UUIDv7 key (made by the application), ENABLE and FORCE ROW LEVEL SECURITY and the workspace_isolation
|  policy. Role `app` keeps the default SELECT, INSERT and UPDATE and has no DELETE; `maintenance` deletes a discarded Draft's reports.
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
            CREATE TABLE validation_reports (
                id uuid PRIMARY KEY,
                workspace_id uuid NOT NULL REFERENCES workspaces (id),
                block_version_id uuid NOT NULL REFERENCES block_versions (id),
                operation_id uuid NOT NULL,
                subject_revision integer NOT NULL,
                status varchar(16) NOT NULL,
                reason varchar(64),
                endpoint_id uuid,
                endpoint_revision integer,
                mismatches jsonb NOT NULL DEFAULT '[]',
                created_by_membership_id uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT validation_reports_status_check CHECK (status IN ('passed', 'failed', 'unreachable', 'stale')),
                CONSTRAINT validation_reports_revision_check CHECK (subject_revision >= 1),
                CONSTRAINT validation_reports_mismatches_check CHECK (jsonb_typeof(mismatches) = 'array')
            );
            CREATE UNIQUE INDEX validation_reports_operation_unique ON validation_reports (operation_id);
            CREATE INDEX validation_reports_version_index ON validation_reports (block_version_id, created_at DESC);
            SQL);

        DB::unprepared(<<<SQL
            ALTER TABLE validation_reports ENABLE ROW LEVEL SECURITY;
            ALTER TABLE validation_reports FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON validation_reports
                USING ({$workspace}) WITH CHECK ({$workspace});
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TABLE IF EXISTS validation_reports CASCADE;');
    }
};
