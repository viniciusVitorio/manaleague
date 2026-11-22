<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// E-mail tem chaves próprias, separadas dos avisos do app: uma geral (email_enabled, o aceite do cadastro) e as categorias
// silenciadas só no e-mail (email_muted_types). Ligado por padrão: quem já tem conta continua recebendo.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->boolean('email_enabled')->default(true);
            $table->json('email_muted_types')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', fn (Blueprint $table) => $table->dropColumn(['email_enabled', 'email_muted_types']));
    }
};
