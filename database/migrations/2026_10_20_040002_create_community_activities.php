<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('community_activities',function(Blueprint $t){$t->id();$t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();$t->string('type',80);$t->string('title');$t->string('subject_type',80);$t->unsignedBigInteger('subject_id');$t->string('event_key')->unique();$t->json('data');$t->timestamps();$t->index(['subject_type','subject_id']);});}
 public function down():void {Schema::dropIfExists('community_activities');}
};
