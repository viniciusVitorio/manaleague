<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Coleção: marcar cópias para troca e guardar o preço de referência (USD, Scryfall) no momento da inclusão. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_items', function (Blueprint $table) {
            $table->unsignedInteger('for_trade')->default(0)->after('quantity'); // quantas cópias estão no fichário de trocas
            $table->decimal('price_usd', 10, 2)->nullable()->after('for_trade');
            $table->string('type_line')->nullable()->after('set_code');
            $table->string('rarity', 20)->nullable()->after('type_line');
            $table->index(['user_id', 'for_trade']);
        });
    }

    public function down(): void
    {
        Schema::table('collection_items', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'for_trade']);
            $table->dropColumn(['for_trade', 'price_usd', 'type_line', 'rarity']);
        });
    }
};
