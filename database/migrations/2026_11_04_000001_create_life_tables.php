<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mesa do contador de vida compartilhada entre os celulares: uma por partida (torneio, Ranked, desafio)
        // ou uma "mesa rapida" sem partida cadastrada, aberta por codigo/QR.
        Schema::create('life_tables', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('code', 8)->nullable()->unique();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('seats');
            $table->json('settings')->nullable();
            $table->json('seat_users')->nullable();
            $table->unsignedInteger('seq')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'subject_id']);
            $table->index('last_activity_at');
        });

        // Jogadas em ordem (seq). O servidor so ordena e valida o formato; cada celular aplica as mesmas
        // jogadas na mesma ordem e chega ao mesmo placar. client_id evita jogada repetida no reenvio offline.
        Schema::create('life_ops', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('life_table_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->string('client_id', 40);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('op');
            $table->timestamp('created_at')->nullable();
            $table->unique(['life_table_id', 'seq']);
            $table->unique(['life_table_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('life_ops');
        Schema::dropIfExists('life_tables');
    }
};
