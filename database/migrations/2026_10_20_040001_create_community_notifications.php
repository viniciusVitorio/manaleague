<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('community_notifications',function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('type',80);$t->string('title');$t->string('url',2048);$t->string('event_key');$t->json('data');$t->timestamp('read_at')->nullable();$t->timestamps();$t->unique(['user_id','event_key']);});
  Schema::create('notification_preferences',function(Blueprint $t){$t->id();$t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();$t->boolean('enabled')->default(true);$t->json('muted_types');$t->timestamps();});
 }
 public function down():void {Schema::dropIfExists('notification_preferences');Schema::dropIfExists('community_notifications');}
};
