<?php

namespace Tests\Feature;

use App\Models\{Tournament, User, GameMatch};
use App\Services\{PairingService, MatchResultService, StandingsService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompetitionSystemsTest extends TestCase
{
    use RefreshDatabase;

    private function arena(string $system, int $players = 5, array $extra = []): Tournament
    {
        $t = User::factory()->create()->tournaments()->create([
            'name' => 'Competition', 'format' => $system === 'commander_pods' ? 'Commander' : 'Modern',
            'system' => $system, 'public_token' => Str::random(40), 'public_slug' => Str::random(40),
            'invite_token' => Str::random(40), ...$extra,
        ]);
        foreach (range(1, $players) as $i) $t->players()->create(['name' => sprintf('Player %03d', $i)]);
        return $t;
    }

    public function test_swiss_http_progression_locks_prior_rounds_and_finishes(): void
    {
        $t = $this->arena('swiss', 5, ['round_limit' => 3]);
        $this->actingAs($t->user)->post(route('tournaments.start', $t))->assertRedirect();
        $seen = []; $byes = [];
        for ($round = 1; $round <= 3; $round++) {
            foreach ($t->matches()->where('round', $round)->get() as $m) {
                if ($m->status === 'bye') { $this->assertNotContains($m->player_one_id, $byes); $byes[] = $m->player_one_id; continue; }
                $pair = [$m->player_one_id, $m->player_two_id]; sort($pair);
                $this->assertNotContains($pair, $seen); $seen[] = $pair;
                $this->put(route('matches.update', [$t, $m]), ['player_one_games'=>2,'player_two_games'=>0])->assertRedirect();
            }
            $this->post(route('tournaments.advance', $t), ['round'=>$round])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertSame('finished', $t->fresh()->status);
        $m = $t->matches()->where('status','finished')->first();
        $this->put(route('matches.update', [$t, $m]), ['player_one_games'=>0,'player_two_games'=>2])->assertStatus(409);
    }

    public function test_elimination_formats_complete_for_odd_fields_and_reset_final(): void
    {
        foreach (['single_elimination', 'double_elimination'] as $system) {
            $t = $this->arena($system, 7);
            app(PairingService::class)->generateLeague($t);
            $round = 0;
            while ($t->fresh()->status !== 'finished' && ++$round < 25) {
                foreach ($t->matches()->where('status','pending')->get() as $m) {
                    // Losers-bracket finalist wins the first grand final: a reset is mandatory.
                    $upset = $m->bracket === 'grand_final';
                    app(MatchResultService::class)->record($t,$m,['player_one_games'=>$upset?0:2,'player_two_games'=>$upset?2:0]);
                }
                app(\App\Services\CompetitionService::class)->advance($t, $round);
            }
            $this->assertSame('finished',$t->fresh()->status);
            $losses = [];
            foreach ($t->matches()->where('status','finished')->get() as $m) {
                $loser=$m->player_one_games > $m->player_two_games ? $m->player_two_id : $m->player_one_id;
                $losses[$loser]=($losses[$loser]??0)+1;
            }
            $this->assertCount(6,array_filter($losses,fn($n)=>$n===($system==='single_elimination'?1:2)));
            if ($system === 'double_elimination') $this->assertSame(1,$t->matches()->where('bracket','reset_final')->count());
        }
    }

    public function test_commander_pods_validate_placements_and_rank_every_player(): void
    {
        $t=$this->arena('commander_pods',9,['round_limit'=>2]);
        $this->actingAs($t->user)->post(route('tournaments.start',$t))->assertRedirect();
        for($round=1;$round<=2;$round++) {
            $ids=[];
            foreach($t->matches()->where('round',$round)->get() as $m) {
                $this->assertGreaterThanOrEqual(3,count($m->pod_player_ids));
                $this->assertLessThanOrEqual(4,count($m->pod_player_ids));
                $ids=array_merge($ids,$m->pod_player_ids);
                $this->put(route('matches.update',[$t,$m]),['placements'=>array_fill_keys($m->pod_player_ids,1)])->assertSessionHasErrors();
                $this->put(route('matches.update',[$t,$m]),['placements'=>array_combine($m->pod_player_ids,range(1,count($m->pod_player_ids)))])->assertRedirect()->assertSessionHasNoErrors();
            }
            $this->assertCount(9,array_unique($ids));
            $this->post(route('tournaments.advance',$t),['round'=>$round])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertSame('finished',$t->fresh()->status);
        $this->assertTrue(app(StandingsService::class)->for($t)->every(fn($r)=>$r['played']===2));
        $this->get(route('tournaments.show',$t))->assertOk()->assertSee('Pods Commander');
    }

    public function test_canonical_result_accepts_swiss_draws_and_validates_scores(): void
    {
        $t = $this->arena('swiss');
        app(PairingService::class)->generateLeague($t);
        $m = $t->matches()->where('status', 'pending')->firstOrFail();
        app(MatchResultService::class)->record($t, $m, ['player_one_games' => 1, 'player_two_games' => 1]);
        $this->assertSame('finished', $m->fresh()->status);
        $this->assertSame(1, app(StandingsService::class)->for($t)->firstWhere('player.id', $m->player_one_id)['points']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(MatchResultService::class)->record($t, $m, ['player_one_games' => -1, 'player_two_games' => 2]);
    }

    public function test_swiss_start_generates_only_first_round_and_one_bye(): void
    {
        $t = $this->arena('swiss');
        app(PairingService::class)->generateLeague($t);
        $this->assertSame('swiss', $t->fresh()->system);
        $this->assertSame(1, $t->matches()->max('round'));
        $this->assertSame(3, $t->matches()->count());
        $this->assertSame(1, $t->matches()->where('status', 'bye')->count());
        $this->assertSame(3, $t->fresh()->round_limit);
    }
}
