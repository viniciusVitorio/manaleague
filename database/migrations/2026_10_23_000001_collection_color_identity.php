<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coleção: identidade de cor para filtrar por cor ("WU", "C" = incolor, null = desconhecida).
 * Itens antigos herdam a cor da mesma impressão em algum deck; o resto vem com `php artisan collection:refresh`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_items', function (Blueprint $table) {
            $table->string('color_identity', 10)->nullable()->after('rarity');
        });

        DB::table('collection_items')->whereNull('color_identity')->update([
            'color_identity' => DB::raw("(SELECT COALESCE(NULLIF(dc.color_identity, ''), 'C') FROM deck_cards dc WHERE lower(dc.scryfall_id) = lower(collection_items.scryfall_id) LIMIT 1)"),
        ]);
    }

    public function down(): void
    {
        Schema::table('collection_items', function (Blueprint $table) {
            $table->dropColumn('color_identity');
        });
    }
};
