<?php

namespace Tests\Feature;

use App\Models\RankedSeason;
use App\Models\User;
use App\Services\ChallengeService;
use App\Services\Ranked\RankedMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Tela de Desafios: em andamento + histórico, e o formulário numa tela própria (Desafiar amigo). */
class ChallengesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_shows_what_needs_you_first_and_the_history_with_results(): void
    {
        [$ana, $bia, $caio] = [$this->user('Ana'), $this->user('Bia'), $this->user('Caio')];
        $this->befriend($ana, $bia);
        $this->befriend($ana, $caio);
        $service = app(ChallengeService::class);

        // Histórico: Ana venceu a Bia por 2–1 (casual confirmado).
        $done = $service->create($ana, $bia->playerProfile, 'Pauper', 3, now()->addDay());
        $service->transition($done, $bia, 'accept');
        $match = $service->report($done, $bia, 2, 1);
        $service->review($done->fresh(), $ana, 'confirm', $match->revision);
        // Em andamento (o que precisa da Ana vem antes; dentro de cada grupo, o mais recente primeiro): o Caio mandou um
        // convite, depois a Bia enviou um placar que a Ana precisa confirmar. Cada um num minuto, para a ordem não
        // depender de empate na data de criação.
        $this->travel(1)->minutes();
        $service->create($caio, $ana->playerProfile, 'Pauper', 1, now()->addDay());
        $this->travel(1)->minutes();
        $toConfirm = $service->create($bia->fresh(), $ana->playerProfile, 'Modern', 5, now()->addDay());
        $service->transition($toConfirm, $ana, 'accept');
        $service->report($toConfirm->fresh(), $bia, 3, 0);
        // E um convite que a própria Ana mandou e ainda não foi respondido.
        $this->travel(1)->minutes();
        $service->create($ana, $caio->playerProfile, 'Pauper', 3, now()->addDay());

        $this->actingAs($ana)->get(route('challenges.index'))->assertOk()
            ->assertSee(route('challenges.create'), false)->assertSee('Desafiar amigo')
            ->assertDontSee('name="opponent_id"', false) // o formulário não fica mais na lista
            ->assertSeeInOrder(['Em andamento', 'Bia desafiou você', 'Confirmar placar', 'Caio desafiou você', 'Convite recebido', 'Você desafiou Caio', 'Aguardando resposta', 'Histórico'], false)
            ->assertSeeInOrder(['Histórico', 'Você desafiou Bia', 'Vitória 2–1'], false);
        $this->actingAs($bia)->get(route('challenges.index'))->assertSee('Aguardando confirmação')->assertSee('Derrota 1–2');
    }

    public function test_ranked_challenges_show_the_ranked_result(): void
    {
        [$ana, $bia] = [$this->user('Ana'), $this->user('Bia')];
        $this->befriend($ana, $bia);
        $season = RankedSeason::create(['name' => 'T1', 'slug' => 't1-'.Str::random(4), 'status' => 'active', 'scope' => 'global', 'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(), 'rules_version' => 'elo-v2']);
        $season->formats()->create(['format' => 'Pauper', 'k_factor' => 32, 'starting_rating' => 600, 'allow_draws' => true]);
        $challenge = app(ChallengeService::class)->create($ana, $bia->playerProfile, 'Pauper', 3, now()->addDay(), true);
        app(ChallengeService::class)->transition($challenge, $bia, 'accept');
        $ranked = \App\Models\RankedMatch::sole();
        app(RankedMatchService::class)->submit($ranked, $bia, 0, 2, (string) Str::uuid());

        $this->actingAs($ana)->get(route('challenges.index'))->assertSee('Ranqueada')->assertSee('Confirmar placar');
        app(RankedMatchService::class)->confirm($ranked->fresh(), $ana);
        $this->actingAs($ana)->get(route('challenges.index'))->assertSeeInOrder(['Histórico', 'Você desafiou Bia', 'Derrota 0–2'], false);
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
