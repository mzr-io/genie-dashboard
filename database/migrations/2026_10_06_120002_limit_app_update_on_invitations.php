<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| `app` marks an invitation used; it never rewrites its email, hash, expiry or Workspace. UPDATE is
| therefore limited to the two columns the accept flow writes. SELECT stays table-wide (the flow reads by
| token hash), so `app` can still read invitee emails, hashes and Workspace IDs.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            REVOKE UPDATE ON invitations FROM app;
            GRANT UPDATE (used_at, updated_at) ON invitations TO app;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            REVOKE UPDATE (used_at, updated_at) ON invitations FROM app;
            GRANT UPDATE ON invitations TO app;
            SQL);
    }
};
