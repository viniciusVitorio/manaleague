<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->unsignedTinyInteger('pod_qualifiers')->default(2);
            $table->boolean('pod_random_draw')->default(true);
            $table->string('pod_tiebreak', 20)->default('manual');
            $table->boolean('pod_consolation')->default(false);
        });
        Schema::table('matches', function (Blueprint $table) {
            $table->unsignedSmallInteger('pod_number')->nullable();
            $table->json('pod_tiebreak_scores')->nullable();
            $table->text('pod_resolution_note')->nullable();
            $table->timestamp('pod_confirmed_at')->nullable();
            $table->foreignId('pod_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['tournament_id', 'round', 'pod_number'], 'matches_pod_round_unique');
        });
        Schema::create('elimination_pod_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->unsignedSmallInteger('round');
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->unique(['tournament_id', 'round', 'player_id'], 'pod_seats_player_round_unique');
            $table->unique(['match_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('elimination_pod_seats');
        Schema::table('matches', function (Blueprint $table) {
            $table->dropUnique('matches_pod_round_unique');
            $table->dropConstrainedForeignId('pod_confirmed_by');
            $table->dropColumn(['pod_number', 'pod_tiebreak_scores', 'pod_resolution_note', 'pod_confirmed_at']);
        });
        Schema::table('tournaments', fn (Blueprint $table) => $table->dropColumn(['pod_qualifiers', 'pod_random_draw', 'pod_tiebreak', 'pod_consolation']));
    }
};
