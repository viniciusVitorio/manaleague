<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\CommunityNotification;
use App\Models\MatchmakingEntry;
use App\Models\MatchmakingProposal;
use App\Models\PlayerProfile;
use App\Models\PlayerRating;
use App\Models\RankedMatch;
use App\Models\RankedSeason;
use App\Models\RatingTransaction;
use App\Models\User;
use App\Services\ChallengeService;
use App\Services\Community\ClubService;
use App\Services\Ranked\MatchmakingService;
use App\Services\Ranked\RankedMatchService;
use App\Services\Ranked\RatingLedgerService;
use App\Services\Ranked\SeasonService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RankedEloTest extends TestCase
{
    use RefreshDatabase;

    private function season(array $formats = ['Pauper', 'Modern'], int $k = 32, array $settings = [], string $status = 'active'): RankedSeason
    {
        $s = RankedSeason::create(['name' => 'Temporada 1', 'slug' => 'temporada-1-'.Str::random(5), 'status' => $status, 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
            'scope' => 'global', 'rules_version' => 'elo-v1', 'settings' => $settings ?: null]);
        foreach ($formats as $f) {
            $s->formats()->create(['format' => $f, 'k_factor' => $k, 'starting_rating' => 1000, 'allow_draws' => true]);
        }

        return $s->fresh('formats');
    }

    private function player(string $name): User
    {
        $u = User::factory()->create(['name' => $name]);
        $u->ensurePlayerProfile();

        return $u->fresh();
    }

    private function friends(User $a, User $b): void
    {
        $a->friendProfiles()->attach($b->playerProfile, ['status' => 'accepted']);
        $b->friendProfiles()->attach($a->playerProfile, ['status' => 'accepted']);
    }

    private function match(RankedSeason $s, User $a, User $b, string $format = 'Pauper', int $bestOf = 3): RankedMatch
    {
        static $source = 1000;

        return app(RankedMatchService::class)->create($s, $format, $a->playerProfile, $b->playerProfile, 'challenge', $source++, $bestOf);
    }

    /** Envia (a) e confirma (b) um placar. */
    private function play(RankedMatch $m, User $a, User $b, int $one, int $two): RankedMatch
    {
        app(RankedMatchService::class)->submit($m, $a, $one, $two, (string) Str::uuid());

        return app(RankedMatchService::class)->confirm($m, $b);
    }

    private function rating(RankedSeason $s, User $u, string $format = 'Pauper'): int
    {
        return PlayerRating::where(['season_id' => $s->id, 'format' => $format, 'player_profile_id' => $u->playerProfile->id])->value('rating');
    }

    private function assertLedgerMatchesRatings(RankedSeason $s): void
    {
        foreach (PlayerRating::where('season_id', $s->id)->get() as $r) {
            $sum = RatingTransaction::where(['season_id' => $s->id, 'format' => $r->format, 'player_profile_id' => $r->player_profile_id])->sum('delta');
            $this->assertSame($r->rating, 1000 + (int) $sum, 'ledger e rating divergem');
        }
    }

    public function test_ranked_challenge_flow_applies_opposite_deltas_once_and_notifies(): void
    {
        $s = $this->season();
        $a = $this->player('Ana');
        $b = $this->player('Bruno');
        $this->friends($a, $b);
        $outsider = $this->player('Zé');

        $this->actingAs($a)->post('/challenges', ['opponent_id' => $b->playerProfile->id, 'format' => 'Pauper', 'best_of' => 3, 'expires_in' => 24, 'ranked' => 1])->assertRedirect();
        $challenge = Challenge::sole();
        $this->assertTrue($challenge->is_ranked);
        $this->actingAs($b)->post("/challenges/{$challenge->id}/transition", ['action' => 'accept'])->assertRedirect();
        $m = RankedMatch::sole();
        $this->assertSame(['challenge', $challenge->id, 32], [$m->source_type, $m->source_id, $m->k_factor]);
        $this->assertNull($challenge->casualMatch()->first());

        $key = (string) Str::uuid();
        $this->actingAs($a)->postJson("/ranked/matches/{$m->id}/results", ['score_one' => 2, 'score_two' => 1, 'idempotency_key' => $key])->assertCreated();
        $this->postJson("/ranked/matches/{$m->id}/results", ['score_one' => 2, 'score_two' => 1, 'idempotency_key' => $key])->assertOk(); // replay idempotente
        $this->postJson("/ranked/matches/{$m->id}/confirm")->assertForbidden(); // quem enviou nao confirma
        $this->actingAs($outsider)->postJson("/ranked/matches/{$m->id}/confirm")->assertForbidden();
        $this->actingAs($outsider)->get("/ranked/matches/{$m->id}")->assertForbidden();
        $this->assertNull(PlayerRating::where('player_profile_id', $a->playerProfile->id)->value('played') ?: null);

        $this->actingAs($b)->postJson("/ranked/matches/{$m->id}/confirm")->assertOk();
        $this->actingAs($b)->postJson("/ranked/matches/{$m->id}/confirm")->assertOk(); // segunda confirmacao: nada muda
        $this->assertSame([1016, 984], [$this->rating($s, $a), $this->rating($s, $b)]);
        $this->assertSame(2, RatingTransaction::count());
        $this->assertSame('completed', $challenge->fresh()->status);
        $this->assertLedgerMatchesRatings($s);
        $this->assertTrue(CommunityNotification::where('user_id', $a->id)->where('title', 'like', '%+16 PDL%')->exists());
        $this->assertTrue(CommunityNotification::where('user_id', $b->id)->where('title', 'like', '%-16 PDL%')->exists());
        $this->actingAs($b)->get('/feed')->assertSee('ranked Pauper');
        $this->actingAs($outsider)->get('/feed')->assertDontSee('ranked Pauper');
        $this->actingAs($a)->get("/ranked/matches/{$m->id}")->assertOk()->assertSee('1000 → 1016')->assertSee('esperado 50.0%');
    }

    public function test_ledger_rejects_duplicate_application_at_database_level(): void
    {
        $s = $this->season();
        $a = $this->player('A');
        $b = $this->player('B');
        $m = $this->play($this->match($s, $a, $b), $a, $b, 2, 0);
        DB::transaction(fn () => $this->assertSame([], app(RatingLedgerService::class)->applyMatch($m->fresh())));
        $this->expectException(QueryException::class);
        DB::table('rating_transactions')->insert(['ranked_match_id' => $m->id, 'season_id' => $s->id, 'format' => 'Pauper', 'player_profile_id' => $a->playerProfile->id,
            'rating_before' => 1016, 'rating_after' => 1032, 'delta' => 16, 'event_type' => 'match', 'correction_seq' => 0, 'rules_version' => 'x', 'effective_at' => now()]);
    }

    public function test_ledger_rows_are_immutable(): void
    {
        $s = $this->season();
        $a = $this->player('A');
        $b = $this->player('B');
        $this->play($this->match($s, $a, $b), $a, $b, 2, 0);
        $this->expectException(\LogicException::class);
        RatingTransaction::first()->update(['delta' => 99]);
    }

    public function test_dispute_freezes_rating_until_moderation_resolves(): void
    {
        $s = $this->season();
        $a = $this->player('A');
        $b = $this->player('B');
        $admin = $this->player('Admin');
        $admin->forceFill(['is_admin' => true])->save();
        $m = $this->match($s, $a, $b);
        $this->actingAs($a)->post("/ranked/matches/{$m->id}/results", ['score_one' => 2, 'score_two' => 0, 'idempotency_key' => (string) Str::uuid()]);
        $this->actingAs($b)->post("/ranked/matches/{$m->id}/dispute", ['reason' => 'Foi 2 a 1 pra mim'])->assertRedirect();
        $this->assertSame('disputed', $m->fresh()->status);
        $this->assertSame(0, RatingTransaction::count());
        $this->assertTrue(CommunityNotification::where('user_id', $admin->id)->where('type', 'ranked.moderation')->exists());

        $data = ['score_one' => 1, 'score_two' => 2, 'reason' => 'Conferido com os dois'];
        $this->actingAs($a)->post("/ranked/matches/{$m->id}/resolve", $data)->assertForbidden(); // participante nao modera
        $this->actingAs($this->player('Comum'))->post("/ranked/matches/{$m->id}/resolve", $data)->assertForbidden();
        $this->actingAs($admin)->post("/ranked/matches/{$m->id}/resolve", $data)->assertRedirect();
        $this->assertSame([984, 1016], [$this->rating($s, $a), $this->rating($s, $b)]);
        $this->assertSame('resolved', $m->submissions()->latest('id')->first()->status);
    }

    public function test_corrected_resubmission_after_dispute_needs_new_confirmation(): void
    {
        $s = $this->season();
        $a = $this->player('A');
        $b = $this->player('B');
        $m = $this->match($s, $a, $b);
        $svc = app(RankedMatchService::class);
        $svc->submit($m, $a, 2, 0, (string) Str::uuid());
        $svc->dispute($m, $b, 'Errado');
        $svc->submit($m, $b, 1, 2, (string) Str::uuid());
        $this->assertSame('superseded', $m->submissions()->oldest('id')->first()->status);
        $svc->confirm($m, $a);
        $this->assertSame(1016, $this->rating($s, $b));
    }

    public function test_score_rules_and_draws(): void
    {
        $s = $this->season(['Pauper', 'Modern'], 32, ['pair_limit_24h' => 10]);
        $a = $this->player('A');
        $b = $this->player('B');
        $svc = app(RankedMatchService::class);
        foreach ([[2, 2], [3, 0], [1, 0], [0, 0, 'ok-draw']] as $case) {
            $m = $this->match($s, $a, $b);
            if (isset($case[2])) {
                $this->play($m, $a, $b, 1, 1);
                $this->assertSame(1000, $this->rating($s, $a)); // empate entre iguais: 0

                continue;
            }
            try {
                $svc->submit($m, $a, $case[0], $case[1], (string) Str::uuid());
                $this->fail('placar invalido aceito');
            } catch (ValidationException) {
                $this->assertSame('pending', $m->fresh()->status);
            }
        }
        $s->formats()->where('format', 'Modern')->update(['allow_draws' => false]);
        $m = $this->match($s->fresh('formats'), $a, $b, 'Modern');
        $this->expectException(ValidationException::class);
        $svc->submit($m, $a, 1, 1, (string) Str::uuid());
    }

    public function test_pair_limit_three_per_24h_per_format_and_casual_still_allowed(): void
    {
        $s = $this->season();
        $a = $this->player('A');
        $b = $this->player('B');
        $this->friends($a, $b);
        foreach (range(1, 3) as $i) {
            $this->match($s, $a, $b);
        }
        $this->match($s, $a, $b, 'Modern'); // outro formato nao conta
        $this->actingAs($a)->post('/challenges', ['opponent_id' => $b->playerProfile->id, 'format' => 'Pauper', 'best_of' => 3, 'expires_in' => 24, 'ranked' => 1])
            ->assertSessionHasErrors(['challenge' => 'Limite de 3 partidas ranqueadas entre vocês em 24h neste formato. Joguem como casual.']);
        $this->actingAs($a)->post('/challenges', ['opponent_id' => $b->playerProfile->id, 'format' => 'Pauper', 'best_of' => 3, 'expires_in' => 24])->assertSessionHasNoErrors();
        Carbon::setTestNow(now()->addHours(25));
        $this->assertNull(app(RankedMatchService::class)->eligibilityError($s, 'Pauper', $a->playerProfile, $b->playerProfile));
        Carbon::setTestNow();
    }

    public function test_casual_challenges_byes_and_tournaments_never_touch_rating(): void
    {
        $this->season();
        $a = $this->player('A');
        $b = $this->player('B');
        $this->friends($a, $b);
        $svc = app(ChallengeService::class);
        $c = $svc->create($a, $b->playerProfile, 'Pauper', 3, now()->addDay());
        $svc->transition($c, $b, 'accept');
        $svc->report($c, $a, 2, 0);
        $svc->review($c, $b, 'confirm', 1);
        $this->assertSame(0, RatingTransaction::count() + PlayerRating::count() + RankedMatch::count());
    }

    public function test_eligibility_blocks_suspended_same_account_inactive_season_and_unranked_format(): void
    {
        $s = $this->season(['Pauper']);
        $a = $this->player('A');
        $b = $this->player('B');
        $svc = app(RankedMatchService::class);
        $this->assertSame('Commander não é ranqueado nesta temporada.', $svc->eligibilityError($s, 'Commander', $a->playerProfile, $b->playerProfile));
        $this->assertNotNull($svc->eligibilityError($s, 'Pauper', $a->playerProfile, $a->playerProfile));
        $guest = PlayerProfile::create(['user_id' => $a->id, 'nickname' => 'Sem conta']);
        $this->assertSame('Os dois jogadores precisam ter contas próprias.', $svc->eligibilityError($s, 'Pauper', $a->playerProfile, $guest));
        $b->playerProfile->forceFill(['ranked_suspended_until' => now()->addDay()])->save();
        $this->assertSame('Um dos jogadores está suspenso do ranked.', $svc->eligibilityError($s, 'Pauper', $a->playerProfile, $b->playerProfile->fresh()));
        $s->update(['status' => 'closing']);
        $this->assertSame('A temporada não está em andamento.', $svc->eligibilityError($s, 'Pauper', $a->playerProfile, $b->playerProfile));
    }

    public function test_formats_have_independent_ratings(): void
    {
        $s = $this->season();
        $a = $this->player('A');
        $b = $this->player('B');
        $this->play($this->match($s, $a, $b, 'Pauper'), $a, $b, 2, 0);
        $this->play($this->match($s, $a, $b, 'Modern'), $a, $b, 0, 2);
        $this->assertSame([1016, 984], [$this->rating($s, $a, 'Pauper'), $this->rating($s, $a, 'Modern')]);
    }

    public function test_k_factor_is_frozen_per_match(): void
    {
        $s = $this->season(['Pauper'], 32);
        $a = $this->player('A');
        $b = $this->player('B');
        $m = $this->match($s, $a, $b);
        $s->formats()->update(['k_factor' => 16]);
        $this->play($m, $a, $b, 2, 0);
        $this->assertSame(1016, $this->rating($s, $a));
    }

    public function test_invalidation_replays_pool_chronologically_with_correction_entries(): void
    {
        $s = $this->season();
        $a = $this->player('A');
        $b = $this->player('B');
        $c = $this->player('C');
        $admin = $this->player('Admin');
        $admin->forceFill(['is_admin' => true])->save();
        $first = $this->play($this->match($s, $a, $b), $a, $b, 2, 0);   // A 1016, B 984
        Carbon::setTestNow(now()->addMinute());
        $this->play($this->match($s, $b, $c), $b, $c, 2, 1);              // B vence C
        Carbon::setTestNow(now()->addMinute());
        $this->play($this->match($s, $a, $c), $a, $c, 2, 0);              // A vence C
        Carbon::setTestNow();
        $before = RatingTransaction::where('event_type', 'match')->get()->toArray();

        $this->actingAs($b)->post("/ranked/matches/{$first->id}/invalidate", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($admin)->post("/ranked/matches/{$first->id}/invalidate", ['reason' => 'Resultado combinado'])->assertRedirect();

        // Recalculo manual sem a primeira partida: B(1000) vence C(1000) => B 1016, C 984; A(1000) vence C(984) => +15.
        $this->assertSame([1015, 1016, 969], [$this->rating($s, $a), $this->rating($s, $b), $this->rating($s, $c)]);
        $this->assertSame($before, RatingTransaction::where('event_type', 'match')->get()->toArray(), 'lançamentos antigos não mudam');
        // C termina com 969 nos dois cenarios: so A e B recebem correcao.
        $this->assertSame(2, RatingTransaction::where('event_type', 'correction')->count());
        $this->assertFalse(RatingTransaction::where('event_type', 'correction')->where('player_profile_id', $c->playerProfile->id)->exists());
        $this->assertLedgerMatchesRatings($s);
        $this->assertSame(1, PlayerRating::where('player_profile_id', $a->playerProfile->id)->value('played'));
        $this->actingAs($admin)->post("/ranked/matches/{$first->id}/invalidate", ['reason' => 'de novo'])->assertSessionHasErrors('ranked');
    }

    public function test_rating_floor_is_audited(): void
    {
        $s = $this->season(['Pauper'], 64);
        $a = $this->player('A');
        $b = $this->player('B');
        foreach ([$a, $b] as $u) {
            PlayerRating::create(['season_id' => $s->id, 'format' => 'Pauper', 'player_profile_id' => $u->playerProfile->id, 'rating' => 10, 'peak_rating' => 1000, 'provisional_games_remaining' => 0]);
        }
        $this->play($this->match($s, $a, $b), $a, $b, 0, 2); // K 64 entre iguais: -32, piso em 0
        $tx = RatingTransaction::where('player_profile_id', $a->playerProfile->id)->sole();
        $this->assertSame([0, -10, true], [$tx->rating_after, $tx->delta, $tx->floor_applied]);
        $this->assertSame(42, $this->rating($s, $b));
    }

    public function test_season_lifecycle_blocks_scoring_and_snapshots_final_ranking(): void
    {
        $s = $this->season(['Pauper'], 32, ['pair_limit_24h' => 10]);
        $a = $this->player('A');
        $b = $this->player('B');
        foreach (range(1, 3) as $i) {
            $this->play($this->match($s, $a, $b), $a, $b, 2, 0);
        }
        $open = $this->match($s, $a, $b, 'Pauper'); // ainda sem resultado
        $reported = null;
        Carbon::setTestNow(now()->addHours(25));
        $reported = $this->match($s, $a, $b);
        app(RankedMatchService::class)->submit($reported, $a, 2, 0, (string) Str::uuid());

        app(SeasonService::class)->close($s);
        $this->assertSame('closing', $s->fresh()->status); // placar em revisao segura o fechamento
        $this->assertNotNull(app(RankedMatchService::class)->eligibilityError($s->fresh('formats'), 'Pauper', $a->playerProfile, $b->playerProfile));
        app(RankedMatchService::class)->confirm($reported, $b); // ainda pode concluir dentro do prazo
        app(SeasonService::class)->refresh();
        $s->refresh();
        $this->assertSame('finished', $s->status);
        $this->assertSame('cancelled', $open->fresh()->status);
        $snap = $s->snapshots()->where('player_profile_id', $a->playerProfile->id)->sole();
        $this->assertSame([1, $this->rating($s, $a), 4], [$snap->rank, $snap->final_rating, $snap->stats['played']]);
        $this->assertSame(4, $s->snapshots()->where('player_profile_id', $b->playerProfile->id)->sole()->stats['losses']);
        $this->assertTrue(CommunityNotification::where('user_id', $a->id)->where('type', 'ranked.season')->exists());
        $this->expectException(ValidationException::class);
        app(RankedMatchService::class)->invalidate($reported->fresh(), $a, 'tarde demais');
        Carbon::setTestNow();
    }

    public function test_new_season_starts_everyone_at_1000_without_touching_previous(): void
    {
        $old = $this->season(['Pauper']);
        $a = $this->player('A');
        $b = $this->player('B');
        $this->play($this->match($old, $a, $b), $a, $b, 2, 0);
        $old->update(['status' => 'finished']);
        $new = $this->season(['Pauper']);
        $this->play($this->match($new, $a, $b), $a, $b, 0, 2);
        $this->assertSame([1016, 984], [$this->rating($old, $a), $this->rating($new, $a)]);
    }

    public function test_scheduling_rejects_overlapping_season_in_same_scope_and_format(): void
    {
        $this->season(['Pauper']);
        $admin = $this->player('Admin');
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->post('/ranked/admin/seasons', ['name' => 'Dupla', 'starts_at' => now()->addDay()->format('Y-m-d\TH:i'), 'ends_at' => now()->addDays(10)->format('Y-m-d\TH:i'),
            'formats' => ['Pauper'], 'scope' => 'global', 'k_factor' => 32, 'pair_limit_24h' => 3, 'allow_draws' => 1])->assertSessionHasNoErrors();
        $draft = RankedSeason::where('name', 'Dupla')->sole();
        $this->assertSame('draft', $draft->status);
        $this->post("/ranked/admin/seasons/{$draft->slug}/schedule")->assertSessionHasErrors('season');
        $this->actingAs($this->player('X'))->get('/ranked/admin')->assertForbidden();
        $this->post('/ranked/admin/seasons', ['name' => 'Hack', 'starts_at' => now(), 'ends_at' => now()->addDay(), 'formats' => ['Pauper'], 'scope' => 'global', 'k_factor' => 32, 'pair_limit_24h' => 3])->assertForbidden();
    }

    public function test_scheduled_season_activates_and_ends_by_date(): void
    {
        $admin = $this->player('Admin');
        $admin->forceFill(['is_admin' => true])->save();
        $svc = app(SeasonService::class);
        $s = $svc->create($admin, ['name' => 'Futura', 'starts_at' => now()->addHour(), 'ends_at' => now()->addDays(2), 'formats' => ['Pauper', 'Commander'], 'scope' => 'global']);
        $this->assertSame(['Pauper'], $s->formats->pluck('format')->all()); // Commander nao e 1v1
        $svc->schedule($s);
        $this->assertSame('scheduled', $s->fresh()->status);
        Carbon::setTestNow(now()->addHours(2));
        $svc->refresh();
        $this->assertSame('active', $s->fresh()->status);
        Carbon::setTestNow(now()->addDays(3));
        $svc->refresh();
        $this->assertSame('finished', $s->fresh()->status);
        Carbon::setTestNow();
    }

    public function test_leaderboard_minimum_games_privacy_provisional_and_tiebreak(): void
    {
        $s = $this->season(['Pauper']);
        $a = $this->player('Ana');
        $b = $this->player('Bia');
        $c = $this->player('Caio');
        Carbon::setTestNow(now()->subDays(3));
        foreach (range(1, 3) as $i) {
            $this->play($this->match($s, $a, $b), $a, $b, 2, 0);
        }
        Carbon::setTestNow();
        $this->play($this->match($s, $a, $c), $a, $c, 2, 0);
        $b->playerProfile->update(['ranked_public' => false]);
        $page = $this->actingAs($c)->get('/ranked/leaderboard?format=Pauper')->assertOk();
        $page->assertSee('Ana')->assertSee('Jogador oculto')->assertDontSee('Bia')->assertSee('Provisório');
        $this->assertStringNotContainsString('Caio</a>', $page->getContent()); // 1 partida: fora do ranking publico
        $this->actingAs($c)->get('/players/'.$b->playerProfile->id.'/ranked')->assertNotFound();
        $this->actingAs($b)->get('/players/'.$b->playerProfile->id.'/ranked')->assertOk()->assertSee('Só você vê');
        $this->actingAs($c)->get('/players/'.$a->playerProfile->id.'/ranked')->assertOk()->assertSee('Lançamentos');
    }

    public function test_matchmaking_proposes_within_window_and_creates_match_after_both_accept(): void
    {
        $s = $this->season(['Pauper']);
        $a = $this->player('A');
        $b = $this->player('B');
        $c = $this->player('C');
        PlayerRating::create(['season_id' => $s->id, 'format' => 'Pauper', 'player_profile_id' => $c->playerProfile->id, 'rating' => 1250, 'peak_rating' => 1250, 'provisional_games_remaining' => 0]);
        $q = app(MatchmakingService::class);

        $this->actingAs($a)->post('/ranked/queue', ['format' => 'Pauper', 'available' => 1])->assertRedirect('/ranked');
        $this->assertSame('searching', $q->status($a)['state']);
        $this->actingAs($c)->post('/ranked/queue', ['format' => 'Pauper', 'available' => 1]);
        $this->assertSame('searching', $q->status($a)['state'], 'diferença de 250 fora da janela inicial');
        $this->assertArrayNotHasKey('opponent', $q->status($a)); // nada sobre outros jogadores antes da proposta
        $this->actingAs($b)->post('/ranked/queue', ['format' => 'Pauper', 'available' => 1]);
        $status = $q->status($a);
        $this->assertSame(['proposal', 'B', 0], [$status['state'], $status['opponent'], $status['gap']]);
        $this->assertTrue(CommunityNotification::where('user_id', $b->id)->where('type', 'ranked.queue')->exists());

        $proposal = $status['proposal_id'];
        $this->actingAs($c)->post("/ranked/proposals/{$proposal}/accept")->assertForbidden();
        $this->actingAs($a)->post("/ranked/proposals/{$proposal}/accept")->assertRedirect();
        $this->assertSame(0, RankedMatch::count());
        $this->actingAs($b)->post("/ranked/proposals/{$proposal}/accept")->assertRedirect();
        $m = RankedMatch::sole();
        $this->assertSame(['matchmaking', $proposal], [$m->source_type, $m->source_id]);
        $this->assertSame('matched', $q->status($a)['state']);
        $this->actingAs($a)->get('/ranked')->assertSee('Partida criada!')->assertSee('/ranked/matches/'.$m->id);
        $this->actingAs($b)->post("/ranked/proposals/{$proposal}/accept")->assertRedirect(); // repetido: sem segunda partida
        $this->assertSame(1, RankedMatch::count());
    }

    public function test_progressive_window_decline_and_expiry(): void
    {
        $s = $this->season(['Pauper']);
        $a = $this->player('A');
        $c = $this->player('C');
        $d = $this->player('D');
        PlayerRating::create(['season_id' => $s->id, 'format' => 'Pauper', 'player_profile_id' => $c->playerProfile->id, 'rating' => 1250, 'peak_rating' => 1250, 'provisional_games_remaining' => 0]);
        $q = app(MatchmakingService::class);
        $q->join($a, 'Pauper', null, null);
        $q->join($c, 'Pauper', null, null);
        Carbon::setTestNow(now()->addMinutes(3));
        $q->heartbeat($c); // 3 min de espera: janela 200, ainda nao
        $this->assertSame('searching', $q->status($a)['state']);
        Carbon::setTestNow(now()->addMinutes(3)); // 6 min: janela 300
        $q->heartbeat($a);
        $st = $q->status($a);
        $this->assertSame(['proposal', 250], [$st['state'], $st['gap']]);
        $this->assertFalse($st['wide_gap']);

        $this->actingAs($c)->post("/ranked/proposals/{$st['proposal_id']}/decline")->assertRedirect();
        $this->assertSame('idle', $q->status($c)['state']);       // quem recusou sai
        $this->assertSame('searching', $q->status($a)['state']);  // o outro continua com a posicao
        $q->join($d, 'Pauper', null, null);
        $st = $q->status($a);
        $this->assertSame('proposal', $st['state']);
        $q->accept(MatchmakingProposal::find($st['proposal_id']), $a);
        Carbon::setTestNow(now()->addMinutes(4)); // D nao respondeu em 3 min
        $q->heartbeat($a);
        $this->assertSame('searching', $q->status($a)['state']);
        $this->assertSame('expired', MatchmakingEntry::where('player_profile_id', $d->playerProfile->id)->value('status'));
        Carbon::setTestNow(now()->addMinutes(11)); // sem presenca por mais de 10 min
        $q->runMatching();
        $this->assertSame('expired', $q->status($a)['state']);
        Carbon::setTestNow();
    }

    public function test_queue_respects_club_scope_suspension_and_single_entry(): void
    {
        $this->season(['Pauper']);
        $a = $this->player('A');
        $b = $this->player('B');
        $club = app(ClubService::class)->create($a, ['name' => 'Loja', 'privacy' => 'private']);
        $this->actingAs($b)->post('/ranked/queue', ['format' => 'Pauper', 'club_id' => $club->id, 'available' => 1])->assertSessionHasErrors('ranked');
        $this->actingAs($a)->post('/ranked/queue', ['format' => 'Pauper', 'club_id' => $club->id, 'available' => 1])->assertSessionHasNoErrors();
        $this->actingAs($a)->post('/ranked/queue', ['format' => 'Pauper', 'available' => 1])->assertSessionHasErrors('ranked');
        $this->actingAs($b)->post('/ranked/queue', ['format' => 'Pauper', 'available' => 1])->assertSessionHasNoErrors();
        $this->assertSame('searching', app(MatchmakingService::class)->status($a)['state'], 'clube e comunidade não se misturam');
        $s = $this->player('Suspenso');
        $s->playerProfile->forceFill(['ranked_suspended_until' => now()->addDay()])->save();
        $this->actingAs($s)->post('/ranked/queue', ['format' => 'Pauper', 'available' => 1])->assertSessionHasErrors('ranked');
        $this->actingAs($b)->post('/ranked/queue', ['format' => 'Commander', 'available' => 1])->assertSessionHasErrors('format');
    }

    public function test_pages_render_for_players_and_admin(): void
    {
        $s = $this->season();
        $a = $this->player('Ana');
        $b = $this->player('Bruno');
        $admin = $this->player('Admin');
        $admin->forceFill(['is_admin' => true])->save();
        $m = $this->match($s, $a, $b);
        $this->actingAs($a)->get('/ranked')->assertOk()->assertSee('Procurar partida')->assertSee('Partidas em aberto')->assertSee('1000 ELO (Prata II)');
        $this->actingAs($a)->get('/dashboard')->assertOk()->assertSee('Enviar resultado ranqueado');
        $this->actingAs($a)->get('/ranked/regulamento')->assertOk()->assertSee('+24');
        $this->actingAs($a)->get("/ranked/seasons/{$s->slug}")->assertOk()->assertSee('Top 10 · Pauper');
        $this->actingAs($a)->get("/ranked/matches/{$m->id}")->assertOk()->assertSee('Informar resultado');
        $this->actingAs($admin)->get('/ranked/admin')->assertOk()->assertSee('Nova temporada');
        $this->actingAs($admin)->get("/ranked/matches/{$m->id}")->assertOk();
        $this->actingAs($a)->get('/tournaments')->assertSee(url('/ranked'), false);
    }
}
