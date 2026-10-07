<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Story 1.17 corrects Story 1.12: a Workspace label is cosmetic descriptive text, not a unique slug. The
| unique index goes, and so does the one column `operator` could read: nothing checks uniqueness any more.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropUnique(['label']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('REVOKE SELECT (label) ON workspaces FROM operator');
        }
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->unique('label');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('GRANT SELECT (label) ON workspaces TO operator');
        }
    }
};
