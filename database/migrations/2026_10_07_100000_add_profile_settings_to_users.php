<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Per-person settings (Story 1.18). `users` is a global table, so these belong to the person, not to a
| Workspace. A null locale or time zone means "not chosen": the client falls back to the catalogue
| locale and to the browser's time zone. `avatar_path` is the random file name on the private `avatars` disk.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 20)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('avatar_path', 100)->nullable();
            $table->boolean('keyboard_shortcuts')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['locale', 'timezone', 'avatar_path', 'keyboard_shortcuts']);
        });
    }
};
