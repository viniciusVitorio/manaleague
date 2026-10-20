<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table): void {
            // Carta escolhida pelo dono para o banner do deck. Sem FK para evitar ciclo decks <-> deck_cards:
            // se a carta sair do deck o valor fica orfao e o banner volta para a escolha automatica.
            $table->unsignedBigInteger('cover_card_id')->nullable()->after('is_visible_to_friends');
        });

        Schema::table('players', function (Blueprint $table): void {
            // Deck salvo usado na inscricao (deck_name/deck_colors continuam como copia para o historico).
            $table->foreignId('deck_id')->nullable()->after('player_profile_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('players', fn (Blueprint $table) => $table->dropConstrainedForeignId('deck_id'));
        Schema::table('decks', fn (Blueprint $table) => $table->dropColumn('cover_card_id'));
    }
};
