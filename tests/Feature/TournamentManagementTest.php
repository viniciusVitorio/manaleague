<?php
namespace Tests\Feature;
use App\Models\GameMatch;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
class TournamentManagementTest extends TestCase
{
 use RefreshDatabase;
 public function test_organizer_can_manage_tournament_and_players(): void
 {
  $user=User::factory()->create();
  $this->actingAs($user)->get(route('tournaments.index'))->assertOk();
  $this->actingAs($user)->get(route('tournaments.create'))->assertOk();
  $this->actingAs($user)->post(route('tournaments.store'),['name'=>'Friday Night Magic','format'=>'Pauper','tournament_date'=>'2026-10-10'])->assertRedirect();
  $tournament=Tournament::query()->sole();
  $this->assertNotNull($tournament->public_slug);
  $this->assertNotNull($tournament->invite_token);
  $this->actingAs($user)->get(route('tournaments.show',$tournament))->assertOk();
  $this->actingAs($user)->post(route('players.store',$tournament),['name'=>'Jace','deck_name'=>'Mono Blue','deck_colors'=>'U'])->assertRedirect();
  $player=$tournament->players()->sole();
  $this->actingAs($user)->delete(route('players.destroy',[$tournament,$player]))->assertRedirect();
  $this->assertDatabaseMissing('players',['id'=>$player->id]);
  $this->actingAs($user)->delete(route('tournaments.destroy',$tournament))->assertRedirect(route('tournaments.index'));
  $this->assertDatabaseMissing('tournaments',['id'=>$tournament->id]);
 }
 public function test_organizer_can_start_league_and_record_a_result(): void
 {
  $user=User::factory()->create(); $tournament=$this->tournament($user);
  foreach(['Ajani','Jace','Liliana','Chandra'] as $name){$tournament->players()->create(['name'=>$name]);}
  $this->actingAs($user)->post(route('tournaments.start',$tournament))->assertRedirect();
  $tournament->refresh(); $this->assertSame(Tournament::STATUS_LEAGUE,$tournament->status);
  $match=$tournament->matches()->where('status',GameMatch::STATUS_PENDING)->firstOrFail();
  $this->actingAs($user)->put(route('matches.update',[$tournament,$match]),['player_one_games'=>2,'player_two_games'=>1])->assertRedirect();
  $this->assertSame(GameMatch::STATUS_FINISHED,$match->fresh()->status);
 }
 public function test_invalid_flow_and_scores_return_validation_errors(): void
 {
  $user=User::factory()->create(); $tournament=$this->tournament($user);
  $this->actingAs($user)->post(route('tournaments.start',$tournament))->assertSessionHasErrors('tournament');
  $one=$tournament->players()->create(['name'=>'One']); $two=$tournament->players()->create(['name'=>'Two']);
  $match=$tournament->matches()->create(['round'=>1,'stage'=>GameMatch::STAGE_LEAGUE,'player_one_id'=>$one->id,'player_two_id'=>$two->id]);
  $this->actingAs($user)->put(route('matches.update',[$tournament,$match]),['player_one_games'=>0,'player_two_games'=>0])->assertSessionHasErrors('score');
 }
 private function tournament(User $user): Tournament
 {
  return $user->tournaments()->create(['name'=>'Arena','format'=>'Pauper','tournament_date'=>'2026-10-10','public_token'=>Str::random(40),'public_slug'=>'arena-'.Str::lower(Str::random(8)),'invite_token'=>Str::random(40)]);
 }
}
