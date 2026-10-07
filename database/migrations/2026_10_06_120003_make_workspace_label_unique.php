<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| A Workspace label is unique. The operator command checks it before it writes anything, so `operator` may
| read that one column of `workspaces` (column-level SELECT; no table-level privilege).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->unique('label');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('GRANT SELECT (label) ON workspaces TO operator');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('REVOKE SELECT (label) ON workspaces FROM operator');
        }

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropUnique(['label']);
        });
    }
};
