<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('tournaments', function (Blueprint $table): void {
            $table->string('system')->default('round_robin');
            $table->unsignedSmallInteger('round_limit')->nullable();
        });
        Schema::table('matches', function (Blueprint $table): void {
            $table->string('bracket')->nullable();
            $table->json('pod_player_ids')->nullable();
            $table->json('pod_scores')->nullable();
        });
    }
    public function down(): void {
        Schema::table('matches', fn (Blueprint $table) => $table->dropColumn(['bracket', 'pod_player_ids', 'pod_scores']));
        Schema::table('tournaments', fn (Blueprint $table) => $table->dropColumn(['system', 'round_limit']));
    }
};
