<?php

namespace Tests\Feature;

use App\Models\PlayerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileAddressTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $nickname, bool $discoverable = true): User
    {
        $user = User::factory()->create(['name' => $nickname]);
        $user->ensurePlayerProfile()->update(['nickname' => $nickname, 'discoverable' => $discoverable]);

        return $user->fresh();
    }

    public function test_player_picks_a_custom_address_and_the_old_one_redirects(): void
    {
        $vini = $this->account('Vinicius Salvador');
        $visitor = $this->account('Bia');
        $this->assertSame('vinicius-salvador', $vini->playerProfile->handle);

        $this->actingAs($vini)->get(route('profiles.edit'))->assertOk()->assertSee('Nome de usuário')->assertSee('Nome de exibição')->assertSee('value="vinicius-salvador"', false);
        $this->actingAs($vini)->patch(route('profiles.handle.update'), ['handle' => '@Vini-MTG '])->assertRedirect(route('profiles.edit'));

        $profile = $vini->playerProfile->fresh();
        $this->assertSame('vini-mtg', $profile->handle);
        $this->assertNotNull($profile->handle_changed_at);
        $this->actingAs($visitor)->get('/p/vini-mtg')->assertOk()->assertSee('Vinicius Salvador')->assertSee('<p class="profile-handle">@vini-mtg</p>', false);

        // endereço antigo: redireciona (302) para quem pode ver; ninguém mais pode pegá-lo enquanto estiver reservado
        $this->actingAs($visitor)->get('/p/vinicius-salvador')->assertRedirect('/p/vini-mtg')->assertStatus(302);
        $this->actingAs($visitor)->patch(route('profiles.handle.update'), ['handle' => 'vinicius-salvador'])->assertSessionHasErrors('handle');
        $this->assertTrue(PlayerProfile::handleTaken('vinicius-salvador', $visitor->playerProfile));

        // um perfil novo com o mesmo apelido não recebe o endereço reservado
        $this->assertSame('vinicius-salvador-2', $this->account('Vinicius Salvador')->playerProfile->handle);

        // depois da reserva, o endereço antigo some e fica livre
        $this->travel(PlayerProfile::HANDLE_HOLD_DAYS + 1)->days();
        $this->actingAs($visitor)->get('/p/vinicius-salvador')->assertNotFound();
        $this->assertFalse(PlayerProfile::handleTaken('vinicius-salvador', $visitor->playerProfile));
    }

    public function test_address_rules_and_one_change_every_thirty_days(): void
    {
        $ana = $this->account('Ana');
        $this->account('Bia');

        foreach (['ab', 'Ana Lúcia', '-ana', 'ana-', 'ana--mtg', 'admin', 'bia', str_repeat('a', 31), 'ana_mtg', 'perfil'] as $bad) {
            $this->actingAs($ana->fresh())->patch(route('profiles.handle.update'), ['handle' => $bad])->assertSessionHasErrors('handle');
        }
        $this->assertSame('ana', $ana->playerProfile->fresh()->handle);
        // limite de 10 tentativas por minuto
        $this->actingAs($ana->fresh())->patch(route('profiles.handle.update'), ['handle' => 'ana-x'])->assertStatus(429);
        $this->travel(2)->minutes();

        $this->actingAs($ana->fresh())->patch(route('profiles.handle.update'), ['handle' => 'ana'])->assertSessionHas('status', 'Esse já é o seu nome de usuário.');
        $this->actingAs($ana->fresh())->patch(route('profiles.handle.update'), ['handle' => 'ana-2026'])->assertSessionHasNoErrors();

        // segunda troca dentro de 30 dias: bloqueada, com a data em que libera
        $this->actingAs($ana->fresh())->patch(route('profiles.handle.update'), ['handle' => 'ana-mtg'])->assertSessionHasErrors('handle');
        $this->actingAs($ana->fresh())->get(route('profiles.edit'))->assertSee('Poderá trocar de novo em');
        $this->assertSame('ana-2026', $ana->playerProfile->fresh()->handle);

        // passados 30 dias, pode voltar ao endereço antigo (a própria reserva não atrapalha)
        $this->travel(PlayerProfile::HANDLE_COOLDOWN_DAYS + 1)->days();
        $this->actingAs($ana->fresh())->patch(route('profiles.handle.update'), ['handle' => 'ana'])->assertSessionHasNoErrors();
        $this->assertSame('ana', $ana->playerProfile->fresh()->handle);
        // o endereço que ela acabou de deixar fica reservado
        $this->assertTrue(PlayerProfile::handleTaken('ana-2026', $this->account('Caio')->playerProfile));
    }

    public function test_old_address_does_not_reveal_a_closed_profile(): void
    {
        $closed = $this->account('Fechado', false);
        $this->actingAs($closed)->patch(route('profiles.handle.update'), ['handle' => 'novo-fechado'])->assertSessionHasNoErrors();

        $stranger = $this->account('Zeca');
        $this->actingAs($stranger)->get('/p/fechado')->assertNotFound();
        $this->actingAs($closed)->get('/p/fechado')->assertRedirect('/p/novo-fechado');
    }
}
