<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| What a pasted sample needs (Story 3.8), owned by Blocks. Expand-only: columns with defaults and an index on `draft_samples`.
|
|  expires_at              the sample's hard end: the moment it was stored plus `tunables.timeouts.draft_sample_ttl`. A sample past it is
|                          never read and is deleted by the `maintenance` purge. Null only for a row written before this column existed.
|  contains_personal_data  the Admin's acknowledgement that the sample may hold personal data; a sample is not stored without it.
|  created_by_membership_id  who pasted it (a plain column, no foreign key across modules).
|  draft_samples_version_unique  one sample per Draft: a new paste replaces the old one in place (the `app` role holds no DELETE).
|
|  `body` holds the sealed text (base64 of a libsodium `secretbox` under the workspace `data` key), never the sample in the clear;
|  `size_bytes` is the size of the sample itself. RLS and the workspace policy of the table stand.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE draft_samples
                ADD COLUMN expires_at timestamptz,
                ADD COLUMN contains_personal_data boolean NOT NULL DEFAULT false,
                ADD COLUMN created_by_membership_id uuid;
            CREATE UNIQUE INDEX draft_samples_version_unique ON draft_samples (block_version_id);
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS draft_samples_version_unique;
            ALTER TABLE draft_samples
                DROP COLUMN IF EXISTS created_by_membership_id,
                DROP COLUMN IF EXISTS contains_personal_data,
                DROP COLUMN IF EXISTS expires_at;
            SQL);
    }
};
