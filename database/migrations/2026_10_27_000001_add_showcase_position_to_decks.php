<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Vitrine do perfil: posição (1 a 3) do deck escolhido pelo jogador. Nulo = fora da vitrine. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->unsignedTinyInteger('showcase_position')->nullable()->after('is_visible_to_friends');
            $table->index(['player_profile_id', 'showcase_position']);
        });
    }

    public function down(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->dropIndex(['player_profile_id', 'showcase_position']);
            $table->dropColumn('showcase_position');
        });
    }
};
