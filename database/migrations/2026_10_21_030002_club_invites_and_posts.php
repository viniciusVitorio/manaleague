<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Clubes: link de convite (entra mesmo em clube privado), cidade/local e mural de avisos. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->string('invite_token', 64)->nullable()->unique();
            $table->string('location', 120)->nullable();
        });
        Schema::create('club_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->boolean('pinned')->default(false);
            $table->timestamps();
            $table->index(['club_id', 'pinned', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_posts');
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropUnique(['invite_token']);
            $table->dropColumn(['invite_token', 'location']);
        });
    }
};
