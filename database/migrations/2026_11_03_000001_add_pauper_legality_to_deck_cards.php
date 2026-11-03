<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Legalidade da carta no Pauper como o Scryfall informa (legal, not_legal, banned). Vale para a carta,
        // não para a edição: o Scryfall já considera todas as impressões. Nula = ainda não consultada
        // (cartas antigas); o comando cards:sync-legality preenche.
        Schema::table('deck_cards', function (Blueprint $table): void {
            $table->string('pauper_legality', 12)->nullable()->after('rarity');
        });
    }

    public function down(): void
    {
        Schema::table('deck_cards', function (Blueprint $table): void {
            $table->dropColumn('pauper_legality');
        });
    }
};
