<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Registro dos e-mails de pódio: uma linha por pessoa, torneio e colocação garante que ninguém recebe o mesmo aviso duas vezes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_podium_emails', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('placement');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['tournament_id', 'user_id', 'placement']);
            $table->index(['sent_at', 'claimed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_podium_emails');
    }
};
