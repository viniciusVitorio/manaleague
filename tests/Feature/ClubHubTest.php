<?php

namespace Tests\Feature;

use App\Models\ClubPost;
use App\Models\CommunityNotification;
use App\Models\GameMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Community\ClubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClubHubTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name): User
    {
        $u = User::factory()->create(['name' => $name]);
        $u->ensurePlayerProfile()->update(['nickname' => $name]);

        return $u->fresh();
    }

    public function test_invite_link_joins_private_club_and_can_be_regenerated(): void
    {
        [$owner, $guest, $member] = [$this->user('Dono'), $this->user('Convidado'), $this->user('Membro')];
        $club = app(ClubService::class)->create($owner, ['name' => 'Mesa de Sexta', 'privacy' => 'private', 'location' => 'Porto Alegre']);
        $club->members()->attach($member->id, ['role' => 'member']);
        $page = $this->actingAs($owner)->get('/clubs/'.$club->id)->assertOk()->assertSee('Convidar');
        $url = route('clubs.invite', $club->fresh()->invite_token);
        $page->assertSee($url, false);
        $this->actingAs($member)->get('/clubs/'.$club->id)->assertOk()->assertDontSee($url, false); // membro comum não vê o link

        $this->actingAs($guest)->get('/clubs/'.$club->id)->assertForbidden();
        $this->get($url)->assertOk()->assertSee('Mesa de Sexta')->assertSee('Entrar no clube');
        $this->assertNull($club->roleFor($guest)); // GET não entra
        $this->post($url)->assertRedirect('/clubs/'.$club->id);
        $this->assertSame('member', $club->roleFor($guest));
        $this->get('/clubs/'.$club->id)->assertOk();

        $this->actingAs($member)->post('/clubs/'.$club->id.'/invite')->assertForbidden();
        $this->actingAs($owner)->post('/clubs/'.$club->id.'/invite')->assertRedirect();
        $this->actingAs($this->user('Atrasado'))->get($url)->assertNotFound();
    }

    public function test_mural_notifies_members_and_only_managers_post(): void
    {
        [$owner, $a, $b] = [$this->user('Dono'), $this->user('Ana'), $this->user('Bia')];
        $club = app(ClubService::class)->create($owner, ['name' => 'Liga', 'privacy' => 'private']);
        foreach ([$a, $b] as $u) {
            $club->members()->attach($u->id, ['role' => 'member']);
        }

        $this->actingAs($a)->post('/clubs/'.$club->id.'/posts', ['body' => 'oi'])->assertForbidden();
        $this->actingAs($owner)->post('/clubs/'.$club->id.'/posts', ['body' => "Sexta tem Pauper às 19h.\nPremiação em booster.", 'pinned' => 1])->assertRedirect();
        $this->assertSame(2, CommunityNotification::where('type', 'club.post')->count());
        $this->actingAs($a)->get('/clubs/'.$club->id)->assertSee('Sexta tem Pauper às 19h.')->assertSee('Fixado');
        $this->actingAs($a)->get('/notifications')->assertSee('Aviso em Liga');
        $post = ClubPost::sole();
        $this->actingAs($a)->delete('/clubs/'.$club->id.'/posts/'.$post->id)->assertForbidden();
        $this->actingAs($owner)->delete('/clubs/'.$club->id.'/posts/'.$post->id)->assertRedirect();
        $this->assertSame(0, ClubPost::count());
    }

    public function test_club_ranking_sums_tournaments_and_counts_titles(): void
    {
        [$owner, $ana, $bia, $caio] = [$this->user('Dono'), $this->user('Ana'), $this->user('Bia'), $this->user('Caio')];
        $club = app(ClubService::class)->create($owner, ['name' => 'Liga', 'privacy' => 'public']);
        foreach ([1, 2] as $n) {
            $t = new Tournament(['name' => "Copa $n", 'format' => 'Pauper', 'tournament_date' => now(), 'status' => Tournament::STATUS_FINISHED, 'system' => 'single_elimination', 'public_token' => Str::random(40), 'public_slug' => 'c'.$n.Str::random(10), 'invite_token' => Str::random(40)]);
            $t->user_id = $owner->id;
            $t->club_id = $club->id;
            $t->save();
            $p = collect([$ana, $bia, $caio])->mapWithKeys(fn ($u) => [$u->name => $t->players()->create(['name' => $u->name, 'player_profile_id' => $u->playerProfile->id])]);
            $t->matches()->create(['round' => 1, 'player_one_id' => $p['Ana']->id, 'player_two_id' => $p['Caio']->id, 'player_one_games' => 2, 'player_two_games' => 0, 'status' => GameMatch::STATUS_FINISHED, 'stage' => 'league']);
            $t->matches()->create(['round' => 2, 'player_one_id' => $p['Ana']->id, 'player_two_id' => $p['Bia']->id, 'player_one_games' => $n === 1 ? 2 : 0, 'player_two_games' => $n === 1 ? 1 : 2, 'status' => GameMatch::STATUS_FINISHED, 'stage' => 'final']);
        }
        $ranking = app(ClubService::class)->ranking($club);
        $this->assertSame(['Ana', 'Bia', 'Caio'], $ranking->pluck('name')->all());
        $this->assertSame([9, 1, 2, 3], [$ranking[0]['points'], $ranking[0]['titles'], $ranking[0]['events'], $ranking[0]['wins']]);
        $this->assertSame([3, 1], [$ranking[1]['points'], $ranking[1]['titles']]);

        $this->actingAs($caio)->get('/clubs/'.$club->id)->assertOk()->assertSee('Ranking do clube')->assertSee('Entrar no clube')->assertDontSee('campeão: Ana');
        $this->actingAs($caio)->get('/clubs')->assertOk()->assertSee('Liga')->assertSee('2 torneios');
        $this->actingAs($caio)->post('/clubs/'.$club->id.'/join')->assertRedirect();
        $this->actingAs($caio)->get('/clubs/'.$club->id)->assertSee('campeão: Ana')->assertSee('campeão: Bia');
        $this->actingAs($caio)->get('/clubs')->assertSee('Meus clubes')->assertSee('Membro');
    }

    public function test_managers_add_friends_from_list_but_not_strangers(): void
    {
        [$owner, $friend, $stranger] = [$this->user('Dono'), $this->user('Amigo'), $this->user('Estranho')];
        $owner->friendProfiles()->attach($friend->playerProfile, ['status' => 'accepted']);
        $friend->friendProfiles()->attach($owner->playerProfile, ['status' => 'accepted']);
        $club = app(ClubService::class)->create($owner, ['name' => 'Liga', 'privacy' => 'private']);
        $this->actingAs($owner)->get('/clubs/'.$club->id)->assertSee('Adicionar amigo')->assertSee('<option value="'.$friend->playerProfile->id.'">Amigo</option>', false);
        $this->post('/clubs/'.$club->id.'/members', ['profile_id' => $stranger->playerProfile->id])->assertForbidden();
        $this->post('/clubs/'.$club->id.'/members', ['profile_id' => $friend->playerProfile->id])->assertRedirect();
        $this->assertSame('member', $club->roleFor($friend));
        $this->assertTrue(CommunityNotification::where('user_id', $friend->id)->where('type', 'club.invite')->exists());
        $this->post('/clubs/'.$club->id.'/members', ['email' => 'ninguem@x.dev'])->assertSessionHasErrors('email');
        $this->post('/clubs/'.$club->id.'/tournaments', ['name' => 'Copa', 'format' => 'Pioneer', 'tournament_date' => now()->addDay()->toDateString()])->assertRedirect();
        $this->assertTrue(CommunityNotification::where('user_id', $friend->id)->where('type', 'club.tournament')->exists());
    }
}
