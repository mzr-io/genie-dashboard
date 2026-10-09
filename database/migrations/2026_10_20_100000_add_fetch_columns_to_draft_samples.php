<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| What a fetched sample needs (Story 3.9), owned by Blocks. Expand-only: columns with defaults on `draft_samples`. RLS and the workspace policy stand.
|
|  sample_revision    a whole number that goes up by one every time the Draft's sample is replaced, by a paste or by a fetch (the first one is 1).
|                     The live preview (Story 3.10) caches per (Draft, sample_revision), so a replaced sample changes the key.
|  request_key        the key of the request a fetched sample was taken with (the client's own, built from the Data Source, the Endpoint, the
|                     parameter values and the default range). Null for a paste. The wizard compares it with the Draft's current key to say a
|                     sample belongs to earlier parameters. Never a secret: it is made of what the Draft's own config already holds.
|  fetch_status       the HTTP status of a fetched sample, null for a paste.
|  fetch_latency_ms   the milliseconds the fetch took, null for a paste.
|
|  `source` stays 'paste' or 'fetch' (the existing check): 'fetch' is "origin = fetched".
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
                ADD COLUMN sample_revision integer NOT NULL DEFAULT 1,
                ADD COLUMN request_key text,
                ADD COLUMN fetch_status smallint,
                ADD COLUMN fetch_latency_ms integer,
                ADD CONSTRAINT draft_samples_revision_check CHECK (sample_revision >= 1);
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE draft_samples
                DROP CONSTRAINT IF EXISTS draft_samples_revision_check,
                DROP COLUMN IF EXISTS fetch_latency_ms,
                DROP COLUMN IF EXISTS fetch_status,
                DROP COLUMN IF EXISTS request_key,
                DROP COLUMN IF EXISTS sample_revision;
            SQL);
    }
};
