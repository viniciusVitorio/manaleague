<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_profiles', function (Blueprint $table): void {
            $table->foreignId('account_user_id')->nullable()->unique()->after('user_id')->constrained('users')->nullOnDelete();
        });

        Schema::create('decks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_profile_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('colors', 10)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('player_profile_user', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_profile_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'player_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_profile_user');
        Schema::dropIfExists('decks');
        Schema::table('player_profiles', fn (Blueprint $table) => $table->dropConstrainedForeignId('account_user_id'));
    }
};
