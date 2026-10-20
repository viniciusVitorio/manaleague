<?php

namespace Tests\Feature;

use App\Models\PlayerProfile;
use App\Models\User;
use App\Services\FriendshipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FriendsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_profile_gets_a_unique_friend_code(): void
    {
        $a = User::factory()->create()->ensurePlayerProfile();
        $b = User::factory()->create()->ensurePlayerProfile();

        $this->assertMatchesRegularExpression('/^[2-9A-HJ-NP-Z]{8}$/', $a->friend_code);
        $this->assertNotSame($a->friend_code, $b->friend_code);
        $this->assertSame(substr($a->friend_code, 0, 4).'-'.substr($a->friend_code, 4), $a->formattedFriendCode());
    }

    public function test_search_by_nickname_and_by_code_respects_privacy(): void
    {
        $me = User::factory()->create();
        [$public] = $this->player('Liliana Vess');
        [$private, $privateProfile] = $this->player('Liliana Secreta');
        $privateProfile->update(['discoverable' => false]);

        $this->actingAs($me)->get(route('friends.index', ['q' => 'lili']))
            ->assertOk()->assertSee('Liliana Vess')->assertDontSee('Liliana Secreta');

        // Pelo codigo exato (com ou sem hifen/minusculas) o perfil privado aparece.
        $code = strtolower($privateProfile->formattedFriendCode());
        $this->actingAs($me)->get(route('friends.index', ['q' => $code]))->assertOk()->assertSee('Liliana Secreta');
    }

    public function test_search_needs_three_letters_and_ignores_own_profile(): void
    {
        $me = User::factory()->create(['name' => 'Jace Beleren']);
        $me->ensurePlayerProfile();
        [, $other] = $this->player('Jace Clone');

        $this->actingAs($me)->get(route('friends.index', ['q' => 'ja']))->assertOk()->assertDontSee('Jace Clone');
        $response = $this->actingAs($me)->get(route('friends.index', ['q' => 'jace']))->assertOk()->assertSee('Jace Clone');
        $this->assertStringNotContainsString(route('friends.store', $me->playerProfile->friend_code), $response->getContent());
    }

    public function test_full_friendship_flow_through_friends_page(): void
    {
        $alice = User::factory()->create();
        $aliceProfile = $alice->ensurePlayerProfile();
        [$bob, $bobProfile] = $this->player('Bob');

        $this->actingAs($alice)->post(route('friends.store', $bobProfile->friend_code))->assertSessionHas('status');
        $this->actingAs($alice)->post(route('friends.store', $bobProfile->friend_code))->assertSessionHas('status', 'Pedido já enviado; aguardando resposta.');

        // Bob ve o badge no menu e o pedido na pagina de amigos.
        $this->actingAs($bob)->get(route('friends.index'))->assertOk()->assertSee('Pedidos recebidos')->assertSee('1 pedido de amizade pendente', false);
        $this->actingAs($bob)->patch(route('friends.accept-code', $aliceProfile->friend_code))->assertSessionHas('status');

        $this->actingAs($alice)->get(route('friends.index'))->assertOk()->assertSee('Meus amigos')->assertSee('Bob');
        $this->assertSame(1, app(FriendshipService::class)->friends($bob)->count());

        $this->actingAs($bob)->delete(route('friends.destroy', $aliceProfile->friend_code))->assertSessionHas('status', 'Amizade desfeita.');
        $this->assertDatabaseCount('player_profile_user', 0);
    }

    public function test_crossed_requests_become_friendship(): void
    {
        $alice = User::factory()->create();
        $aliceProfile = $alice->ensurePlayerProfile();
        [$bob, $bobProfile] = $this->player('Bob');

        $this->actingAs($alice)->post(route('friends.store', $bobProfile->friend_code));
        $this->actingAs($bob)->post(route('friends.store', $aliceProfile->friend_code))->assertSessionHas('status', 'Você e '.$aliceProfile->nickname.' agora são amigos.');

        $this->assertDatabaseCount('player_profile_user', 2);
        $this->assertDatabaseMissing('player_profile_user', ['status' => 'pending']);
    }

    public function test_cannot_befriend_self_or_unknown_codes(): void
    {
        $me = User::factory()->create();
        $mine = $me->ensurePlayerProfile();

        $this->actingAs($me)->post(route('friends.store', $mine->friend_code))->assertSessionHasErrors('friend');
        $this->actingAs($me)->post(route('friends.store', 'ZZZZ-ZZZZ'))->assertNotFound();
        $this->actingAs($me)->post('/me/friends/<script>')->assertNotFound();
    }

    public function test_pending_requests_are_capped(): void
    {
        $me = User::factory()->create();
        $me->ensurePlayerProfile();
        $ids = collect(range(1, FriendshipService::MAX_PENDING_OUTGOING))->map(fn ($i) => $this->player("Alvo {$i}")[1]->id);
        $me->friendProfiles()->attach($ids->mapWithKeys(fn ($id) => [$id => ['status' => 'pending']])->all());

        [, $extra] = $this->player('Mais um');
        $this->actingAs($me)->post(route('friends.store', $extra->friend_code))->assertSessionHasErrors('friend');
    }

    public function test_privacy_setting_can_be_changed(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me)->patch(route('friends.privacy'), ['discoverable' => '0'])->assertSessionHas('status');
        $this->assertFalse($me->playerProfile()->sole()->discoverable);
    }

    public function test_friends_page_shows_my_code(): void
    {
        $me = User::factory()->create();
        $code = $me->ensurePlayerProfile()->formattedFriendCode();

        $this->get(route('friends.index'))->assertRedirect(route('login'));
        $this->actingAs($me)->get(route('friends.index'))->assertOk()->assertSee($code);
    }

    /** @return array{0: User, 1: PlayerProfile} */
    private function player(string $nickname): array
    {
        $user = User::factory()->create(['name' => $nickname]);

        return [$user, $user->ensurePlayerProfile()];
    }
}
