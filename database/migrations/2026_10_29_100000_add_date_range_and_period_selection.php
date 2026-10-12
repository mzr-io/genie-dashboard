<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| The dashboard Date Range and the Block Period Selector choice (Story 4.15), both remembered on the server so they follow the member to any device.
| `dashboards.date_range` is a preset token, `custom` (with `range_from`/`range_to`, inclusive local dates) or null for "the Block's own default".
| `dashboard_items.period_selection` is a preset token or null. Which tokens a Block accepts is enforced by the command and the Period kernel, not here.
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
                ADD COLUMN date_range text,
                ADD COLUMN range_from date,
                ADD COLUMN range_to date,
                ADD CONSTRAINT dashboards_date_range_check CHECK (
                    (date_range IS NULL AND range_from IS NULL AND range_to IS NULL)
                    OR (date_range = 'custom' AND range_from IS NOT NULL AND range_to IS NOT NULL AND range_from <= range_to)
                    OR (date_range IS NOT NULL AND date_range <> 'custom' AND range_from IS NULL AND range_to IS NULL)
                );
            ALTER TABLE dashboard_items ADD COLUMN period_selection text;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE dashboard_items DROP COLUMN IF EXISTS period_selection;
            ALTER TABLE dashboards DROP CONSTRAINT IF EXISTS dashboards_date_range_check,
                DROP COLUMN IF EXISTS date_range, DROP COLUMN IF EXISTS range_from, DROP COLUMN IF EXISTS range_to;
            SQL);
    }
};
