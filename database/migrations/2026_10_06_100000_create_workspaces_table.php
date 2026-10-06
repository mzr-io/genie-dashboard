<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| `workspaces` is a global table (it has no workspace_id). Its rows are reachable only through
| the Access-owned SECURITY DEFINER function `access_user_memberships`, so the runtime role
| `app` gets no privilege on it at all.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->requireRoles(['app', 'migrator', 'maintenance', 'system', 'operator']);
        }

        Schema::create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('label')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            // Default privileges would have granted `app` access; the function is the only path.
            DB::unprepared('REVOKE ALL ON TABLE workspaces FROM app');
        }
    }

    /**
     * @param  list<string>  $roles
     */
    private function requireRoles(array $roles): void
    {
        $present = array_column(DB::select('select rolname from pg_roles where rolname in ('.implode(',', array_fill(0, count($roles), '?')).')', $roles), 'rolname');
        $missing = array_values(array_diff($roles, $present));

        if ($missing !== []) {
            throw new RuntimeException('Missing database role(s): '.implode(', ', $missing).'. Create them with docker/postgres/initdb.sh before migrating.');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
