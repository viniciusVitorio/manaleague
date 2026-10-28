<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Tournament;
use App\Models\User;
use App\Services\StandingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TournamentSocialTest extends TestCase
{
    use RefreshDatabase;

    private function tournament(User $owner, string $name = 'Liga'): Tournament
    {
        return $owner->tournaments()->create(['name' => $name, 'format' => 'Pauper', 'tournament_date' => '2026-10-10', 'status' => 'league',
            'public_token' => Str::random(40), 'public_slug' => Str::slug($name).'-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40)]);
    }

    private function account(string $nickname, bool $discoverable = true): User
    {
        $user = User::factory()->create(['name' => $nickname]);
        $user->ensurePlayerProfile()->update(['nickname' => $nickname, 'discoverable' => $discoverable]);

        return $user->fresh();
    }

    private function play(Tournament $t, int $round, Player $one, ?Player $two, int $a = 2, int $b = 0): void
    {
        $t->matches()->create(['round' => $round, 'stage' => GameMatch::STAGE_LEAGUE, 'player_one_id' => $one->id, 'player_two_id' => $two?->id,
            'player_one_games' => $a, 'player_two_games' => $b, 'status' => $two ? GameMatch::STATUS_FINISHED : GameMatch::STATUS_BYE]);
    }

    public function test_win_streak_marks_fire_at_three_and_lightning_at_six(): void
    {
        $owner = User::factory()->create();
        $t = $this->tournament($owner);
        [$ana, $bia, $caio] = collect(['Ana', 'Bia', 'Caio'])->map(fn ($n) => $t->players()->create(['name' => $n]))->all();

        $this->play($t, 1, $ana, $bia);          // Ana 1
        $this->play($t, 2, $ana, $caio);         // Ana 2
        $this->play($t, 3, $ana, null);          // BYE: não conta nem zera
        $this->play($t, 4, $ana, $bia);          // Ana 3 -> 🔥
        $this->play($t, 1, $caio, $bia, 1, 1);   // empate: Caio e Bia zerados
        $this->play($t, 5, $bia, $caio, 0, 2);   // Caio 1

        $streaks = app(StandingsService::class)->for($t)->mapWithKeys(fn ($row) => [$row['player']->name => $row['streak']]);
        $this->assertSame(['Ana' => 3, 'Bia' => 0, 'Caio' => 1], $streaks->sortKeys()->all());

        $html = $this->actingAs($owner)->get(route('tournaments.show', $t))->assertOk()->getContent();
        $this->assertStringContainsString('aria-label="3 vitórias seguidas">🔥</span>', $html);
        $this->assertStringNotContainsString('⚡', $html);

        foreach ([6, 7, 8] as $round) {
            $this->play($t, $round, $ana, $caio);
        }
        $this->actingAs($owner)->get(route('tournaments.show', $t))->assertSee('aria-label="6 vitórias seguidas">⚡</span>', false);
        $this->get(route('arena.live', $t->public_slug))->assertOk()->assertJsonFragment(['name' => 'Ana', 'streak' => 6]);

        // derrota zera a sequência
        $this->play($t, 9, $caio, $ana);
        $this->actingAs($owner)->get(route('tournaments.show', $t))->assertDontSee('vitórias seguidas');
    }

    public function test_ranking_names_link_to_profiles_the_viewer_can_open(): void
    {
        $owner = $this->account('Org');
        $t = $this->tournament($owner);
        $open = $this->account('Aberta');
        $private = $this->account('Fechado', false);
        $t->players()->create(['name' => 'Aberta', 'player_profile_id' => $open->playerProfile->id]);
        $t->players()->create(['name' => 'Fechado', 'player_profile_id' => $private->playerProfile->id]);
        $t->players()->create(['name' => 'Convidado']); // sem conta: nunca vira link

        $openLink = 'href="'.route('profiles.show', $open->playerProfile).'"';
        $privateLink = 'href="'.route('profiles.show', $private->playerProfile).'"';

        // organizador vê os dois (ele organizou o torneio do perfil fechado)
        $this->actingAs($owner)->get(route('tournaments.show', $t))->assertSee($openLink, false)->assertSee($privateLink, false);

        // outro participante, na página pública: só o perfil aberto vira link
        $public = route('arena.public', $t->public_slug);
        $this->actingAs($private)->get($public)->assertOk()->assertSee($openLink, false);
        $this->actingAs($open)->get($public)->assertDontSee($privateLink, false);

        // visitante sem login: nenhum link de perfil
        auth()->logout();
        $this->get($public)->assertOk()->assertDontSee($openLink, false)->assertSee('Aberta');
    }

    public function test_names_link_to_profiles_across_pages_without_changing_the_text(): void
    {
        $owner = $this->account('Org');
        $ana = $this->account('Ana');
        $bia = $this->account('Bia');
        $t = $this->tournament($owner);
        $pa = $t->players()->create(['name' => 'Ana', 'player_profile_id' => $ana->playerProfile->id]);
        $pb = $t->players()->create(['name' => 'Bia', 'player_profile_id' => $bia->playerProfile->id]);
        $t->matches()->create(['round' => 1, 'stage' => GameMatch::STAGE_LEAGUE, 'status' => 'pending', 'player_one_id' => $pa->id, 'player_two_id' => $pb->id]);
        $biaLink = '<a class="player-link" href="'.route('profiles.show', $bia->playerProfile).'">Bia</a>';

        // card de partida na página pública e na página do torneio
        $this->actingAs($ana)->get(route('arena.public', $t->public_slug))->assertSee($biaLink, false);
        $this->actingAs($owner)->get(route('tournaments.show', $t))->assertSee($biaLink, false)
            ->assertDontSee('@else', false)->assertDontSee('Resultado travado'); // partida editável: sem aviso de travado
        // minhas partidas
        $this->actingAs($ana)->get(route('matches.mine'))->assertSee($biaLink, false)->assertSeeText('Ana × Bia');

        // pedido de amizade recebido (página de amigos)
        $this->actingAs($bia)->patch(route('profiles.friend', $ana->playerProfile))->assertRedirect();
        $this->actingAs($ana)->get(route('friends.index'))->assertSee('href="'.route('profiles.show', $bia->playerProfile).'"', false);
    }

    public function test_tournaments_page_splits_organizing_and_playing(): void
    {
        $me = $this->account('Eu');
        $organizer = $this->account('Organizador');
        $mine = $this->tournament($me, 'Meu Torneio');
        $theirs = $this->tournament($organizer, 'Torneio da Liga');
        $theirs->players()->create(['name' => 'Eu', 'player_profile_id' => $me->playerProfile->id]);
        $this->tournament($organizer, 'Outro Torneio'); // não participo

        $page = $this->actingAs($me)->get(route('tournaments.index'))->assertOk();
        $page->assertSeeInOrder(['Organizados por você', 'Meu Torneio', 'Que você participa', 'Torneio da Liga', 'por Organizador'])
            ->assertDontSee('Outro Torneio')
            ->assertSee(route('arena.public', $theirs->public_slug), false)
            ->assertSee(route('tournaments.show', $mine), false);

        $fresh = $this->account('Novato');
        $this->actingAs($fresh)->get(route('tournaments.index'))->assertSee('Nenhum torneio criado')->assertSee('Você ainda não está em nenhum torneio');
    }
}
