<?php

namespace Tests\Feature;

use App\Models\RankedSeason;
use App\Models\User;
use App\Services\ChallengeService;
use App\Services\Ranked\RankedMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Tela de Desafios: em andamento (com as ações no cartão) e rivalidades; o histórico fica em Minhas partidas. */
class ChallengesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_shows_what_needs_you_first_with_the_actions_and_then_the_rivalries(): void
    {
        [$ana, $bia, $caio] = [$this->user('Ana'), $this->user('Bia'), $this->user('Caio')];
        $this->befriend($ana, $bia);
        $this->befriend($ana, $caio);
        $service = app(ChallengeService::class);

        // Série encerrada: Ana venceu a Bia por 2–1 (casual confirmado). Não aparece em Desafios, só na rivalidade.
        $done = $service->create($ana, $bia->playerProfile, 'Pauper', 3, now()->addDay());
        $service->transition($done, $bia, 'accept');
        $match = $service->report($done, $bia, 2, 1);
        $service->review($done->fresh(), $ana, 'confirm', $match->revision);
        // Em andamento (o que precisa da Ana vem antes; dentro de cada grupo, o mais recente primeiro): o Caio mandou um
        // convite, depois a Bia enviou um placar que a Ana precisa confirmar. Cada um num minuto, para a ordem não
        // depender de empate na data de criação.
        $this->travel(1)->minutes();
        $invite = $service->create($caio, $ana->playerProfile, 'Pauper', 1, now()->addDay());
        $this->travel(1)->minutes();
        $toConfirm = $service->create($bia->fresh(), $ana->playerProfile, 'Modern', 5, now()->addDay());
        $service->transition($toConfirm, $ana, 'accept');
        $service->report($toConfirm->fresh(), $bia, 3, 0);
        // E um convite que a própria Ana mandou e ainda não foi respondido.
        $this->travel(1)->minutes();
        $mine = $service->create($ana, $caio->playerProfile, 'Pauper', 3, now()->addDay());

        $page = $this->actingAs($ana)->get(route('challenges.index'))->assertOk()
            ->assertSee(route('challenges.create'), false)->assertSee('Desafiar amigo')
            ->assertDontSee('name="opponent_id"', false) // o formulário não fica na lista
            ->assertSeeInOrder(['Desafiar amigo', 'Em andamento', 'Bia', 'Confirmar placar', 'Caio desafiou você', 'Convite recebido',
                'Você desafiou Caio', 'Aguardando resposta', 'Rivalidades', 'Bia', '1 série'], false)
            ->assertDontSee('Histórico')->assertDontSee('id="desafio-'.$done->id.'"', false)
            ->assertSee(route('matches.mine', ['tipo' => 'desafios']), false);
        // Cada desafio é uma linha que abre ao clicar (<details>, fechada por padrão) com as ações dentro: confirmar ou
        // contestar o placar, aceitar ou recusar o convite, cancelar o próprio convite.
        $this->assertMatchesRegularExpression('/<details class="mm-open[^"]*" id="desafio-'.$toConfirm->id.'">\s*<summary class="ui-row mm-row/', $page->getContent());
        $page->assertSee(route('challenges.review', $toConfirm), false)->assertSee('Contestar')
            ->assertSee(route('challenges.transition', $invite), false)->assertSee('Aceitar')->assertSee('Recusar')
            ->assertSee(route('challenges.transition', $mine), false)->assertSee('Cancelar convite');

        $this->actingAs($bia)->get(route('challenges.index'))->assertSee('Aguardando confirmação')->assertSee('Placar enviado')
            ->assertDontSee(route('challenges.review', $toConfirm), false);
    }

    public function test_accepted_challenge_takes_the_score_right_on_the_card(): void
    {
        [$ana, $bia] = [$this->user('Ana'), $this->user('Bia')];
        $this->befriend($ana, $bia);
        $challenge = app(ChallengeService::class)->create($ana, $bia->playerProfile, 'Pauper', 3, now()->addDay());
        app(ChallengeService::class)->transition($challenge, $bia, 'accept');

        // Bia foi desafiada: o campo "Você" dela é o do adversário (opponent_games), e o contador de vida está à mão.
        $html = $this->actingAs($bia)->get(route('challenges.index'))->assertOk()
            ->assertSee('Jogar e enviar placar')->assertSee(route('challenges.report', $challenge), false)
            ->assertSee(route('life.challenge', $challenge), false)
            // Placar com as fotos e o mini contador (− número +) de cada um, como no resumo da partida.
            ->assertSee('class="mm-board"', false)->assertSee('data-score-step', false)->assertSee('js/score-step.js', false)->getContent();
        $this->assertLessThan(strpos($html, 'name="challenger_games"'), strpos($html, 'name="opponent_games"'));

        $this->from(route('challenges.index'))->post(route('challenges.report', $challenge), ['opponent_games' => 2, 'challenger_games' => 0])
            ->assertRedirect(route('challenges.index'));
        $match = $challenge->casualMatch()->sole();
        $this->assertSame([0, 2, 'reported'], [$match->challenger_games, $match->opponent_games, $match->status]);
    }

    public function test_ranked_challenges_are_played_on_the_card_and_count_in_the_rivalry(): void
    {
        [$ana, $bia] = [$this->user('Ana'), $this->user('Bia')];
        $this->befriend($ana, $bia);
        $season = RankedSeason::create(['name' => 'T1', 'slug' => 't1-'.Str::random(4), 'status' => 'active', 'scope' => 'global', 'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(), 'rules_version' => 'elo-v2']);
        $season->formats()->create(['format' => 'Pauper', 'k_factor' => 32, 'starting_rating' => 600, 'allow_draws' => true]);
        $challenge = app(ChallengeService::class)->create($ana, $bia->playerProfile, 'Pauper', 3, now()->addDay(), true);
        app(ChallengeService::class)->transition($challenge, $bia, 'accept');
        $ranked = \App\Models\RankedMatch::sole();

        // Sem placar: o cartão envia para a partida ranqueada (score_one é da Ana, que criou).
        $this->actingAs($ana)->get(route('challenges.index'))->assertSee('Desafio ranqueado')
            ->assertSee(route('ranked.matches.results', $ranked), false)->assertSee('name="score_one"', false);
        app(RankedMatchService::class)->submit($ranked, $bia, 0, 2, (string) Str::uuid());

        $this->actingAs($ana)->get(route('challenges.index'))->assertSee('Confirmar placar')->assertSee(route('ranked.matches.confirm', $ranked), false)
            ->assertSee('atualiza o ELO');
        $this->from(route('challenges.index'))->post(route('ranked.matches.confirm', $ranked))->assertRedirect(route('challenges.index'));
        $this->actingAs($ana)->get(route('challenges.index'))->assertDontSee('id="desafio-'.$challenge->id.'"', false)
            ->assertSeeInOrder(['Rivalidades', 'Bia', '1 série · 1 ranqueada'], false);
    }

    public function test_new_challenge_page_preselects_the_friend_and_old_links_redirect_to_it(): void
    {
        [$ana, $bia, $caio] = [$this->user('Ana'), $this->user('Bia'), $this->user('Caio')];
        $this->befriend($ana, $bia);
        $this->befriend($ana, $caio);

        $this->actingAs($ana)->get(route('challenges.create', ['opponent' => $caio->playerProfile->id]))->assertOk()
            ->assertSee('Novo desafio')->assertSee('Enviar desafio')
            ->assertSee('value="'.$caio->playerProfile->id.'" checked', false)
            ->assertDontSee('value="'.$bia->playerProfile->id.'" checked', false);
        // Links antigos (?opponent=, ?ranked=) para a lista abrem o formulário.
        $this->get(route('challenges.index', ['opponent' => $bia->playerProfile->id]))->assertRedirect(route('challenges.create', ['opponent' => $bia->playerProfile->id]));
        $this->get(route('challenges.index', ['ranked' => 1]))->assertRedirect(route('challenges.create', ['ranked' => 1]));

        $this->post(route('challenges.store'), ['opponent_id' => $bia->playerProfile->id, 'format' => 'Pauper', 'best_of' => 5, 'expires_in' => 48])->assertRedirect();
        $this->assertDatabaseHas('challenges', ['opponent_id' => $bia->playerProfile->id, 'best_of' => 5]);

        // Sem amigos: o formulário dá lugar a um convite para encontrar amigos.
        $this->actingAs($this->user('Zé'))->get(route('challenges.create'))->assertOk()
            ->assertSee('Encontrar amigos')->assertDontSee('Enviar desafio');
    }

    private function user(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->ensurePlayerProfile()->update(['nickname' => $name]);

        return $user->fresh();
    }

    private function befriend(User $a, User $b): void
    {
        $a->friendProfiles()->attach($b->playerProfile, ['status' => 'accepted']);
        $b->friendProfiles()->attach($a->playerProfile, ['status' => 'accepted']);
    }
}
