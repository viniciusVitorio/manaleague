<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileSocialTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $nickname): User
    {
        $user = User::factory()->create(['name' => $nickname]);
        $user->ensurePlayerProfile()->update(['nickname' => $nickname]);

        return $user->fresh();
    }

    private function befriend(User $a, User $b): void
    {
        $a->friendProfiles()->attach($b->playerProfile, ['status' => 'accepted']);
        $b->friendProfiles()->attach($a->playerProfile, ['status' => 'accepted']);
    }

    public function test_friend_profile_has_no_head_to_head_section(): void
    {
        $ana = $this->account('Ana');
        $bia = $this->account('Bia');
        $this->befriend($ana, $bia);
        $challenge = Challenge::create(['challenger_id' => $ana->playerProfile->id, 'opponent_id' => $bia->playerProfile->id, 'format' => 'Pauper', 'best_of' => 3, 'status' => 'completed', 'expires_at' => now()->addDay()]);
        $challenge->casualMatch()->create(['status' => 'confirmed', 'challenger_games' => 2, 'opponent_games' => 0, 'confirmed_at' => now()]);

        // O retrospecto entre os dois fica no card do Rival (e em Desafios → Rivalidades), não numa seção à parte.
        $this->actingAs($ana)->get(route('profiles.show', $bia->playerProfile))->assertOk()
            ->assertDontSee('Confronto direto')->assertDontSee('id="h2h-title"', false)->assertSee('id="rival-title"', false);
    }

    public function test_back_link_returns_to_where_the_visitor_came_from(): void
    {
        $ana = $this->account('Ana');
        $bia = $this->account('Bia');
        $profileUrl = route('profiles.show', $bia->playerProfile);

        $this->actingAs($ana)->from(route('tournaments.index'))->get($profileUrl)
            ->assertSee('<a class="back-link" href="'.route('tournaments.index').'">← Voltar</a>', false);
        // sem página anterior (ou vindo de fora do site): volta para Amigos
        $this->actingAs($ana)->from('https://exemplo.com/x')->get($profileUrl)
            ->assertSee('<a class="back-link" href="'.route('friends.index').'">← Amigos</a>', false);
        // o próprio perfil continua voltando para Torneios
        $this->actingAs($bia)->from(route('friends.index'))->get(route('profiles.show', $bia->playerProfile))
            ->assertSee('← Torneios', false);
    }

    public function test_profile_has_copy_link_button_and_clickable_avatars(): void
    {
        $ana = $this->account('Ana');
        $bia = $this->account('Bia');
        $this->befriend($ana, $bia);
        $biaUrl = route('profiles.show', $bia->playerProfile);

        $this->actingAs($ana)->get($biaUrl)->assertOk()
            ->assertSee('data-copy-link="'.$biaUrl.'"', false)
            ->assertSee('aria-label="Copiar link do perfil"', false)
            ->assertSee('js/copy-link.js', false);

        // na lista de amigos, o avatar leva ao mesmo perfil, sem entrar no Tab
        $this->actingAs($ana)->get(route('friends.index'))
            ->assertSee('<a class="player-link avatar-link" tabindex="-1" aria-hidden="true" href="'.$biaUrl.'">', false);
    }
}
