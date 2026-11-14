<?php

namespace Tests\Feature;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_scores_to_confirm_are_grouped_under_confirm_all_instead_of_repeated(): void
    {
        $ana = User::factory()->create(['name' => 'Ana']);
        $ana->ensurePlayerProfile()->update(['nickname' => 'Ana']);
        $service = app(\App\Services\ChallengeService::class);
        foreach (['Bia', 'Caio'] as $name) {
            $friend = User::factory()->create(['name' => $name]);
            $friend->ensurePlayerProfile()->update(['nickname' => $name]);
            $ana->friendProfiles()->attach($friend->playerProfile, ['status' => 'accepted']);
            $friend->friendProfiles()->attach($ana->fresh()->playerProfile, ['status' => 'accepted']);
            $challenge = $service->create($friend->fresh(), $ana->fresh()->playerProfile, 'Pauper', 3, now()->addDay());
            $service->transition($challenge, $ana, 'accept');
            $service->report($challenge->fresh(), $friend, 2, 1);
        }

        // Dois placares: o "Confirmar todos" resume e as linhas ficam recolhidas, sem repetir embaixo.
        $html = $this->actingAs($ana)->get('/dashboard')->assertOk()
            ->assertSeeInOrder(['Precisa de você', 'placares esperando a sua confirmação', 'Confirmar todos (2)', 'Conferir um por um', 'Confirmar placar do desafio', 'Confirmar placar do desafio', '</details>'], false)
            ->getContent();
        $this->assertSame(2, substr_count($html, 'Confirmar placar do desafio'), 'cada placar aparece uma vez só');
        $this->assertStringNotContainsString('rank-line', $html);
    }

    public function test_empty_dashboard_shows_compact_empty_states_and_no_fake_numbers(): void
    {
        $user = User::factory()->create(['name' => 'Novato']);
        $user->ensurePlayerProfile();

        $page = $this->actingAs($user)->get('/dashboard')->assertOk();
        $page->assertSee('Meu painel')->assertSee('Desafiar amigo')->assertSee('Criar torneio')
            ->assertSee('Nenhuma partida pendente.')->assertSee('Tudo em dia por aqui.')
            ->assertSee('Você não tem desafios em aberto.')->assertSee('Suas atividades aparecerão aqui.')
            ->assertSee('Sem partidas ainda')->assertDontSee('Precisa de você');
        // Navegacao unica: o painel nao repete a barra interna da comunidade.
        $page->assertDontSee('community-nav', false);
        $this->assertMatchesRegularExpression('#href="'.preg_quote(url('/dashboard'), '#').'"\s+aria-current="page"#', $page->getContent());
    }

    public function test_next_match_shows_real_opponent_deck_round_and_action(): void
    {
        $owner = User::factory()->create();
        $me = User::factory()->create(['name' => 'Vini']);
        $rival = User::factory()->create(['name' => 'Lucas']);
        $t = $owner->tournaments()->create(['name' => 'Copa da Loja', 'format' => 'Pauper', 'system' => 'swiss', 'round_limit' => 3, 'tournament_date' => '2026-10-24',
            'public_token' => Str::random(40), 'public_slug' => 'copa-'.Str::random(20), 'invite_token' => Str::random(40)]);
        foreach ([[$me, 'Vini', 'Affinity'], [$rival, 'Lucas', 'Elfos']] as [$account, $name, $deckName]) {
            $profile = $account->ensurePlayerProfile();
            $deck = $profile->decks()->create(['name' => $deckName, 'format' => 'Pauper']);
            $t->players()->create(['name' => $name, 'deck_id' => $deck->id, 'deck_name' => $deckName, 'player_profile_id' => $profile->id]);
        }
        foreach (['C', 'D'] as $n) {
            $t->players()->create(['name' => $n]);
        }
        $this->actingAs($owner)->post(route('tournaments.start', $t));
        $mine = $t->matches()->where('status', 'pending')->get()->first(fn ($m) => in_array('Vini', [$m->playerOne->name, $m->playerTwo?->name], true));
        $opponent = $mine->playerOne->name === 'Vini' ? $mine->playerTwo->name : $mine->playerOne->name;

        $page = $this->actingAs($me)->get('/dashboard')->assertOk();
        $page->assertSee('Minha próxima partida')->assertSee('Copa da Loja')->assertSee('Rodada 1 · Pauper')
            ->assertSee('Affinity')->assertSee($opponent)->assertSee('Aguardando resultado')->assertSee('Enviar resultado')
            ->assertSee('Precisa de você')->assertDontSee('Nenhuma partida pendente.');
        $this->assertMatchesRegularExpression('/Torneios ativos.*?ui-stat-value">1</s', $page->getContent());
        $this->assertMatchesRegularExpression('/Partidas pendentes.*?ui-stat-value">1</s', $page->getContent());
    }

    public function test_header_marks_active_section_and_keeps_every_destination_reachable(): void
    {
        $user = User::factory()->create();
        $page = $this->actingAs($user)->get('/tournaments')->assertOk();
        $html = $page->getContent();
        $this->assertMatchesRegularExpression('#href="'.preg_quote(url('/tournaments'), '#').'"\s+aria-current="page"#', $html);
        $this->assertDoesNotMatchRegularExpression('#href="'.preg_quote(url('/dashboard'), '#').'"\s+aria-current="page"#', $html);
        foreach (['/dashboard', '/me/matches', '/challenges', '/me/collection', '/clubs', '/feed', '/notifications', '/me/profile', '/me/friends'] as $path) {
            $page->assertSee(url($path), false);
        }
        $page->assertSee('aria-controls="menu-mobile"', false)->assertSee('aria-controls="menu-account"', false);
    }

    public function test_notifications_page_uses_switches_and_mutes_only_switched_off_categories(): void
    {
        $user = User::factory()->create();
        $user->ensurePlayerProfile();
        NotificationPreference::create(['user_id' => $user->id, 'enabled' => true, 'muted_types' => ['club']]);
        $html = $this->actingAs($user)->get('/notifications')->assertOk()->assertSee('Caixa de entrada')->assertSee('Tudo em dia por aqui.')
            ->assertSee('Preferências')->assertDontSee('Silenciar categorias')->assertDontSee('Marcar todas como lidas')->getContent();
        // clube silenciado: switch desligado e hidden habilitado; desafio: switch ligado e hidden desabilitado
        $this->assertMatchesRegularExpression('/data-mute="club"\s*>/', $html);
        $this->assertMatchesRegularExpression('/value="club"\s+data-mute-field/', $html);
        $this->assertMatchesRegularExpression('/data-mute="challenge" checked/', $html);
        $this->assertMatchesRegularExpression('/value="challenge" disabled/', $html);
        $this->patch('/notifications/preferences', ['enabled' => 1, 'muted_types' => ['ranked']])->assertRedirect();
        $this->assertSame(['ranked'], NotificationPreference::where('user_id', $user->id)->value('muted_types'));
    }

    public function test_rivalries_page_explains_how_it_works(): void
    {
        $user = User::factory()->create();
        $user->ensurePlayerProfile();
        $this->actingAs($user)->get('/challenges/rivalries')->assertRedirect(route('challenges.index').'#rivalidades');
        $this->get('/challenges')->assertOk()->assertSee('Nenhum desafio ainda.');
        $friend = User::factory()->create();
        $friend->ensurePlayerProfile();
        $user->friendProfiles()->attach($friend->playerProfile, ['status' => 'accepted']);
        $friend->friendProfiles()->attach($user->playerProfile, ['status' => 'accepted']);
        $this->actingAs($user)->get('/challenges/new')->assertOk()->assertSee('class="ui-switch"', false)->assertSee('Partida ranqueada');
    }
}
