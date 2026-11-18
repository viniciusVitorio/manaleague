<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Premiação do torneio. tournament_prizes é o que o organizador oferece por colocação (1, 2, 3; 0 = participação):
// boosters (prêmio físico, com quantidade) e um troféu ou medalha de catálogo (colecionável, com nome opcional).
// prize_awards é o prêmio entregue a um inscrito quando o torneio encerra: o booster guarda quando foi recebido,
// o troféu guarda o lugar no destaque do perfil.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_prizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('placement');
            $table->string('kind', 20);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('style', 20)->nullable();
            $table->string('label', 60)->nullable();
            // Arte do troféu (opcional), sempre um WebP quadrado no storage das fotos; sem ela vale o ícone do catálogo.
            $table->string('image_path')->nullable();
            $table->timestamps();
            $table->unique(['tournament_id', 'placement', 'kind']);
        });

        Schema::create('prize_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_prize_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('redeemed_at')->nullable();
            $table->foreignId('redeemed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('showcase_position')->nullable();
            $table->timestamps();
            $table->unique(['tournament_prize_id', 'player_id']);
            $table->index('player_profile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prize_awards');
        Schema::dropIfExists('tournament_prizes');
    }
};
