<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Workspace settings (Story 1.18 creates the table for the Help & support links; Epic 8 adds editing). One
| row per Workspace (`workspace_id` is unique) with a `revision` for later optimistic updates. `help_links`
| is a JSON array of {label, url} (http or https only, checked where it is written); `contact_href` is the
| address behind "Contact your workspace administrator". A tenant table: non-null workspace_id, ENABLE and
| FORCE ROW LEVEL SECURITY and the Workspace policy used by every tenant table.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->unique()->constrained('workspaces');
            $table->unsignedInteger('revision')->default(1);
            $table->jsonb('help_links')->default('[]');
            $table->string('contact_href', 2048)->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE workspace_settings ADD CONSTRAINT workspace_settings_help_links_check
                CHECK (jsonb_typeof(help_links) = 'array');
            ALTER TABLE workspace_settings ENABLE ROW LEVEL SECURITY;
            ALTER TABLE workspace_settings FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON workspace_settings
                USING (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid)
                WITH CHECK (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid);
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_settings');
    }
};
