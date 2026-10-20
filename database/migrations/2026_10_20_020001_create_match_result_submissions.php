<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('match_result_submissions', function(Blueprint $t) {
   $t->id(); $t->foreignId('match_id')->unique()->constrained('matches')->cascadeOnDelete();
   $t->foreignId('submitted_by')->constrained('users'); $t->uuid('idempotency_key');
   $t->unsignedTinyInteger('player_one_games'); $t->unsignedTinyInteger('player_two_games');
   $t->string('status')->default('pending'); $t->text('dispute_reason')->nullable(); $t->text('resolution_reason')->nullable();
   $t->foreignId('reviewed_by')->nullable()->constrained('users'); $t->timestamps();
   $t->unique(['submitted_by','idempotency_key']);
  });
  Schema::create('match_result_submission_events', function(Blueprint $t) {
   $t->id(); $t->foreignId('submission_id')->constrained('match_result_submissions')->cascadeOnDelete();
   $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
   $t->string('action'); $t->json('data'); $t->timestamp('created_at');
  });
 }
 public function down(): void { Schema::dropIfExists('match_result_submission_events'); Schema::dropIfExists('match_result_submissions'); }
};
