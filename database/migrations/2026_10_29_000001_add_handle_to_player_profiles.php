<?php

use App\Models\PlayerProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Endereço público do perfil pelo apelido (/p/ana) em vez do id sequencial. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_profiles', function (Blueprint $table) {
            $table->string('handle', 40)->nullable()->unique()->after('nickname');
        });

        // Preenche os perfis existentes, do mais antigo ao mais novo (quem chegou primeiro fica com o apelido limpo).
        $taken = [];
        DB::table('player_profiles')->orderBy('id')->select(['id', 'nickname'])->lazyById()->each(function ($row) use (&$taken) {
            $handle = PlayerProfile::uniqueHandle((string) $row->nickname, fn (string $h) => isset($taken[$h]));
            $taken[$handle] = true;
            DB::table('player_profiles')->where('id', $row->id)->update(['handle' => $handle]);
        });
    }

    public function down(): void
    {
        Schema::table('player_profiles', function (Blueprint $table) {
            $table->dropUnique(['handle']);
            $table->dropColumn('handle');
        });
    }
};
