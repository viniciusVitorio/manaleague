<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Acabamento da carta no deck (normal ou foil), como na coleção. A mesma edição pode estar nas duas versões.
        Schema::table('deck_cards', function (Blueprint $table): void {
            $table->boolean('foil')->default(false)->after('board');
            $table->dropUnique(['deck_id', 'scryfall_id', 'board']);
            $table->unique(['deck_id', 'scryfall_id', 'board', 'foil']);
        });
    }

    public function down(): void
    {
        // Volta a uma linha por edição e lado: soma as cópias foil na versão normal antes de remover a coluna.
        foreach (DB::table('deck_cards')->where('foil', true)->get() as $foil) {
            $normal = DB::table('deck_cards')->where(['deck_id' => $foil->deck_id, 'scryfall_id' => $foil->scryfall_id, 'board' => $foil->board, 'foil' => false])->first();
            if ($normal) {
                DB::table('deck_cards')->where('id', $normal->id)->update(['quantity' => min(99, $normal->quantity + $foil->quantity)]);
                DB::table('deck_cards')->where('id', $foil->id)->delete();
            }
        }
        Schema::table('deck_cards', function (Blueprint $table): void {
            $table->dropUnique(['deck_id', 'scryfall_id', 'board', 'foil']);
            $table->unique(['deck_id', 'scryfall_id', 'board']);
            $table->dropColumn('foil');
        });
    }
};
