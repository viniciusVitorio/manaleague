<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('email_verification_required')->default(true);
        });
        // Preserva o acesso de contas existentes sem afirmar que o endereço foi confirmado.
        DB::table('users')->update(['email_verification_required' => false]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('email_verification_required'));
    }
};
