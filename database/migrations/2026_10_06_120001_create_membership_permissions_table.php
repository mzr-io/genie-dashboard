<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The Admin permissions a membership holds (Story 1.12 creates the table so the first Admin receives
| them all; enforcement arrives with Story 1.19). One row per held permission, from the closed
| App\Modules\Access\Contracts\Permission enum. A tenant table: non-null workspace_id, ENABLE and
| FORCE ROW LEVEL SECURITY and the Workspace policy used by every tenant table.
*/
return new class extends Migration
{
    private const PERMISSIONS = [
        'data_sources.manage', 'blocks.edit', 'blocks.publish', 'templates.manage', 'users.manage',
        'settings.manage', 'audit.view', 'data.preview_as_user', 'access.manage',
    ];

    public function up(): void
    {
        Schema::create('membership_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces');
            $table->foreignUuid('membership_id')->constrained('workspace_memberships');
            $table->string('permission', 40);
            $table->timestamps();

            $table->unique(['membership_id', 'permission']);
            $table->index('workspace_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $permissions = implode(', ', array_map(fn (string $p): string => "'{$p}'", self::PERMISSIONS));

        DB::unprepared(<<<SQL
            ALTER TABLE membership_permissions ADD CONSTRAINT membership_permissions_permission_check
                CHECK (permission IN ({$permissions}));
            ALTER TABLE membership_permissions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE membership_permissions FORCE ROW LEVEL SECURITY;
            CREATE POLICY workspace_isolation ON membership_permissions
                USING (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid)
                WITH CHECK (workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid);
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_permissions');
    }
};
