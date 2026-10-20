<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('challenges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('challenger_id')->constrained('player_profiles')->cascadeOnDelete();
            $t->foreignId('opponent_id')->constrained('player_profiles')->cascadeOnDelete();
            $t->string('format', 60); $t->unsignedTinyInteger('best_of');
            $t->string('status')->default('pending'); $t->timestamp('expires_at');
            $t->timestamps(); $t->index(['status', 'expires_at']);
        });
        Schema::create('casual_matches', function (Blueprint $t) {
            $t->id(); $t->foreignId('challenge_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('status')->default('pending');
            $t->unsignedTinyInteger('challenger_games')->nullable(); $t->unsignedTinyInteger('opponent_games')->nullable();
            $t->json('game_order')->nullable();
            $t->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('dispute_reason')->nullable(); $t->timestamp('confirmed_at')->nullable();
            $t->unsignedInteger('revision')->default(0); $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('casual_matches'); Schema::dropIfExists('challenges'); }
};
