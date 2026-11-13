<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Histórico de vida de uma partida jogada com o contador (o gráfico da partida).
// match_type + match_id apontam para a partida: tournament (GameMatch), challenge (Challenge) ou ranked (RankedMatch).
// Um histórico por partida: vale o do aparelho de quem enviou o placar primeiro.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_life_logs', function (Blueprint $table) {
            $table->id();
            $table->string('match_type', 20);
            $table->unsignedBigInteger('match_id');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('starting_life');
            // Lugares na ordem do contador (ids de jogador ou de perfil, conforme o tipo da partida).
            $table->json('seats');
            // [{winner, points: [[segundos, lugar, vida, veneno], ...], out: [[segundos, lugar], ...]}]
            $table->json('games');
            $table->timestamps();
            $table->unique(['match_type', 'match_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_life_logs');
    }
};
