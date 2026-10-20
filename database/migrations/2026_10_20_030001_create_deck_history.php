<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        Schema::create('deck_versions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('source_deck_id')->index(); $t->unsignedBigInteger('player_profile_id')->index();
            $t->unsignedInteger('number'); $t->string('label', 100); $t->string('fingerprint', 64); $t->json('payload'); $t->timestamp('created_at');
            $t->unique(['source_deck_id', 'number']);
        });
        Schema::create('deck_uses', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('deck_version_id')->index(); $t->unsignedBigInteger('tournament_id')->index();
            $t->unsignedBigInteger('player_id')->unique(); $t->string('tournament_name'); $t->timestamp('created_at');
        });
        // SQLite deployment: enforce append-only even for query-builder writes.
        if (DB::getDriverName() === 'sqlite') foreach (['deck_versions', 'deck_uses'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) DB::unprepared("CREATE TRIGGER {$table}_no_{$operation} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Immutable deck history'); END");
        }
    }
    public function down(): void { Schema::dropIfExists('deck_uses'); Schema::dropIfExists('deck_versions'); }
};
