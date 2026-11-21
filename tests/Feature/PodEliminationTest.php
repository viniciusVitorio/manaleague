<?php

namespace Tests\Feature;

use App\Models\CardBan;
use App\Models\Player;
use App\Models\Tournament;
use App\Models\User;
use App\Services\LifeCounterService;
use App\Services\LiveDisplayService;
use App\Services\MatchDetailService;
use App\Services\PairingService;
use App\Services\PlayerStatsService;
use App\Services\PodEliminationService;
use App\Services\PrizeService;
use App\Services\StandingsService;
use App\Services\TournamentChampionService;
use App\Services\TournamentRecapService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PodEliminationTest extends TestCase
{
    use RefreshDatabase;

    private function arena(int $count = 8, array $options = []): Tournament
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $t = $owner->tournaments()->create(array_merge([
            'name' => 'Commander Cup', 'format' => 'Commander', 'system' => 'pod_elimination',
            'tournament_date' => today(), 'max_players' => 8,
            'public_token' => Str::random(40), 'public_slug' => Str::random(24), 'invite_token' => Str::random(40),
        ], $options));
        foreach (range(1, $count) as $i) {
            $t->players()->create(['name' => 'Player '.$i])->forceFill(['checked_in_at' => now()])->save();
        }

        return $t;
    }

    private function recordPod(Tournament $t, $match, array $ranks = [1, 2, 3, 4], array $extra = [])
    {
        return $this->put(route('matches.update', [$t, $match]), array_merge([
            'placements' => array_combine($match->pod_player_ids, $ranks), 'confirm' => 1,
        ], $extra));
    }

    public static function noncanonicalSeatKeys(): array
    {
        $cases = [];
        foreach (['placements', 'tiebreak'] as $field) {
            foreach (['leading-zero', 'suffix'] as $shape) {
                foreach ([0, 1] as $confirm) {
                    $cases["{$field}-{$shape}-{$confirm}"] = [$field, $shape, $confirm];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('noncanonicalSeatKeys')]
    public function test_noncanonical_seat_keys_are_rejected_without_mutation(string $field, string $shape, int $confirm): void
    {
        $t = $this->arena(8, ['pod_random_draw' => false]);
        $this->assertSame(0, $t->players()->whereNotNull('player_profile_id')->count());
        app(PairingService::class)->generateLeague($t);
        $m = $t->matches()->first();
        $places = array_combine($m->pod_player_ids, [1, 2, 2, 4]);
        $resolved = array_combine($m->pod_player_ids, [1, 2, 3, 4]);
        $map = $field === 'placements' ? $places : $resolved;
        $id = array_key_first($map);
        $badKey = $shape === 'leading-zero' ? '0'.$id : $id.'suffix';
        $map[$badKey] = $map[$id];
        unset($map[$id]);
        $this->put(route('matches.update', [$t, $m]), [
            'placements' => $field === 'placements' ? $map : $places,
            'tiebreak' => $field === 'tiebreak' ? $map : $resolved,
            'resolution_note' => 'Playoff decision', 'confirm' => $confirm,
        ])->assertRedirect()->assertSessionHasErrors($field);
        $m->refresh();
        $this->assertNull($m->pod_scores);
        $this->assertNull($m->pod_tiebreak_scores);
        $this->assertNull($m->pod_confirmed_at);
        $this->assertSame('pending', $m->status);
        $this->assertSame(2, $t->matches()->count());
        $this->assertCount(8, app(StandingsService::class)->for($t->fresh()));
    }

    public function test_confirmed_semifinals_advance_only_top_two_once_after_all_results(): void
    {
        $t = $this->arena(8, ['pod_random_draw' => false]);
        app(PairingService::class)->generateLeague($t);
        $pods = $t->matches()->get();
        $this->recordPod($t, $pods[0])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $t->matches()->count());
        $this->get(route('arena.public', $t->public_slug))->assertOk()->assertDontSee('Líder:');
        $t->refresh()->forceFill(['round_ends_at' => now()->addHour()])->save();
        $this->recordPod($t, $pods[1])->assertRedirect()->assertSessionHasNoErrors();
        $final = $t->matches()->where('stage', 'final')->sole();
        $expected = $pods->flatMap(fn ($m) => array_slice($m->pod_player_ids, 0, 2))->all();
        $this->assertEqualsCanonicalizing($expected, $final->pod_player_ids);
        $this->assertNull($t->fresh()->round_ends_at);
        $this->assertSame('finals', $t->fresh()->status);
        $this->recordPod($t, $pods[0])->assertStatus(409);
        $this->assertSame(3, $t->matches()->count());
        $t->refresh()->forceFill(['round_ends_at' => now()->addHour()])->save();
        $this->recordPod($t, $final)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('finished', $t->fresh()->status);
        $this->assertNull($t->fresh()->round_ends_at);
        $this->assertSame($final->pod_player_ids[0], app(TournamentChampionService::class)->for($t->fresh())->id);
        $ranking = app(StandingsService::class)->for($t->fresh());
        $this->assertSame([1, 2, 3, 4, 5, 5, 5, 5], $ranking->pluck('rank')->all());
    }

    public function test_ties_are_preserved_and_cutoff_requires_explicit_documented_resolution(): void
    {
        $t = $this->arena();
        app(PairingService::class)->generateLeague($t);
        [$a, $b] = $t->matches()->get()->all();
        $this->recordPod($t, $a, [1, 2, 2, 4], ['confirm' => 0])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([1, 2, 2, 4], array_values($a->fresh()->pod_scores));
        $this->assertNull($a->fresh()->pod_confirmed_at);
        $this->recordPod($t, $a, [1, 2, 2, 4])->assertSessionHasErrors('tiebreak');
        $resolution = array_combine($a->pod_player_ids, [1, 2, 3, 4]);
        $this->recordPod($t, $a, [1, 2, 2, 4], ['tiebreak' => $resolution])->assertSessionHasErrors('resolution_note');
        $this->recordPod($t, $a, [1, 2, 2, 4], ['tiebreak' => $resolution, 'resolution_note' => 'Desempate jogado e confirmado na mesa.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->recordPod($t, $b, [1, 2, 3, 3])->assertRedirect()->assertSessionHasNoErrors();
        $final = $t->matches()->where('stage', 'final')->sole();
        $this->assertContains($a->pod_player_ids[1], $final->pod_player_ids);
        $this->assertNotContains($a->pod_player_ids[2], $final->pod_player_ids);
        $this->recordPod($t, $final, [1, 1, 3, 4])->assertSessionHasErrors('tiebreak');
        $this->recordPod($t, $final, [1, 2, 3, 3])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([1, 2, 3, 3, 5, 5, 5, 5], app(StandingsService::class)->for($t->fresh())->pluck('rank')->all());
        $this->assertSame([1, 2, 2, 4], array_values($a->fresh()->pod_scores));
    }

    public function test_consolation_can_finish_after_final_and_never_becomes_champion(): void
    {
        foreach ([true, false] as $finalFirst) {
            $t = $this->arena(8, ['pod_consolation' => true]);
            app(PairingService::class)->generateLeague($t);
            foreach ($t->matches()->get() as $m) {
                $this->recordPod($t, $m)->assertSessionHasNoErrors();
            }
            $final = $t->matches()->where('stage', 'final')->sole();
            $consolation = $t->matches()->where('stage', 'consolation')->sole();
            $this->assertEmpty(array_intersect($final->pod_player_ids, $consolation->pod_player_ids));
            $this->recordPod($t, $finalFirst ? $final : $consolation)->assertSessionHasNoErrors();
            $this->assertSame('finals', $t->fresh()->status);
            $this->recordPod($t, $finalFirst ? $consolation : $final)->assertSessionHasNoErrors();
            $this->assertSame('finished', $t->fresh()->status);
            $champion = app(TournamentChampionService::class)->for($t->fresh());
            $this->assertSame($final->pod_player_ids[0], $champion->id);
            $this->assertSame($champion->id, app(TournamentRecapService::class)->for($t->fresh())['champion']->id);
            $this->assertSame(range(1, 8), app(StandingsService::class)->for($t->fresh())->pluck('rank')->all());
        }
    }

    public function test_repeat_preserves_settings_and_manual_advance_cannot_bypass_pending_confirmations(): void
    {
        $t = $this->arena(8, ['pod_consolation' => true, 'pod_random_draw' => false, 'pod_tiebreak' => 'playoff']);
        $this->post(route('tournaments.repeat', $t), ['name' => 'Next Cup', 'tournament_date' => today()->addWeek()->toDateString(), 'copy_players' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $copy = Tournament::where('id', '!=', $t->id)->sole();
        $this->assertTrue($copy->pod_consolation);
        $this->assertFalse($copy->pod_random_draw);
        $this->assertSame('playoff', $copy->pod_tiebreak);
        $this->assertSame(0, $copy->matches()->count());
        $this->assertSame(0, $copy->players()->whereNotNull('checked_in_at')->count());
        app(PairingService::class)->generateLeague($t);
        $this->post(route('tournaments.advance', $t), ['round' => 1])->assertSessionHasErrors('tournament');
        $this->assertSame(2, $t->matches()->count());
    }

    public function test_multiplayer_history_stats_prizes_and_achievements_do_not_invent_duel_results(): void
    {
        $t = $this->arena();
        foreach ($t->players()->get() as $p) {
            $profile = User::factory()->create()->ensurePlayerProfile();
            $p->update(['player_profile_id' => $profile->id, 'deck_id' => $profile->decks()->create(['name' => 'Commander', 'format' => 'Commander'])->id]);
        }
        $t->prizes()->create(['placement' => 1, 'kind' => 'booster', 'quantity' => 2]);
        $t->prizes()->create(['placement' => 0, 'kind' => 'booster', 'quantity' => 1]);
        app(PairingService::class)->generateLeague($t);
        foreach ($t->matches()->get() as $m) {
            $this->recordPod($t, $m)->assertSessionHasNoErrors();
        }
        $final = $t->matches()->where('stage', 'final')->sole();
        $this->recordPod($t, $final)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('ranked_matches', 0);
        $this->assertDatabaseCount('rating_transactions', 0);
        foreach ($t->players()->with('profile')->get() as $p) {
            $stats = app(PlayerStatsService::class)->for($p->profile);
            $this->assertSame(0, $stats['played'], 'Pods must not produce fake duel draws for any seat');
            $this->assertSame(1, $stats['facts']['tournaments_finished']);
            $this->assertSame((int) ($p->id === $final->pod_player_ids[0]), $stats['facts']['titles']);
        }
        $this->assertDatabaseCount('prize_awards', 9);
        app(PrizeService::class)->sync($t->fresh());
        $this->assertDatabaseCount('prize_awards', 9);
    }

    public function test_live_pod_numbers_distinguish_tables_without_exposing_private_result_metadata(): void
    {
        $t = $this->arena(8, ['pod_random_draw' => false]);
        app(PairingService::class)->generateLeague($t);
        $pods = $t->matches()->orderBy('pod_number')->get();
        $this->recordPod($t, $pods[0], [1, 2, 2, 4], [
            'tiebreak' => array_combine($pods[0]->pod_player_ids, [1, 2, 3, 4]),
            'resolution_note' => 'Private organizer decision',
        ])->assertSessionHasNoErrors();
        $snapshot = app(LiveDisplayService::class)->snapshot($t->fresh());
        $this->assertSame([1, 2], array_column($snapshot['matches'], 'pod_number'));
        $this->assertSame(['Semifinais', 'Semifinais'], array_column($snapshot['matches'], 'phase'));
        $this->assertSame(['id', 'round', 'stage', 'status', 'one', 'two', 'one_score', 'two_score', 'pod_number', 'phase', 'confirmed', 'seats'], array_keys($snapshot['matches'][0]));
        $this->assertSame(['name', 'place', 'advancing'], array_keys($snapshot['matches'][0]['seats'][0]));
        $this->assertSame([1, 2, 3, 4], array_column($snapshot['matches'][0]['seats'], 'place'));
        $this->assertSame([null, null, null, null], array_column($snapshot['matches'][1]['seats'], 'place'));
        $this->assertStringNotContainsString('Private organizer decision', json_encode($snapshot));
        $this->get(route('arena.tv', $t->public_slug))->assertOk()
            ->assertSee('Mesa 1 · Semifinais')->assertSee('Mesa 2 · Semifinais')->assertDontSee('Private organizer decision');
        $this->get(route('arena.public', $t->public_slug))->assertOk()
            ->assertSee('Mesa 1')->assertSee('Mesa 2')->assertDontSee('Private organizer decision');
    }

    public function test_pod_ui_and_public_snapshot_show_every_seat_and_never_duel_brackets(): void
    {
        $t = $this->arena(8, ['pod_consolation' => true]);
        app(PairingService::class)->generateLeague($t);
        $this->get(route('tournaments.show', $t))->assertOk()->assertSee('Semifinais')->assertSee('Confirmar posições')->assertSee('Salvar provisório')->assertDontSee('Concluir rodada / avançar')->assertDontSee('Resultados enviados')->assertDontSee('@else', false);
        $snapshot = app(LiveDisplayService::class)->snapshot($t->fresh());
        $this->assertCount(4, $snapshot['matches'][0]['seats']);
        $this->assertArrayNotHasKey('pod_confirmed_by', $snapshot['matches'][0]);
        $this->assertArrayNotHasKey('resolution_note', $snapshot['matches'][0]);
        $this->get(route('arena.public', $t->public_slug))->assertOk()->assertSee('Semifinais')->assertDontSee('Confirmar posições');
        foreach ($t->matches()->get() as $m) {
            $this->recordPod($t, $m)->assertSessionHasNoErrors();
        }
        $this->get(route('tournaments.show', $t))->assertOk()->assertSee('Grande final')->assertSee('Consolação')->assertSee('5º ao 8º');
        $this->get(route('arena.tv', $t->public_slug))->assertOk()->assertSee('Grande final');
        foreach ($t->matches()->where('round', 2)->get() as $m) {
            $this->recordPod($t, $m)->assertSessionHasNoErrors();
        }
        $this->get(route('tournaments.show', $t))->assertOk()->assertSee('é o campeão')->assertDontSee('Games</dt>', false);
        $this->get(route('arena.public', $t->public_slug))->assertOk()->assertSee('Classificação final')->assertDontSee('Critérios: pontos');
    }

    public function test_three_player_elimination_can_be_created_configured_and_started_over_http(): void
    {
        $this->actingAs(User::factory()->create());
        $data = ['name' => 'Three seat final', 'format' => 'Commander', 'system' => 'pod_elimination',
            'tournament_date' => today()->toDateString(), 'max_players' => 3];
        $this->post(route('tournaments.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $t = Tournament::sole();
        $this->assertSame(3, $t->max_players);
        $this->get(route('tournaments.edit', $t))->assertOk()->assertSee('name="max_players" min="3"', false);
        $this->put(route('tournaments.update', $t), $data)->assertRedirect()->assertSessionHasNoErrors();
        foreach (range(1, 3) as $i) {
            $t->players()->create(['name' => 'Guest '.$i])->forceFill(['checked_in_at' => now()])->save();
        }
        $html = $this->get(route('tournaments.show', $t))->assertOk()->assertSee('Com 3 ou mais jogadores')->getContent();
        $this->assertMatchesRegularExpression('/<button[^>]*(?<!disabled)>Começar torneio<\/button>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*disabled[^>]*>Começar torneio<\/button>/', $html);
        $this->post(route('tournaments.start', $t))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('finals', $t->fresh()->status);
        $this->assertCount(3, $t->matches()->sole()->pod_player_ids);
    }

    public function test_other_systems_keep_four_player_configuration_and_start_ui_minimum(): void
    {
        foreach (['round_robin', 'swiss', 'commander_pods', 'single_elimination', 'double_elimination'] as $system) {
            $t = $this->arena(3, ['system' => $system]);
            $data = ['name' => 'Legacy minimum', 'format' => 'Commander', 'system' => $system,
                'tournament_date' => today()->toDateString(), 'max_players' => 3];
            $this->post(route('tournaments.store'), $data)->assertSessionHasErrors('max_players');
            $html = $this->get(route('tournaments.show', $t))->assertOk()->assertSee('Com 4 ou mais jogadores')->getContent();
            $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>Começar torneio<\/button>/', $html);
        }
    }

    public function test_creation_form_exposes_commander_only_settings_and_keeps_legacy_pods(): void
    {
        $this->actingAs(User::factory()->create())->get(route('tournaments.create'))->assertOk()
            ->assertSee('Eliminação por Pods')->assertSee('name="pod_qualifiers"', false)
            ->assertSee('name="pod_consolation"', false)->assertSee('name="pod_tiebreak"', false)
            ->assertSee('name="pod_random_draw"', false)->assertSee('Pods Commander');
    }

    public function test_invalid_structures_and_actual_checkin_count_leave_setup_unchanged(): void
    {
        $t = $this->arena(5);
        $this->post(route('tournaments.start', $t))->assertSessionHasErrors('tournament');
        $this->assertSame('setup', $t->fresh()->status);
        $this->assertSame(0, $t->matches()->count());
        $this->assertDatabaseCount('deck_uses', 0);
        $t = $this->arena();
        $p = $t->players()->first();
        $p->forceFill(['checked_in_at' => null])->save();
        $this->post(route('tournaments.start', $t))->assertSessionHasErrors('tournament');
        $this->assertSame(0, $t->matches()->count());
        $owner = $t->user;
        $data = ['name' => 'Invalid', 'format' => 'Commander', 'system' => 'pod_elimination', 'tournament_date' => today()->toDateString(), 'max_players' => 8];
        foreach ([['format' => 'Modern'], ['ranked' => 1], ['max_players' => 5], ['pod_qualifiers' => 1], ['pod_qualifiers' => 3], ['max_players' => 16, 'pod_consolation' => 1]] as $bad) {
            $this->actingAs($owner)->post(route('tournaments.store'), array_merge($data, $bad))->assertSessionHasErrors();
        }
    }

    public function test_all_supported_plans_have_exact_recursive_partitions_and_unsupported_plans_are_rejected(): void
    {
        $service = app(PodEliminationService::class);
        foreach ([1, 2] as $q) {
            $supported = [3 => true, 4 => true];
            foreach (range(5, 128) as $n) {
                $valid = false;
                for ($pods = (int) ceil($n / 4); $pods <= intdiv($n, 3); $pods++) {
                    $valid = $valid || isset($supported[$pods * $q]);
                }
                if ($valid) {
                    $supported[$n] = true;
                }
                try {
                    $plan = $service->plan($n, $q);
                    $this->assertTrue($valid, "Unexpected plan for {$n}/{$q}");
                    $current = $n;
                    foreach ($plan as $sizes) {
                        $this->assertSame($current, array_sum($sizes));
                        foreach ($sizes as $size) {
                            $this->assertContains($size, [3, 4]);
                        }
                        $current = count($sizes) * $q;
                    }
                    $this->assertCount(1, $plan[array_key_last($plan)]);
                } catch (\LogicException $e) {
                    $this->assertFalse($valid, "Missing valid plan for {$n}/{$q}: {$e->getMessage()}");
                }
            }
        }
    }

    public function test_authorization_and_invalid_placements_never_mutate_results(): void
    {
        $t = $this->arena();
        $owner = $t->user;
        app(PairingService::class)->generateLeague($t);
        $m = $t->matches()->first();
        $this->actingAs(User::factory()->create());
        $this->recordPod($t, $m)->assertForbidden();
        $this->post(route('tournaments.start', $t))->assertForbidden();
        $this->actingAs($owner);
        foreach ([[1, 2, 2, 3], [0, 2, 3, 4], [1, 2, 3, 5]] as $ranks) {
            $this->recordPod($t, $m, $ranks)->assertSessionHasErrors();
        }
        $this->put(route('matches.update', [$t, $m]), ['placements' => [99999 => 1]])->assertSessionHasErrors('placements');
        $this->assertNull($m->fresh()->pod_scores);
        $this->assertSame('pending', $m->fresh()->status);
        $this->post(route('tournaments.start', $t))->assertSessionHasErrors('tournament');
        $this->assertSame(2, $t->matches()->count());
        $this->recordPod($t, $m, [1, 2, 3, 4], ['confirm' => 0])->assertSessionHasNoErrors();
        app(PodEliminationService::class)->progress($t, 1);
        $this->assertSame(2, $t->matches()->count());
        $this->assertNull($m->fresh()->pod_confirmed_at);
    }

    public function test_database_uniqueness_and_progression_retry_do_not_duplicate_phase_or_seats(): void
    {
        $t = $this->arena();
        app(PairingService::class)->generateLeague($t);
        foreach ($t->matches()->get() as $m) {
            $this->recordPod($t, $m)->assertSessionHasNoErrors();
        }
        app(PodEliminationService::class)->progress($t, 1);
        app(PodEliminationService::class)->progress($t, 1);
        $this->assertSame(3, $t->matches()->count());
        $this->assertDatabaseCount('elimination_pod_seats', 12);
        $seat = (array) DB::table('elimination_pod_seats')->first();
        unset($seat['id']);
        $this->expectException(QueryException::class);
        DB::table('elimination_pod_seats')->insert($seat);
    }

    public function test_tied_podium_does_not_arbitrarily_choose_one_prize_recipient(): void
    {
        $t = $this->arena();
        $t->prizes()->create(['placement' => 2, 'kind' => 'booster', 'quantity' => 1]);
        app(PairingService::class)->generateLeague($t);
        foreach ($t->matches()->get() as $m) {
            $this->recordPod($t, $m)->assertSessionHasNoErrors();
        }
        $final = $t->matches()->where('stage', 'final')->sole();
        $this->recordPod($t, $final, [1, 2, 2, 4])->assertSessionHasNoErrors();
        $ranking = app(StandingsService::class)->for($t->fresh());
        $this->assertCount(2, $ranking->where('rank', 2));
        $this->assertCount(0, $ranking->where('podium', 2));
        $this->assertDatabaseCount('prize_awards', 0);
    }

    public function test_fourth_seat_has_history_life_counter_and_resolved_positions_but_cannot_confirm(): void
    {
        $t = $this->arena();
        $owner = $t->user;
        foreach ($t->players()->get() as $p) {
            $profile = User::factory()->create()->ensurePlayerProfile();
            $p->update(['player_profile_id' => $profile->id, 'deck_id' => $profile->decks()->create(['name' => 'Commander', 'format' => 'Commander'])->id]);
        }
        app(PairingService::class)->generateLeague($t);
        $m = $t->matches()->first();
        $p = Player::find($m->pod_player_ids[3]);
        $account = $p->profile->account;
        $config = app(LifeCounterService::class)->forTournament($t->fresh(), $m, $account);
        $this->assertCount(4, $config['players']);
        $this->assertSame(3, $config['meIndex']);
        $this->assertNull($config['submit']);
        $this->assertSame(40, $config['startingLife']);
        $this->actingAs($account)->get('/me/matches')->assertOk()->assertSee('Mesa Commander');
        $this->recordPod($t, $m)->assertForbidden();
        $this->actingAs($owner);
        $ranks = [1, 2, 3, 3];
        $this->recordPod($t, $m, $ranks, ['tiebreak' => array_combine($m->pod_player_ids, [1, 2, 4, 3]), 'resolution_note' => 'Desempate de classificação solicitado na mesa.'])->assertSessionHasNoErrors();
        $detail = app(MatchDetailService::class)->for('tournament', $m->id, $account);
        $this->assertCount(4, $detail['players']);
        $this->assertContains('Semifinais', $detail['context']);
        $this->assertSame(3, collect($detail['players'])->firstWhere('me', true)['place']);
        $this->actingAs($account)->get('/me/matches')->assertOk()->assertSee('você ficou em 3º');
    }

    public function test_counter_style_submission_without_explicit_confirmation_stays_provisional(): void
    {
        $t = $this->arena();
        app(PairingService::class)->generateLeague($t);
        $m = $t->matches()->first();
        $this->put(route('matches.update', [$t, $m]), ['placements' => array_combine($m->pod_player_ids, [1, 2, 3, 4])])->assertSessionHasNoErrors();
        $this->assertSame('pending', $m->fresh()->status);
        $this->assertNull($m->fresh()->pod_confirmed_at);
        $this->assertSame(2, $t->matches()->count());
    }

    public function test_start_rechecks_account_decks_and_commander_bans_before_any_snapshot(): void
    {
        $t = $this->arena();
        $p = $t->players()->first();
        $profile = User::factory()->create()->ensurePlayerProfile();
        $p->update(['player_profile_id' => $profile->id]);
        try {
            app(PairingService::class)->generateLeague($t);
            $this->fail('Missing account deck must prevent start');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('deck', $e->getMessage());
        }
        $deck = $profile->decks()->create(['name' => 'Commander deck', 'format' => 'Commander']);
        $p->update(['deck_id' => $deck->id]);
        $deck->cards()->create(['scryfall_id' => (string) Str::uuid(), 'name' => 'Black Lotus', 'quantity' => 1, 'board' => 'main', 'type_line' => 'Artifact']);
        CardBan::create(['format' => 'Commander', 'card_name' => 'Black Lotus']);
        try {
            app(PairingService::class)->generateLeague($t);
            $this->fail('Banned Commander card must prevent start');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Commander', $e->getMessage());
        }
        $this->assertSame('setup', $t->fresh()->status);
        $this->assertSame(0, $t->matches()->count());
        $this->assertDatabaseCount('deck_uses', 0);
    }

    public function test_recursive_progression_supports_three_seat_final_and_multiple_elimination_phases(): void
    {
        foreach ([[9, 1], [16, 2]] as [$count, $q]) {
            $t = $this->arena($count, ['max_players' => $count, 'pod_qualifiers' => $q]);
            app(PairingService::class)->generateLeague($t);
            $last = [];
            $round = 1;
            while ($t->fresh()->status !== 'finished') {
                $matches = $t->matches()->where('round', $round)->get();
                $this->assertNotEmpty($matches->all());
                $current = $matches->pluck('pod_player_ids')->flatten()->all();
                $this->assertCount(count(array_unique($current)), $current);
                if ($last) {
                    $this->assertEqualsCanonicalizing($last, $current);
                }
                $last = [];
                foreach ($matches as $m) {
                    $ranks = range(1, count($m->pod_player_ids));
                    $this->recordPod($t, $m, $ranks)->assertSessionHasNoErrors();
                    $last = array_merge($last, array_slice($m->pod_player_ids, 0, $q));
                }
                $round++;
                $this->assertLessThan(10, $round);
            }
            $final = $t->matches()->where('stage', 'final')->sole();
            $this->assertContains(count($final->pod_player_ids), [3, 4]);
            $this->assertCount($count, app(StandingsService::class)->for($t->fresh()));
        }
    }

    public function test_phase_number_has_a_database_unique_constraint(): void
    {
        $t = $this->arena();
        app(PairingService::class)->generateLeague($t);
        $m = $t->matches()->first();
        $this->expectException(QueryException::class);
        $t->matches()->create($m->only(['round', 'stage', 'pod_number', 'player_one_id', 'player_two_id', 'pod_player_ids', 'status']));
    }

    public function test_create_and_start_eight_player_commander_elimination(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->post(route('tournaments.store'), [
            'name' => 'Pod Cup', 'format' => 'Commander', 'system' => 'pod_elimination',
            'tournament_date' => today()->toDateString(), 'max_players' => 8,
            'pod_qualifiers' => 2, 'pod_random_draw' => 1, 'pod_tiebreak' => 'manual', 'pod_consolation' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $t = Tournament::sole();
        foreach (range(1, 8) as $i) {
            $t->players()->create(['name' => 'Player '.$i])->forceFill(['checked_in_at' => now()])->save();
        }
        $this->post(route('tournaments.start', $t))->assertRedirect()->assertSessionHasNoErrors();
        $pods = $t->matches()->get();
        $this->assertCount(2, $pods);
        $this->assertSame([4, 4], $pods->map(fn ($m) => count($m->pod_player_ids))->all());
        $this->assertCount(8, $pods->pluck('pod_player_ids')->flatten()->unique());
        $this->assertSame('league', $t->fresh()->status);
    }
}
