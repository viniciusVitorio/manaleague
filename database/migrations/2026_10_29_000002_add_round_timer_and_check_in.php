<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Dia de evento: cronômetro da rodada atual (torneio) e check-in dos inscritos (jogador). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->timestamp('round_ends_at')->nullable();
            $table->unsignedSmallInteger('round_minutes')->nullable();
        });
        Schema::table('players', function (Blueprint $table) {
            $table->timestamp('checked_in_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('players', fn (Blueprint $table) => $table->dropColumn('checked_in_at'));
        Schema::table('tournaments', fn (Blueprint $table) => $table->dropColumn(['round_ends_at', 'round_minutes']));
    }
};
