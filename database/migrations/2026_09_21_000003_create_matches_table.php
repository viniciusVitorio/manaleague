<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('round');
            $table->string('stage', 20)->default('league');
            $table->foreignId('player_one_id')->constrained('players')->cascadeOnDelete();
            $table->foreignId('player_two_id')->nullable()->constrained('players')->cascadeOnDelete();
            $table->unsignedTinyInteger('player_one_games')->nullable();
            $table->unsignedTinyInteger('player_two_games')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('played_at')->nullable();
            $table->timestamps();
            $table->index(['tournament_id', 'stage', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
