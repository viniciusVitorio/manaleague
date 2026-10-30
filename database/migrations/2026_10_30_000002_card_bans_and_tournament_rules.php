<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cartas banidas por formato (copiadas do Scryfall pelo comando cards:sync-bans).
        Schema::create('card_bans', function (Blueprint $table): void {
            $table->id();
            $table->string('format', 30);
            $table->string('card_name', 200);
            $table->timestamps();
            $table->unique(['format', 'card_name']);
        });

        // Regras do torneio: padrão do formato (banidas não entram) ou personalizadas.
        Schema::table('tournaments', function (Blueprint $table): void {
            $table->string('rules', 10)->default('standard');
            $table->boolean('allow_banned')->default(false);
            $table->text('house_rules')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table): void {
            $table->dropColumn(['rules', 'allow_banned', 'house_rules']);
        });
        Schema::dropIfExists('card_bans');
    }
};
