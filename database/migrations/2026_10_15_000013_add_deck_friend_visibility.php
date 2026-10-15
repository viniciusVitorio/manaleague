<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table): void {
            // Preserva o comportamento atual para decks existentes; o dono pode ocultar cada deck.
            $table->boolean('is_visible_to_friends')->default(true)->after('is_primary');
        });
    }

    public function down(): void
    {
        Schema::table('decks', function (Blueprint $table): void {
            $table->dropColumn('is_visible_to_friends');
        });
    }
};
