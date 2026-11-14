<?php

namespace Tests\Feature;

use App\Models\RankedSeason;
use App\Models\User;
use App\Services\ChallengeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Minhas partidas: torneio, desafio e ranked num lugar só, do ponto de vista de quem vê, o que precisa de você primeiro. */
class MyMatchesPageTest extends TestCase
{
    use RefreshDatabase;

    private function cup(string $name, string $slug): array
    {
        $owner = User::factory()->create();
        $ana = User::factory()->create(); $bia = User::factory()->create();
        $t = $owner->tournaments()->create(['name' => $name, 'format' => 'Pauper', 'tournament_date' => '2026-10-20', 'status' => 'league',
            'public_token' => Str::random(40), 'public_slug' => $slug, 'invite_token' => Str::random(40)]);
        $pAna = $t->players()->create(['name' => 'Ana', 'player_profile_id' => $ana->ensurePlayerProfile()->id]);
        $pBia = $t->players()->create(['name' => 'Bia', 'player_profile_id' => $bia->ensurePlayerProfile()->id]);

        return [$t, $ana, $bia, $pAna, $pBia];
    }

    private function befriend(User $a, User $b): void
    {
        $a->friendProfiles()->attach($b->ensurePlayerProfile(), ['status' => 'accepted']);
        $b->friendProfiles()->attach($a->ensurePlayerProfile(), ['status' => 'accepted']);
    }

    public function test_tournament_history_shows_your_side_first_with_result_and_campaign(): void
    {
        [$t, $ana, $bia, $pAna, $pBia] = $this->cup('Copa Pauper', 'copa-pauper');
        // Bia e a jogadora 1 nas duas: para a Ana o placar tem que vir invertido (seu–dele).
        $t->matches()->create(['round' => 1, 'stage' => 'league', 'status' => 'finished', 'player_one_id' => $pBia->id, 'player_two_id' => $pAna->id, 'player_one_games' => 2, 'player_two_games' => 1]);
        $t->matches()->create(['round' => 2, 'stage' => 'league', 'status' => 'finished', 'player_one_id' => $pBia->id, 'player_two_id' => $pAna->id, 'player_one_games' => 0, 'player_two_games' => 2]);
        $t->matches()->create(['round' => 3, 'stage' => 'league', 'status' => 'bye', 'player_one_id' => $pAna->id]);

        // Linha do tempo (Todas): com o nome do torneio em cada linha.
        $this->actingAs($ana)->get(route('matches.mine'))->assertOk()
            ->assertSeeText('Você × Bia')->assertSeeText('2 partidas encerradas · campanha 1–1–0')
            ->assertSeeInOrder(['Histórico', 'Copa Pauper · Rodada 3', 'Folga', 'Rodada 2', 'Vitória 2–0', 'Rodada 1', 'Derrota 1–2']);

        // Filtro Torneios: agrupado por torneio, com a campanha e o link do placar.
        $this->actingAs($ana)->get(route('matches.mine', ['tipo' => 'torneios']))->assertOk()
            ->assertSeeInOrder(['Copa Pauper', 'campanha 1–1–0', 'Rodada 3', 'Rodada 2', 'Rodada 1'])
            ->assertSee('href="'.route('arena.public', 'copa-pauper').'"', false);

        $this->actingAs($bia)->get(route('matches.mine'))->assertSeeText('Você × Ana')->assertSeeInOrder(['Derrota 0–2', 'Vitória 2–1']);
    }

    public function test_rounds_that_have_not_started_are_listed_apart_and_cannot_get_a_score(): void
    {
        [$t, $ana, $bia, $pAna, $pBia] = $this->cup('Liga Longa', 'liga-longa');
        // Todos contra todos: as rodadas 2 e 3 já existem, mas a 1 ainda não terminou.
        $now = $t->matches()->create(['round' => 1, 'stage' => 'league', 'status' => 'pending', 'player_one_id' => $pAna->id, 'player_two_id' => $pBia->id]);
        $next = $t->matches()->create(['round' => 2, 'stage' => 'league', 'status' => 'pending', 'player_one_id' => $pBia->id, 'player_two_id' => $pAna->id]);
        $t->matches()->create(['round' => 3, 'stage' => 'league', 'status' => 'pending', 'player_one_id' => $pAna->id, 'player_two_id' => $pBia->id]);

        $html = $this->actingAs($ana)->get(route('matches.mine'))->assertOk()
            ->assertSeeInOrder(['Em andamento', '1 partida', 'Liga Longa · Rodada 1', 'Envie o resultado', 'Próximas partidas', '2 partidas', 'Rodada 2', 'começa depois da rodada 1', 'A jogar', 'Rodada 3', 'A jogar'])
            ->getContent();
        $this->assertSame(1, substr_count($html, 'name="idempotency_key"'), 'só a rodada atual tem campo de placar');
        $this->assertStringNotContainsString('Histórico', $html);

        // O servidor também recusa placar de rodada que ainda não começou; o painel só aponta a rodada atual.
        $this->postJson(route('matches.submissions.store', [$t, $next]), ['player_one_games' => 2, 'player_two_games' => 0, 'idempotency_key' => (string) Str::uuid()])
            ->assertStatus(409);
        $this->get('/dashboard')->assertOk()->assertSee('Rodada 1 · Pauper')->assertDontSee('Rodada 2 · Pauper');

        // Rodada 1 encerrada: a 2 vira a atual e já aceita placar.
        $now->update(['status' => 'finished', 'player_one_games' => 2, 'player_two_games' => 0]);
        $this->get(route('matches.mine'))->assertSeeInOrder(['Em andamento', 'Rodada 2', 'Envie o resultado', 'Próximas partidas', 'Rodada 3', 'Histórico', 'Rodada 1']);
        $this->postJson(route('matches.submissions.store', [$t, $next]), ['player_one_games' => 0, 'player_two_games' => 2, 'idempotency_key' => (string) Str::uuid()])
            ->assertCreated();
    }

    public function test_what_needs_you_comes_first_with_inputs_on_your_side(): void
    {
        [$t, $ana, $bia, $pAna, $pBia] = $this->cup('Copa Ao Vivo', 'copa-ao-vivo');
        $t->matches()->create(['round' => 1, 'stage' => 'league', 'status' => 'pending', 'player_one_id' => $pBia->id, 'player_two_id' => $pAna->id]);
        $toConfirm = $t->matches()->create(['round' => 1, 'stage' => 'league', 'status' => 'pending', 'player_one_id' => $pAna->id, 'player_two_id' => $pBia->id]);
        $this->actingAs($bia)->postJson(route('matches.submissions.store', [$t, $toConfirm]), ['player_one_games' => 2, 'player_two_games' => 1, 'idempotency_key' => (string) Str::uuid()])->assertCreated();

        $page = $this->actingAs($ana)->get(route('matches.mine'))->assertOk()
            ->assertSeeInOrder(['Em andamento', 'Envie o resultado', 'Confirme o placar'])
            ->assertSeeText('Bia enviou o placar 2–1: vitória sua.')
            ->assertSee('Confirmar placar')->assertSee('Contestar')->assertSee('Abrir contador de vida');
        // Ana e a jogadora 2: o primeiro campo ("Você") grava player_two_games.
        $html = $page->getContent();
        $this->assertLessThan(strpos($html, 'name="player_one_games"'), strpos($html, 'name="player_two_games"'));

        $this->actingAs($bia)->get(route('matches.mine'))->assertSee('Aguardando confirmação')->assertSeeText('Placar enviado 1–2: derrota sua.')
            ->assertDontSee('Confirmar placar');
    }

    public function test_challenges_appear_with_the_same_state_as_the_challenges_page(): void
    {
        $ana = User::factory()->create(); $bia = User::factory()->create();
        $this->befriend($ana, $bia);
        $service = app(ChallengeService::class);
        $done = $service->create($ana, $bia->ensurePlayerProfile(), 'Pauper', 3, now()->addDay());
        $service->transition($done, $bia, 'accept');
        $service->report($done, $ana, 2, 1);
        $service->review($done, $bia, 'confirm', 1);
        $invite = $service->create($bia, $ana->ensurePlayerProfile(), 'Modern', 1, now()->addDay());

        $this->actingAs($ana)->get(route('matches.mine'))->assertOk()
            ->assertSeeInOrder(['Em andamento', 'desafiou você', 'Convite recebido', 'Histórico', 'Desafio casual', 'Pauper · MD3', 'Vitória 2–1'])
            // Desafio em andamento: o cartão com as ações fica aqui mesmo (aceitar ou recusar o convite).
            ->assertSee('id="desafio-'.$invite->id.'"', false)->assertSee(route('challenges.transition', $invite), false)->assertSee('Aceitar')
            // Desafio encerrado abre o resumo da partida na janela (MatchDetailTest).
            ->assertSee('href="'.route('matches.detail', ['challenge', $done->id]).'"', false)
            ->assertSeeText('1 partida encerrada · campanha 1–0–0');

        $this->actingAs($bia)->get(route('matches.mine', ['tipo' => 'desafios']))->assertOk()
            ->assertSee('Derrota 1–2')->assertSee('Aguardando resposta');
        // Filtro Torneios nao mistura desafio.
        $this->actingAs($ana)->get(route('matches.mine', ['tipo' => 'torneios']))->assertDontSee('Vitória 2–1');
    }

    public function test_ranked_filter_shows_ranked_challenges_and_ranked_tournaments_only(): void
    {
        $ana = User::factory()->create(); $bia = User::factory()->create();
        $this->befriend($ana, $bia);
        $season = RankedSeason::create(['name' => 'T1', 'slug' => 't1-'.Str::random(4), 'status' => 'active', 'scope' => 'global',
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(), 'rules_version' => 'elo-v2']);
        $season->formats()->create(['format' => 'Pauper', 'k_factor' => 32, 'starting_rating' => 600, 'allow_draws' => true]);
        $service = app(ChallengeService::class);
        $ranked = $service->create($ana, $bia->ensurePlayerProfile(), 'Pauper', 3, now()->addDay(), true);
        $service->transition($ranked, $bia, 'accept');
        $casual = $service->create($ana, $bia->ensurePlayerProfile(), 'Modern', 1, now()->addDay());

        $this->actingAs($ana)->get(route('matches.mine', ['tipo' => 'ranked']))->assertOk()
            ->assertSee('Desafio ranqueado')->assertSee('Jogar e enviar placar')
            ->assertSee('id="desafio-'.$ranked->id.'"', false)
            ->assertDontSee('id="desafio-'.$casual->id.'"', false);
        // Em "Todas" o desafio ranqueado aparece uma vez so (nao duplica com o Ranked).
        $html = $this->actingAs($ana)->get(route('matches.mine'))->getContent();
        $this->assertSame(1, substr_count($html, 'id="desafio-'.$ranked->id.'"'));
    }

    public function test_unknown_filter_falls_back_to_all_and_empty_state_points_to_challenges(): void
    {
        $this->actingAs(User::factory()->create())->get(route('matches.mine', ['tipo' => 'xyz']))->assertOk()
            ->assertSee('Nenhuma partida vinculada à sua conta.')->assertSee('aria-current="page"', false)
            ->assertSee(route('challenges.create'), false);
    }
}
