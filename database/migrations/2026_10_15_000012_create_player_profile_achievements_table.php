<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_profile_achievements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_profile_id')->constrained()->cascadeOnDelete();
            $table->string('achievement', 40);
            $table->timestamp('unlocked_at');
            $table->timestamp('seen_at')->nullable();
            $table->unique(['player_profile_id', 'achievement']);
            $table->index('achievement');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_profile_achievements');
    }
};
