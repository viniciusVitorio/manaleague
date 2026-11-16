<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link público do deck (ML-023): qualquer pessoa com o link vê a lista, sem conta.
 * share_token fica cifrado (APP_KEY) para o dono copiar o link de novo; a busca pelo link usa só o hash,
 * então um vazamento do banco não entrega links que funcionam. Gerar outro link ou desativar troca/apaga os dois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->text('share_token')->nullable();
            $table->string('share_token_hash', 64)->nullable()->unique();
            $table->timestamp('shared_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->dropUnique(['share_token_hash']);
            $table->dropColumn(['share_token', 'share_token_hash', 'shared_at']);
        });
    }
};
