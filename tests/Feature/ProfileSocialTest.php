<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\GameMatch;
use App\Models\RankedMatch;
use App\Models\RankedSeason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileSocialTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $nickname): User
    {
        $user = User::factory()->create(['name' => $nickname]);
        $user->ensurePlayerProfile()->update(['nickname' => $nickname]);

        return $user->fresh();
    }

    private function befriend(User $a, User $b): void
    {
        $a->friendProfiles()->attach($b->playerProfile, ['status' => 'accepted']);
        $b->friendProfiles()->attach($a->playerProfile, ['status' => 'accepted']);
    }

    public function test_head_to_head_sums_tournaments_challenges_and_ranked_once(): void
    {
        $ana = $this->account('Ana');
        $bia = $this->account('Bia');
        [$pa, $pb] = [$ana->playerProfile, $bia->playerProfile];

        // torneio: Ana vence 2x1 (Bia é jogadora 1)
        $t = $ana->tournaments()->create(['name' => 'Copa', 'format' => 'Pauper', 'tournament_date' => '2026-10-01', 'status' => 'league',
            'public_token' => Str::random(40), 'public_slug' => 'copa-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40)]);
        $ta = $t->players()->create(['name' => 'Ana', 'player_profile_id' => $pa->id]);
        $tb = $t->players()->create(['name' => 'Bia', 'player_profile_id' => $pb->id]);
        $tournamentMatch = $t->matches()->create(['round' => 1, 'stage' => GameMatch::STAGE_LEAGUE, 'status' => GameMatch::STATUS_FINISHED,
            'player_one_id' => $tb->id, 'player_two_id' => $ta->id, 'player_one_games' => 1, 'player_two_games' => 2, 'played_at' => '2026-10-01 20:00:00']);

        // desafio casual confirmado: Bia vence 2x0
        $challenge = Challenge::create(['challenger_id' => $pa->id, 'opponent_id' => $pb->id, 'format' => 'Pauper', 'best_of' => 3, 'status' => 'completed', 'expires_at' => now()->addDay()]);
        $challenge->casualMatch()->create(['status' => 'confirmed', 'challenger_games' => 0, 'opponent_games' => 2, 'confirmed_at' => '2026-10-05 20:00:00']);

        // Ranked de desafio confirmado: Ana vence 2x0 (a mais recente)
        $season = RankedSeason::create(['name' => 'T1', 'slug' => 't1', 'status' => 'active', 'scope' => 'global', 'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(), 'rules_version' => 'elo-v2']);
        $ranked = ['season_id' => $season->id, 'format' => 'Pauper', 'status' => 'confirmed', 'k_factor' => 32, 'rules_version' => 'elo-v2'];
        RankedMatch::create($ranked + ['player_one_profile_id' => $pb->id, 'player_two_profile_id' => $pa->id, 'source_type' => 'challenge', 'source_id' => 99,
            'score_one' => 0, 'score_two' => 2, 'confirmed_at' => '2026-10-10 20:00:00']);
        // Ranked do torneio ranqueado: já contado pela partida do torneio
        RankedMatch::create($ranked + ['player_one_profile_id' => $pb->id, 'player_two_profile_id' => $pa->id, 'source_type' => 'tournament_match', 'source_id' => $tournamentMatch->id,
            'score_one' => 1, 'score_two' => 2, 'confirmed_at' => '2026-10-01 20:00:00']);

        $this->actingAs($ana)->get(route('profiles.show', $pb))->assertOk()
            ->assertSee('Confronto direto')->assertSee('Você × Bia')
            ->assertSeeInOrder(['<b>2</b><span>Vitórias</span>', '<b>1</b><span>Derrota</span>'], false)
            ->assertSee('3 partidas · última: Ranked Pauper, você venceu por 2×0 em 10/10/2026');

        // do lado da Bia, o placar se inverte
        $this->actingAs($bia)->get(route('profiles.show', $pa))
            ->assertSeeInOrder(['<b>1</b><span>Vitória</span>', '<b>2</b><span>Derrotas</span>'], false)
            ->assertSee('você perdeu por 0×2');

        // no próprio perfil não aparece
        $this->actingAs($ana)->get(route('profiles.index'))->assertDontSee('Confronto direto');
    }

    public function test_head_to_head_invites_friends_who_never_played_and_hides_for_strangers(): void
    {
        $ana = $this->account('Ana');
        $bia = $this->account('Bia');
        $caio = $this->account('Caio');
        $this->befriend($ana, $bia);

        $this->actingAs($ana)->get(route('profiles.show', $bia->playerProfile))
            ->assertSee('Vocês ainda não se enfrentaram')
            ->assertSee(route('challenges.index', ['opponent' => $bia->playerProfile->id]), false);
        $this->actingAs($ana)->get(route('profiles.show', $caio->playerProfile))->assertOk()->assertDontSee('Confronto direto');
    }

    public function test_back_link_returns_to_where_the_visitor_came_from(): void
    {
        $ana = $this->account('Ana');
        $bia = $this->account('Bia');
        $profileUrl = route('profiles.show', $bia->playerProfile);

        $this->actingAs($ana)->from(route('tournaments.index'))->get($profileUrl)
            ->assertSee('<a class="back-link" href="'.route('tournaments.index').'">← Voltar</a>', false);
        // sem página anterior (ou vindo de fora do site): volta para Amigos
        $this->actingAs($ana)->from('https://exemplo.com/x')->get($profileUrl)
            ->assertSee('<a class="back-link" href="'.route('friends.index').'">← Amigos</a>', false);
        // o próprio perfil continua voltando para Torneios
        $this->actingAs($bia)->from(route('friends.index'))->get(route('profiles.show', $bia->playerProfile))
            ->assertSee('← Torneios', false);
    }

    public function test_profile_has_copy_link_button_and_clickable_avatars(): void
    {
        $ana = $this->account('Ana');
        $bia = $this->account('Bia');
        $this->befriend($ana, $bia);
        $biaUrl = route('profiles.show', $bia->playerProfile);

        $this->actingAs($ana)->get($biaUrl)->assertOk()
            ->assertSee('data-copy-link="'.$biaUrl.'"', false)
            ->assertSee('aria-label="Copiar link do perfil"', false)
            ->assertSee('js/copy-link.js', false);

        // na lista de amigos, o avatar leva ao mesmo perfil, sem entrar no Tab
        $this->actingAs($ana)->get(route('friends.index'))
            ->assertSee('<a class="player-link avatar-link" tabindex="-1" aria-hidden="true" href="'.$biaUrl.'">', false);
    }
}
