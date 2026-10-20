<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('clubs', function(Blueprint $t){$t->id();$t->string('name',120);$t->text('description')->nullable();$t->string('privacy')->default('private');$t->timestamps();});
  Schema::create('club_user', function(Blueprint $t){$t->id();$t->foreignId('club_id')->constrained()->cascadeOnDelete();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('role')->default('member');$t->timestamps();$t->unique(['club_id','user_id']);});
  Schema::table('tournaments', function(Blueprint $t){$t->foreignId('club_id')->nullable()->constrained()->restrictOnDelete();});
 }
 public function down(): void {Schema::table('tournaments',fn(Blueprint $t)=>$t->dropConstrainedForeignId('club_id'));Schema::dropIfExists('club_user');Schema::dropIfExists('clubs');}
};
