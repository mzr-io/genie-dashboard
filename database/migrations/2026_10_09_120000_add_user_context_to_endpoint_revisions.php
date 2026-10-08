<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| User-context bindings on Endpoint revisions (Story 2.13), owned by Connector.
|
|  requires_user_context  derived by `ManageEndpoints` when it inserts a revision: true when a parameter or a header uses a
|                         user binding (`user_id`, `user_email`, `user_group`, `user_attribute`). Never client-supplied.
|  scope_by_caller        optional; may be true only when `requires_user_context` is (a CHECK). Stored and audited now because
|                         the revision is immutable; Story 2.14's `FetchKeyResolver` reads it to add the membership ID to `ctx`.
|
| Both are set on insert only: the immutability trigger on `endpoint_revisions` is unchanged and still fails every UPDATE and
| DELETE. Existing revisions get false and false.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('endpoint_revisions', function ($table) {
            $table->boolean('requires_user_context')->default(false);
            $table->boolean('scope_by_caller')->default(false);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE endpoint_revisions ADD CONSTRAINT endpoint_revisions_scope_by_caller_check
                CHECK (NOT scope_by_caller OR requires_user_context);
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE endpoint_revisions DROP CONSTRAINT IF EXISTS endpoint_revisions_scope_by_caller_check');
        }

        Schema::table('endpoint_revisions', function ($table) {
            $table->dropColumn(['requires_user_context', 'scope_by_caller']);
        });
    }
};
