<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| `timezone` (Story 4.1): the Workspace's IANA time zone, in which every Block's period is resolved (FR-35, AD-27). The user's own
| time zone is for display only. Default `UTC`; Epic 8 adds the editor, and until then the value is set by the operator.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_settings', function (Blueprint $table) {
            $table->string('timezone', 64)->default('UTC');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_settings', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
