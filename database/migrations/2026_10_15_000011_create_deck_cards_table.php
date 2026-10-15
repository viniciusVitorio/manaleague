<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table): void {
            $table->string('format', 30)->nullable()->after('colors');
        });

        Schema::create('deck_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deck_id')->constrained()->cascadeOnDelete();
            $table->uuid('scryfall_id');
            $table->string('board', 12)->default('main'); // main | side | commander
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('name', 200);
            $table->string('mana_cost', 60)->nullable();
            $table->decimal('cmc', 5, 1)->default(0);
            $table->string('type_line', 200)->nullable();
            $table->string('color_identity', 10)->nullable();
            $table->string('rarity', 20)->nullable();
            $table->string('set_code', 10)->nullable();
            $table->string('image_url', 500)->nullable();
            $table->string('art_url', 500)->nullable();
            $table->timestamps();
            $table->unique(['deck_id', 'scryfall_id', 'board']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deck_cards');
        Schema::table('decks', fn (Blueprint $table) => $table->dropColumn('format'));
    }
};
