<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('achievement_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_profile_id')->constrained()->cascadeOnDelete();
            $table->string('achievement', 80);
            $table->string('token_hash', 64)->unique();
            $table->timestamps();
            $table->unique(['player_profile_id', 'achievement']);
        });
    }
    public function down(): void { Schema::dropIfExists('achievement_shares'); }
};
