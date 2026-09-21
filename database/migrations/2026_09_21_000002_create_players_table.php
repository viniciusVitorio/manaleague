<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('deck_name', 100)->nullable();
            $table->string('deck_colors', 10)->nullable();
            $table->timestamps();
            $table->unique(['tournament_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
