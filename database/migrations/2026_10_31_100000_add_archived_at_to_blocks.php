<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| blocks.archived_at (Story 6.1): when a Block was archived. An archived Block is read-only: it cannot be edited into a new Draft. Expand-only, nullable,
| no default; archiving itself (Story 6.9) sets it.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('ALTER TABLE blocks ADD COLUMN archived_at timestamptz');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('ALTER TABLE blocks DROP COLUMN archived_at');
    }
};
