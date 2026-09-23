<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_profile_user', fn (Blueprint $table) => $table->string('status', 16)->default('accepted')->after('player_profile_id'));
    }

    public function down(): void
    {
        Schema::table('player_profile_user', fn (Blueprint $table) => $table->dropColumn('status'));
    }
};
