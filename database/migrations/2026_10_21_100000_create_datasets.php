<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Datasets suggestion catalog (Story 3.11c), owned by Datasets. Expand-only: two new tables. A Dataset is remembered when an Admin picks a record
| path for a Draft, and is offered to NEW Drafts of the same Endpoint only as a suggestion: the Draft's own config stays authoritative and nothing
| reads these tables at runtime. No Draft id and no sample text is stored, only field paths, types and roles.
|
|  datasets        (workspace_id, endpoint_id, record_path) is unique; record_path is the stored RFC 9535 subset (`$.data[*]`).
|  dataset_fields  the typed fields under the record path: stored path, the inferred type text and the role (one of the six, or null), in
|                  `position` order. `position` is null for a retired row: role `app` cannot DELETE, so replacing a field list rewrites the rows in
|                  place, adds rows for any extra fields and retires the surplus (position null); a retired row is reused by a later list. Only the
|                  rows with a position are the Dataset's fields.
|
|  Tenant tables: non-null workspace_id, UUIDv7 key (made by the application), ENABLE and FORCE ROW LEVEL SECURITY and the workspace_isolation
|  policy. Role `app` keeps the default SELECT, INSERT and UPDATE and has no DELETE.
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
            CREATE TABLE datasets (
                id uuid PRIMARY KEY,
                workspace_id uuid NOT NULL REFERENCES workspaces (id),
                endpoint_id uuid NOT NULL,
                record_path varchar(512) NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT datasets_endpoint_workspace_fk FOREIGN KEY (workspace_id, endpoint_id) REFERENCES endpoints (workspace_id, id),
                CONSTRAINT datasets_record_path_check CHECK (record_path ~ '^\$' AND char_length(record_path) <= 512),
                CONSTRAINT datasets_workspace_id_id_unique UNIQUE (workspace_id, id)
            );
            CREATE UNIQUE INDEX datasets_endpoint_path_unique ON datasets (workspace_id, endpoint_id, record_path);
            CREATE INDEX datasets_endpoint_updated_index ON datasets (workspace_id, endpoint_id, updated_at DESC);

            CREATE TABLE dataset_fields (
                id uuid PRIMARY KEY,
                workspace_id uuid NOT NULL REFERENCES workspaces (id),
                dataset_id uuid NOT NULL,
                path varchar(512) NOT NULL,
                type text NOT NULL,
                role text,
                position integer,
                CONSTRAINT dataset_fields_dataset_workspace_fk FOREIGN KEY (workspace_id, dataset_id) REFERENCES datasets (workspace_id, id),
                CONSTRAINT dataset_fields_path_check CHECK (path ~ '^\$' AND char_length(path) <= 512),
                CONSTRAINT dataset_fields_type_check CHECK (char_length(type) BETWEEN 1 AND 64),
                CONSTRAINT dataset_fields_role_check CHECK (role IS NULL OR role IN ('label', 'value', 'dimension', 'measure', 'time', 'filter')),
                CONSTRAINT dataset_fields_position_check CHECK (position IS NULL OR position >= 0)
            );
            CREATE INDEX dataset_fields_dataset_index ON dataset_fields (dataset_id);
            CREATE UNIQUE INDEX dataset_fields_dataset_position_unique ON dataset_fields (dataset_id, position) WHERE position IS NOT NULL;
            SQL);

        foreach (['datasets', 'dataset_fields'] as $table) {
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
            DROP TABLE IF EXISTS dataset_fields CASCADE;
            DROP TABLE IF EXISTS datasets CASCADE;
            SQL);
    }
};
