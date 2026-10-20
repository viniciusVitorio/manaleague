<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ranked ELO/PDL — grupo A (temporadas, ratings, partidas, ledger, snapshots).
 * Somente tabelas/colunas novas; nada do legado e alterado de forma destrutiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_admin')->default(false);
        });

        Schema::table('player_profiles', function (Blueprint $t) {
            $t->timestamp('ranked_suspended_until')->nullable();
            $t->boolean('ranked_public')->default(true); // aparece com apelido no ranking (pode ocultar)
        });

        Schema::create('ranked_seasons', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('slug', 140)->unique();
            $t->string('status', 20)->default('draft'); // draft|scheduled|active|closing|finished
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->string('scope', 20)->default('global'); // global|club
            $t->foreignId('club_id')->nullable()->constrained()->nullOnDelete();
            $t->string('rules_version', 40);
            $t->json('settings')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['status', 'starts_at']);
        });

        Schema::create('ranked_season_formats', function (Blueprint $t) {
            $t->id();
            $t->foreignId('season_id')->constrained('ranked_seasons')->cascadeOnDelete();
            $t->string('format', 40);
            $t->boolean('enabled')->default(true);
            $t->unsignedSmallInteger('k_factor')->default(32);
            $t->unsignedSmallInteger('starting_rating')->default(1000);
            $t->boolean('allow_draws')->default(true);
            $t->timestamps();
            $t->unique(['season_id', 'format']);
        });

        Schema::create('player_ratings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('season_id')->constrained('ranked_seasons')->restrictOnDelete();
            $t->string('format', 40);
            $t->foreignId('player_profile_id')->constrained()->restrictOnDelete();
            $t->integer('rating');
            $t->unsignedInteger('played')->default(0);
            $t->unsignedInteger('wins')->default(0);
            $t->unsignedInteger('losses')->default(0);
            $t->unsignedInteger('draws')->default(0);
            $t->integer('peak_rating');
            $t->unsignedSmallInteger('provisional_games_remaining')->default(5);
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->unique(['season_id', 'format', 'player_profile_id']);
            $t->index(['season_id', 'format', 'rating']);
        });

        Schema::create('ranked_matches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('season_id')->constrained('ranked_seasons')->restrictOnDelete();
            $t->string('format', 40);
            $t->foreignId('player_one_profile_id')->constrained('player_profiles')->restrictOnDelete();
            $t->foreignId('player_two_profile_id')->constrained('player_profiles')->restrictOnDelete();
            $t->string('source_type', 20); // challenge|matchmaking
            $t->unsignedBigInteger('source_id');
            $t->string('status', 20)->default('pending'); // pending|reported|disputed|confirmed|invalidated|cancelled
            $t->unsignedTinyInteger('best_of')->default(3);
            $t->boolean('is_ranked')->default(true);
            $t->unsignedSmallInteger('k_factor');
            $t->string('rules_version', 40);
            $t->unsignedTinyInteger('score_one')->nullable();
            $t->unsignedTinyInteger('score_two')->nullable();
            $t->timestamp('played_at')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('invalidated_at')->nullable();
            $t->foreignId('invalidated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('invalidation_reason', 500)->nullable();
            $t->timestamps();
            $t->unique(['source_type', 'source_id']);
            $t->index(['season_id', 'format', 'status', 'confirmed_at']);
            $t->index(['player_one_profile_id', 'created_at']);
            $t->index(['player_two_profile_id', 'created_at']);
        });

        Schema::create('ranked_result_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ranked_match_id')->constrained()->cascadeOnDelete();
            $t->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $t->unsignedTinyInteger('score_one');
            $t->unsignedTinyInteger('score_two');
            $t->string('status', 20)->default('pending'); // pending|confirmed|disputed|resolved|superseded
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('dispute_reason', 1000)->nullable();
            $t->string('resolution_reason', 1000)->nullable();
            $t->uuid('idempotency_key');
            $t->string('ip_address', 45)->nullable(); // retencao limitada (ranked:prune)
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['submitted_by', 'idempotency_key']);
            $t->index(['ranked_match_id', 'status']);
        });

        Schema::create('rating_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ranked_match_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('season_id')->constrained('ranked_seasons')->restrictOnDelete();
            $t->string('format', 40);
            $t->foreignId('player_profile_id')->constrained()->restrictOnDelete();
            $t->integer('rating_before');
            $t->integer('rating_after');
            $t->integer('delta');
            $t->decimal('expected_score', 6, 4)->nullable();
            $t->decimal('actual_score', 3, 1)->nullable();
            $t->unsignedSmallInteger('k_factor')->nullable();
            $t->string('event_type', 20); // match|correction
            $t->unsignedInteger('correction_seq')->default(0); // 0 para 'match'; n-esima correcao
            $t->boolean('floor_applied')->default(false);
            $t->string('rules_version', 40);
            $t->string('note', 255)->nullable();
            $t->timestamp('effective_at');
            $t->timestamps();
            // Impede aplicar o mesmo resultado duas vezes ao mesmo jogador.
            $t->unique(['ranked_match_id', 'player_profile_id', 'event_type', 'correction_seq'], 'rating_tx_once');
            $t->index(['season_id', 'format', 'player_profile_id', 'effective_at'], 'rating_tx_history');
        });

        Schema::create('rating_season_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('season_id')->constrained('ranked_seasons')->restrictOnDelete();
            $t->string('format', 40);
            $t->foreignId('player_profile_id')->constrained()->restrictOnDelete();
            $t->integer('final_rating');
            $t->integer('peak_rating');
            $t->unsignedInteger('rank')->nullable(); // null quando abaixo do minimo de partidas
            $t->string('tier', 40);
            $t->json('stats');
            $t->timestamps();
            $t->unique(['season_id', 'format', 'player_profile_id']);
        });

        Schema::create('ranked_review_flags', function (Blueprint $t) {
            $t->id();
            $t->foreignId('season_id')->constrained('ranked_seasons')->cascadeOnDelete();
            $t->string('format', 40);
            $t->foreignId('profile_a_id')->constrained('player_profiles')->cascadeOnDelete();
            $t->foreignId('profile_b_id')->constrained('player_profiles')->cascadeOnDelete();
            $t->string('reason', 60);
            $t->string('details', 500);
            $t->string('status', 20)->default('open'); // open|dismissed|actioned
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['season_id', 'format', 'profile_a_id', 'profile_b_id', 'reason'], 'ranked_flag_once');
        });

        // Grupo B — fila de matchmaking presencial.
        Schema::create('matchmaking_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('player_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignId('season_id')->constrained('ranked_seasons')->cascadeOnDelete();
            $t->string('format', 40);
            $t->foreignId('club_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 20)->default('searching'); // searching|proposed|matched|cancelled|expired
            $t->integer('rating_at_join');
            $t->string('session_note', 120)->nullable(); // ex.: "loja X, sexta 19h" (sem localizacao precisa)
            $t->timestamp('joined_at');
            $t->timestamp('last_confirmed_at');
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->index(['season_id', 'format', 'club_id', 'status', 'joined_at'], 'mm_queue');
            $t->index(['player_profile_id', 'status']);
        });

        Schema::create('matchmaking_proposals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('entry_one_id')->constrained('matchmaking_entries')->cascadeOnDelete();
            $t->foreignId('entry_two_id')->constrained('matchmaking_entries')->cascadeOnDelete();
            $t->string('status', 20)->default('pending'); // pending|accepted|declined|expired
            $t->unsignedInteger('rating_gap');
            $t->timestamp('expires_at');
            $t->timestamp('accepted_one_at')->nullable();
            $t->timestamp('accepted_two_at')->nullable();
            $t->foreignId('ranked_match_id')->nullable()->unique()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['status', 'expires_at']);
        });

        Schema::table('challenges', function (Blueprint $t) {
            $t->boolean('is_ranked')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('challenges', fn (Blueprint $t) => $t->dropColumn('is_ranked'));
        foreach (['matchmaking_proposals', 'matchmaking_entries', 'ranked_review_flags', 'rating_season_snapshots', 'rating_transactions', 'ranked_result_submissions', 'ranked_matches', 'player_ratings', 'ranked_season_formats', 'ranked_seasons'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('player_profiles', fn (Blueprint $t) => $t->dropColumn(['ranked_suspended_until', 'ranked_public']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_admin'));
    }
};
