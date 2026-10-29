<?php

namespace Tests\Feature;

use App\Models\CommunityNotification;
use App\Models\GameMatch;
use App\Models\PlayerProfile;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventDayTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $nickname, bool $discoverable = true): User
    {
        $user = User::factory()->create(['name' => $nickname]);
        $user->ensurePlayerProfile()->update(['nickname' => $nickname, 'discoverable' => $discoverable]);

        return $user->fresh();
    }

    private function tournament(User $owner, string $status = 'setup', string $name = 'Copa'): Tournament
    {
        return $owner->tournaments()->create(['name' => $name, 'format' => 'Pauper', 'tournament_date' => '2026-11-01', 'status' => $status,
            'public_token' => Str::random(40), 'public_slug' => Str::slug($name).'-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40)]);
    }

    public function test_profile_address_uses_the_nickname_and_old_links_redirect(): void
    {
        $ana = $this->account('Ana Lúcia');
        $other = $this->account('Ana Lúcia');
        $this->assertSame('ana-lucia', $ana->playerProfile->handle);
        $this->assertSame('ana-lucia-2', $other->playerProfile->handle);
        $this->assertSame(url('/p/ana-lucia'), route('profiles.show', $ana->playerProfile));

        $visitor = $this->account('Bia');
        $this->actingAs($visitor)->get('/p/ana-lucia')->assertOk()->assertSee('Ana Lúcia');
        $this->actingAs($visitor)->get('/players/'.$ana->playerProfile->id)->assertRedirect('/p/ana-lucia')->assertStatus(301);

        // trocar o apelido muda o endereço
        $ana->playerProfile->update(['nickname' => 'Liliana']);
        $this->assertSame('liliana', $ana->playerProfile->fresh()->handle);
        $this->actingAs($visitor)->get('/p/ana-lucia')->assertNotFound();

        // perfil "Só com código": o endereço antigo não revela o novo para estranhos
        $private = $this->account('Fechado', false);
        $this->actingAs($visitor)->get('/players/'.$private->playerProfile->id)->assertForbidden();
        $this->assertSame('jogador', PlayerProfile::uniqueHandle('🐉 !!', fn () => false));
    }

    public function test_public_tournament_link_has_a_whatsapp_preview_with_the_leader(): void
    {
        $owner = $this->account('Org');
        $t = $this->tournament($owner, 'league');
        $ana = $t->players()->create(['name' => 'Ana']);
        $bia = $t->players()->create(['name' => 'Bia']);
        $t->matches()->create(['round' => 1, 'stage' => GameMatch::STAGE_LEAGUE, 'status' => GameMatch::STATUS_FINISHED,
            'player_one_id' => $ana->id, 'player_two_id' => $bia->id, 'player_one_games' => 2, 'player_two_games' => 0]);

        $this->get(route('arena.public', $t->public_slug))->assertOk()
            ->assertSee('<meta property="og:title" content="🏆 Copa">', false)
            ->assertSee('<meta property="og:description" content="Pauper · 01/11/2026 · 2 jogadores · Líder: Ana (3 pts)">', false)
            ->assertSee('<meta property="og:image" content="'.asset('android-chrome-512x512.png').'">', false)
            ->assertSee('<title>Copa · ManaLeague</title>', false);
    }

    public function test_deck_in_standings_links_only_for_who_can_see_the_deck(): void
    {
        $owner = $this->account('Org');
        $ana = $this->account('Ana');
        $friend = $this->account('Amiga');
        $stranger = $this->account('Zeca');
        $ana->friendProfiles()->attach($friend->playerProfile, ['status' => 'accepted']);
        $friend->friendProfiles()->attach($ana->playerProfile, ['status' => 'accepted']);
        $deck = $ana->playerProfile->decks()->create(['name' => 'Burn', 'format' => 'Pauper', 'is_visible_to_friends' => true]);
        $t = $this->tournament($owner);
        $t->players()->create(['name' => 'Ana', 'player_profile_id' => $ana->playerProfile->id, 'deck_id' => $deck->id, 'deck_name' => 'Burn']);
        $link = 'href="'.route('decks.show', $deck).'">Burn</a>';
        $public = route('arena.public', $t->public_slug);

        $this->actingAs($ana)->get($public)->assertSee($link, false);
        $this->actingAs($friend)->get($public)->assertSee($link, false);
        $this->actingAs($stranger)->get($public)->assertDontSee($link, false)->assertSee('Burn');

        $deck->update(['is_visible_to_friends' => false]);
        $this->actingAs($friend)->get($public)->assertDontSee($link, false);
        $this->actingAs($ana)->get($public)->assertSee($link, false);
    }

    public function test_bell_counts_only_notifications_you_can_still_see(): void
    {
        $me = $this->account('Eu');
        $other = $this->account('Outro');
        $hidden = $this->tournament($other, 'league', 'Torneio Alheio');
        CommunityNotification::create(['user_id' => $me->id, 'type' => 'achievement.unlocked', 'title' => 'Conquista', 'url' => '/me/profile', 'event_key' => 'a', 'data' => []]);
        CommunityNotification::create(['user_id' => $me->id, 'type' => 'tournament.round', 'title' => 'Rodada pronta', 'url' => '/tournaments/'.$hidden->id, 'event_key' => 'b', 'data' => []]);

        $this->actingAs($me)->get(route('notifications.index'))->assertOk()
            ->assertSee('aria-label="Notificações, 1 não lidas"', false)
            ->assertSee('Conquista')->assertDontSee('Rodada pronta');
    }

    public function test_organizer_runs_the_round_timer_and_everyone_sees_it(): void
    {
        $owner = $this->account('Org');
        $player = $this->account('Ana');
        $t = $this->tournament($owner, 'league');
        $entry = $t->players()->create(['name' => 'Ana', 'player_profile_id' => $player->playerProfile->id]);
        $rival = $t->players()->create(['name' => 'Bia']);
        $t->matches()->create(['round' => 1, 'stage' => GameMatch::STAGE_LEAGUE, 'status' => 'pending', 'player_one_id' => $entry->id, 'player_two_id' => $rival->id]);

        $this->actingAs($player)->post(route('tournaments.timer.start', $t), ['minutes' => 50])->assertForbidden();
        $this->actingAs($owner)->post(route('tournaments.timer.start', $t), ['minutes' => 2])->assertSessionHasErrors('minutes');

        $this->travelTo(now()->startOfMinute());
        $this->actingAs($owner)->post(route('tournaments.timer.start', $t), ['minutes' => 50])->assertSessionHas('status', 'Cronômetro iniciado: 50 minutos.');
        $endsAt = now()->addMinutes(50)->toIso8601String();
        $this->assertSame($endsAt, $t->fresh()->round_ends_at->toIso8601String());

        $this->actingAs($owner)->get(route('tournaments.show', $t))->assertSee('data-ends-at="'.$endsAt.'"', false)->assertSee('>50:00</span>', false)->assertSee('Reiniciar');
        $this->get(route('arena.live', $t->public_slug))->assertJsonPath('round_ends_at', $endsAt);
        $this->get(route('arena.public', $t->public_slug))->assertSee('data-ends-at="'.$endsAt.'"', false);
        $this->get(route('arena.tv', $t->public_slug))->assertSee('data-ends-at="'.$endsAt.'"', false);
        $this->actingAs($player)->get(route('matches.mine'))->assertSee('round-timer-compact', false);

        $this->travel(51)->minutes();
        $this->actingAs($owner)->get(route('tournaments.show', $t))->assertSee('Tempo esgotado');

        $this->actingAs($owner)->delete(route('tournaments.timer.stop', $t))->assertSessionHas('status', 'Cronômetro parado.');
        $this->assertNull($t->fresh()->round_ends_at);
        $this->get(route('arena.live', $t->public_slug))->assertJsonPath('round_ends_at', null);

        // antes de começar não tem cronômetro
        $setup = $this->tournament($owner, 'setup', 'Outra');
        $this->actingAs($owner)->post(route('tournaments.timer.start', $setup), ['minutes' => 50])->assertStatus(409);
    }

    public function test_check_in_by_players_and_organizer_and_removing_absent(): void
    {
        $owner = $this->account('Org');
        $ana = $this->account('Ana');
        $t = $this->tournament($owner);
        $mine = $t->players()->create(['name' => 'Ana', 'player_profile_id' => $ana->playerProfile->id]);
        $guest = $t->players()->create(['name' => 'Convidado']);
        $absent = $t->players()->create(['name' => 'Faltou']);

        // organizador não consegue remover ausentes sem nenhuma presença confirmada
        $this->actingAs($owner)->delete(route('tournaments.absent.destroy', $t))->assertSessionHasErrors('check_in');
        $this->assertSame(3, $t->players()->count());

        // jogadora confirma pela página pública
        $this->actingAs($ana)->get(route('arena.public', $t->public_slug))->assertSee('Fazer check-in');
        $this->actingAs($ana)->post(route('tournaments.check-in', $t))->assertSessionHas('status', 'Presença confirmada. Boa sorte!');
        $this->assertNotNull($mine->fresh()->checked_in_at);
        $this->actingAs($ana)->get(route('arena.public', $t->public_slug))->assertSee('Presença confirmada')->assertSee('Desfazer check-in');

        // quem não está inscrito não faz check-in
        $this->actingAs($this->account('Zeca'))->post(route('tournaments.check-in', $t))->assertNotFound();

        // organizador marca o convidado sem conta e vê o resumo
        $this->actingAs($ana)->patch(route('tournaments.players.check-in', [$t, $guest]))->assertForbidden();
        $this->actingAs($owner)->patch(route('tournaments.players.check-in', [$t, $guest]))->assertSessionHas('status', 'Convidado marcado como presente.');
        $this->actingAs($owner)->get(route('tournaments.show', $t))->assertSee('2 de 3')->assertSee('Remover ausentes');

        $this->actingAs($owner)->delete(route('tournaments.absent.destroy', $t))->assertSessionHas('status', '1 ausente removido.');
        $this->assertSame(['Ana', 'Convidado'], $t->players()->pluck('name')->all());
        $this->assertModelMissing($absent);

        // depois que o torneio começa, o check-in fecha
        $t->update(['status' => 'league']);
        $this->actingAs($ana)->post(route('tournaments.check-in', $t))->assertStatus(409);
    }
}
