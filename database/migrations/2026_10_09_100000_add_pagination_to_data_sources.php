<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Pagination on a Data Source (Story 2.11), an expand-only change owned by Connector.
|
|  data_sources.pagination_style           `none` (the default: one request), `page`, `offset`, `cursor` or `link_header`.
|  data_sources.pagination_param           the query parameter that carries the page number, the offset or the cursor.
|  data_sources.pagination_size_param      the query parameter that carries the page size, and `pagination_size` its value;
|  data_sources.pagination_size            both set or both null (a positive whole number),
|                                          never for `link_header`.
|  data_sources.pagination_records_path    where the records array sits in each page (a dotted path); null means the response
|                                          root is the array.
|  data_sources.pagination_cursor_path     where the next cursor sits in the response, for `cursor` only.
|
| Every column except the style is null unless the style needs it; the CHECKs keep that.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->string('pagination_style', 16)->default('none');
            $table->string('pagination_param', 64)->nullable();
            $table->string('pagination_size_param', 64)->nullable();
            $table->unsignedInteger('pagination_size')->nullable();
            $table->string('pagination_records_path', 255)->nullable();
            $table->string('pagination_cursor_path', 255)->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE data_sources ADD CONSTRAINT data_sources_pagination_style_check
                CHECK (pagination_style IN ('none', 'page', 'offset', 'cursor', 'link_header'));

            ALTER TABLE data_sources ADD CONSTRAINT data_sources_pagination_check CHECK (
                CASE WHEN pagination_style = 'none'
                    THEN pagination_param IS NULL AND pagination_size_param IS NULL AND pagination_size IS NULL
                         AND pagination_records_path IS NULL AND pagination_cursor_path IS NULL
                    ELSE (pagination_style = 'link_header') = (pagination_param IS NULL)
                         AND (pagination_style = 'cursor') = (pagination_cursor_path IS NOT NULL)
                         AND (pagination_size_param IS NULL) = (pagination_size IS NULL)
                         AND (pagination_style <> 'link_header' OR pagination_size IS NULL)
                         AND (pagination_size IS NULL OR pagination_size > 0)
                END);
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE data_sources DROP CONSTRAINT IF EXISTS data_sources_pagination_check;
                ALTER TABLE data_sources DROP CONSTRAINT IF EXISTS data_sources_pagination_style_check;
                SQL);
        }

        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn(['pagination_style', 'pagination_param', 'pagination_size_param', 'pagination_size', 'pagination_records_path', 'pagination_cursor_path']);
        });
    }
};
