<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FriendsOfFriendsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name, bool $discoverable = true): User
    {
        $u = User::factory()->create(['name' => $name]);
        $u->ensurePlayerProfile()->update(['nickname' => $name, 'discoverable' => $discoverable]);

        return $u->fresh();
    }

    private function befriend(User $a, User $b): void
    {
        $a->friendProfiles()->attach($b->playerProfile, ['status' => 'accepted']);
        $b->friendProfiles()->attach($a->playerProfile, ['status' => 'accepted']);
    }

    public function test_friend_profile_lists_their_friends_with_add_buttons(): void
    {
        [$ana, $bia, $caio, $duda, $priv] = [$this->user('Ana'), $this->user('Bia'), $this->user('Caio'), $this->user('Duda'), $this->user('Privado', false)];
        $this->befriend($ana, $bia);
        $this->befriend($bia, $caio);
        $this->befriend($bia, $duda);
        $this->befriend($bia, $priv);
        $this->befriend($ana, $duda);

        $page = $this->actingAs($ana)->get(route('profiles.show', $bia->playerProfile))->assertOk()->assertSee('3 amigos')->assertSee('friends-pop', false)->assertDontSee('Amigos de Bia');
        $page->assertSee('Caio')->assertSee('Amigo em comum')->assertSee(route('friends.store', $caio->playerProfile->friend_code), false)
            ->assertDontSee('Privado');
        $this->assertStringContainsString('Você', $page->getContent());

        // amigo de amigo abre o perfil, mas sem a lista de amigos dele e sem decks
        $this->actingAs($ana)->get(route('profiles.show', $caio->playerProfile))->assertOk()->assertSee('Caio')->assertDontSee('friends-pop', false);
        // perfil "so por codigo" continua fechado; perfis abertos abrem ate para estranhos (sem decks nem lista de amigos)
        $this->actingAs($ana)->get(route('profiles.show', $priv->playerProfile))->assertForbidden();
        $stranger = $this->user('Zeca');
        $this->actingAs($stranger)->get(route('profiles.show', $caio->playerProfile))->assertOk()->assertDontSee('friends-pop', false);
        $this->actingAs($caio)->get(route('profiles.show', $duda->playerProfile))->assertOk(); // Caio-Bia-Duda
        $this->actingAs($stranger)->get(route('profiles.show', $bia->playerProfile))->assertOk()->assertDontSee('friends-pop', false);
        $this->actingAs($stranger)->get(route('profiles.show', $priv->playerProfile))->assertForbidden();

        $this->actingAs($ana)->post(route('friends.store', $caio->playerProfile->friend_code))->assertRedirect();
        $this->actingAs($ana)->get(route('profiles.show', $bia->playerProfile))->assertSee('Pedido enviado');
    }

    public function test_friends_page_suggests_friends_of_friends_by_mutual_count(): void
    {
        [$ana, $bia, $duda, $caio, $eva, $priv] = [$this->user('Ana'), $this->user('Bia'), $this->user('Duda'), $this->user('Caio'), $this->user('Eva'), $this->user('Privado', false)];
        $this->befriend($ana, $bia);
        $this->befriend($ana, $duda);
        $this->befriend($bia, $caio);
        $this->befriend($duda, $caio);
        $this->befriend($bia, $eva);
        $this->befriend($bia, $priv);

        $html = $this->actingAs($ana)->get('/me/friends')->assertOk()->assertSee('Talvez você conheça')
            ->assertSee('2 amigos em comum: Bia, Duda')->assertSee('Amigo de Bia')->assertDontSee('Privado')->getContent();
        $this->assertLessThan(strpos($html, 'Eva'), strpos($html, 'Caio'));

        $loner = $this->user('Solo');
        $this->actingAs($loner)->get('/me/friends')->assertOk()->assertDontSee('Talvez você conheça');
    }
}
