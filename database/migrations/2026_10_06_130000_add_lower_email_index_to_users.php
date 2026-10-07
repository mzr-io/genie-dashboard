<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Sign-in and invitations look users up with `lower(email) = ?`; the plain unique index on `email`
| cannot serve that, so a functional index does. PostgreSQL only.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('CREATE INDEX IF NOT EXISTS users_email_lower_index ON users (lower(email))');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP INDEX IF EXISTS users_email_lower_index');
    }
};
