<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('player_profiles', function(Blueprint $table): void {
   $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete();
   $table->string('nickname',80); $table->string('avatar',255)->nullable();
   $table->string('preferred_deck_name',100)->nullable(); $table->string('preferred_deck_colors',10)->nullable();
   $table->boolean('is_friend')->default(false); $table->timestamps();
   $table->unique(['user_id','nickname']);
  });
  Schema::table('players',function(Blueprint $table): void {$table->foreignId('player_profile_id')->nullable()->after('tournament_id')->constrained()->nullOnDelete();});
  DB::table('players')->orderBy('id')->eachById(function(object $player): void {
   $tournament=DB::table('tournaments')->where('id',$player->tournament_id)->first();
   if(!$tournament)return;
   $profileId=DB::table('player_profiles')->where('user_id',$tournament->user_id)->whereRaw('lower(nickname) = ?', [mb_strtolower($player->name)])->value('id');
   if(!$profileId){$profileId=DB::table('player_profiles')->insertGetId(['user_id'=>$tournament->user_id,'nickname'=>$player->name,'preferred_deck_name'=>$player->deck_name,'preferred_deck_colors'=>$player->deck_colors,'created_at'=>now(),'updated_at'=>now()]);}
   DB::table('players')->where('id',$player->id)->update(['player_profile_id'=>$profileId]);
  });
 }
 public function down(): void {Schema::table('players',fn(Blueprint $table)=>$table->dropConstrainedForeignId('player_profile_id'));Schema::dropIfExists('player_profiles');}
};