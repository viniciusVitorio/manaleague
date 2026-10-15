<?php

use App\Models\PlayerProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_profiles', function (Blueprint $table): void {
            $table->string('friend_code', 12)->nullable()->unique()->after('nickname');
            // false = o perfil so pode ser encontrado pelo codigo de amigo exato, nunca pela busca por apelido.
            $table->boolean('discoverable')->default(true)->after('friend_code');
        });

        DB::table('player_profiles')->orderBy('id')->eachById(function (object $profile): void {
            DB::table('player_profiles')->where('id', $profile->id)->update(['friend_code' => PlayerProfile::generateFriendCode()]);
        });
    }

    public function down(): void
    {
        Schema::table('player_profiles', function (Blueprint $table): void {
            $table->dropUnique(['friend_code']);
            $table->dropColumn(['friend_code', 'discoverable']);
        });
    }
};
