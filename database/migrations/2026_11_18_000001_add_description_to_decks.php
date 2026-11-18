<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Descrição curta do deck: uma frase do dono que aparece no topo da página para todos que podem ver o deck. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table): void {
            $table->string('description', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('decks', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
