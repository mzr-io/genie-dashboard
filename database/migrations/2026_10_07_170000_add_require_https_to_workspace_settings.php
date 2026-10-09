<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| `require_https` (Story 2.3): when on, a Data Source Base URL must be https. Default off (the MVP allows plain http to
| source APIs, marked "Not encrypted"). It is OR-ed with the deployment setting `dashflow.tunables.guards.require_https`.
| Epic 8 adds the editor; until then the value is set by the operator.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_settings', function (Blueprint $table) {
            $table->boolean('require_https')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('workspace_settings', function (Blueprint $table) {
            $table->dropColumn('require_https');
        });
    }
};
