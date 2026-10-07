<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| The runtime role `app` never gets DELETE on a tenant table (only `maintenance` does). The framework
| itself, however, deletes from a few global tables as the runtime role: database sessions (logout and
| garbage collection), password-reset tokens, API token revocation, the database cache and locks, and
| the database queue's job tables. DELETE is granted on exactly these tables and nothing else.
*/
return new class extends Migration
{
    private const TABLES = [
        'sessions', 'password_reset_tokens', 'personal_access_tokens',
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (DB::selectOne("select 1 as ok from pg_roles where rolname = 'app'") === null) {
            throw new RuntimeException('Missing database role(s): app. Create them with docker/postgres/initdb.sh before migrating.');
        }

        DB::unprepared('GRANT DELETE ON TABLE '.implode(', ', self::TABLES).' TO app');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('REVOKE DELETE ON TABLE '.implode(', ', self::TABLES).' FROM app');
    }
};
