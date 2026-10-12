<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| `dashboards.compact_order` (Story 4.12): the order in which a dashboard's Blocks stack on a phone or any single-column layout, as a JSON list of item
| ids. Null until a person reorders; then new items are appended to it. It is separate from the saved desktop positions, which a reorder never changes.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE dashboards
                ADD COLUMN compact_order jsonb,
                ADD CONSTRAINT dashboards_compact_order_check CHECK (compact_order IS NULL OR jsonb_typeof(compact_order) = 'array');
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('ALTER TABLE dashboards DROP CONSTRAINT IF EXISTS dashboards_compact_order_check, DROP COLUMN IF EXISTS compact_order');
    }
};
