<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_profiles', function (Blueprint $table): void {
            // Chave do objeto no disco de avatares (ex.: avatars/<uuid>.webp). O emoji continua em "avatar".
            $table->string('avatar_path', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('player_profiles', function (Blueprint $table): void {
            $table->dropColumn('avatar_path');
        });
    }
};
