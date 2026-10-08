<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| OAuth2 client credentials (Story 2.7), an expand-only change owned by Connector.
|
|  data_sources.oauth_token_url / oauth_client_id / oauth_scope   where the token is requested, who asks and for what scope.
|                                                                 Plain values, not secrets. All null unless `auth_type` is
|                                                                 `oauth2_client_credentials` (a CHECK keeps that), and the URL
|                                                                 and client ID are set for it. The scope is optional.
|  secrets.version                                                 a whole number, 1 when a secret is first set and raised in
|                                                                 place on every replace. The token cache key carries it, so a
|                                                                 replaced client secret never meets an old cached token.
|  secrets_slot_check                                              widened to take the `oauth_client_secret` slot.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->string('oauth_token_url', 2048)->nullable();
            $table->string('oauth_client_id', 255)->nullable();
            $table->string('oauth_scope', 512)->nullable();
        });

        Schema::table('secrets', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE data_sources ADD CONSTRAINT data_sources_oauth_check CHECK (
                CASE WHEN auth_type = 'oauth2_client_credentials'
                    THEN oauth_token_url IS NOT NULL AND oauth_client_id IS NOT NULL
                    ELSE oauth_token_url IS NULL AND oauth_client_id IS NULL AND oauth_scope IS NULL
                END);

            ALTER TABLE secrets ADD CONSTRAINT secrets_version_check CHECK (version >= 1);

            ALTER TABLE secrets DROP CONSTRAINT secrets_slot_check;
            ALTER TABLE secrets ADD CONSTRAINT secrets_slot_check
                CHECK (slot ~ '^(api_key|bearer_token|basic_username|basic_password|oauth_client_secret|header:[!#$%&''*+.^_`|~0-9a-z-]{1,128})$');
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // The owner is bound by the policy too, so the force is lifted for the delete only.
            DB::unprepared(<<<'SQL'
                ALTER TABLE secrets DROP CONSTRAINT IF EXISTS secrets_slot_check;
                ALTER TABLE secrets NO FORCE ROW LEVEL SECURITY;
                DELETE FROM secrets WHERE slot = 'oauth_client_secret';
                ALTER TABLE secrets FORCE ROW LEVEL SECURITY;
                -- Without the token URL and client ID an OAuth2 source cannot call anything: it goes back to no authentication.
                ALTER TABLE data_sources DROP CONSTRAINT IF EXISTS data_sources_oauth_check;
                ALTER TABLE data_sources NO FORCE ROW LEVEL SECURITY;
                UPDATE data_sources SET auth_type = 'none' WHERE auth_type = 'oauth2_client_credentials';
                ALTER TABLE data_sources FORCE ROW LEVEL SECURITY;
                ALTER TABLE secrets ADD CONSTRAINT secrets_slot_check
                    CHECK (slot ~ '^(api_key|bearer_token|basic_username|basic_password|header:[!#$%&''*+.^_`|~0-9a-z-]{1,128})$');
                ALTER TABLE secrets DROP CONSTRAINT IF EXISTS secrets_version_check;
                SQL);
        }

        Schema::table('secrets', function (Blueprint $table) {
            $table->dropColumn('version');
        });

        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn(['oauth_token_url', 'oauth_client_id', 'oauth_scope']);
        });
    }
};
