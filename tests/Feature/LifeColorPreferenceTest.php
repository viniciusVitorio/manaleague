<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ChallengeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** A cor do contador de vida é uma preferência do perfil e vale em toda partida da pessoa. */
class LifeColorPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_picks_a_color_in_preferences_and_it_shows_up_on_the_counter(): void
    {
        [$ana, $bia] = $this->friends();

        $this->actingAs($ana)->get(route('profiles.edit'))->assertOk()->assertSee('Cor no contador de vida')->assertSee('name="life_color" value="#65a30d"', false);
        $this->actingAs($ana)->patch(route('preferences.update'), ['theme' => 'dark', 'life_color' => '#65a30d'])->assertSessionHas('status', 'Preferências salvas.');
        $this->assertSame('#65a30d', $ana->fresh()->life_color);

        $challenge = $this->acceptedChallenge($ana, $bia);
        $players = $this->config($this->actingAs($bia)->get(route('life.challenge', $challenge)))['players'];
        $this->assertSame(['#65a30d', null], array_column($players, 'color'), 'Bia não escolheu: fica com a automática');

        // Volta para a automática.
        $this->actingAs($ana)->patch(route('preferences.update'), ['theme' => 'dark', 'life_color' => '']);
        $this->assertNull($ana->fresh()->life_color);
    }

    public function test_only_colors_from_the_palette_are_accepted(): void
    {
        $ana = User::factory()->create();
        $this->actingAs($ana)->patch(route('preferences.update'), ['theme' => 'dark', 'life_color' => '#ffffff'])->assertSessionHasErrors('life_color');
        $this->actingAs($ana)->patch(route('preferences.update'), ['theme' => 'dark', 'life_color' => 'red; background:url(x)'])->assertSessionHasErrors('life_color');
        $this->assertNull($ana->fresh()->life_color);

        // Branco vale (o contador troca o texto para escuro nesse lado).
        $this->actingAs($ana)->patch(route('preferences.update'), ['theme' => 'dark', 'life_color' => '#f4f4f5'])->assertSessionHasNoErrors();
        $this->assertSame('#f4f4f5', $ana->fresh()->life_color);
    }

    public function test_tournament_counter_uses_each_account_color_and_guests_get_the_automatic_one(): void
    {
        $owner = User::factory()->create();
        $one = User::factory()->create();
        $one->forceFill(['life_color' => '#2563eb'])->save();
        $t = $owner->tournaments()->create(['name' => 'Liga', 'format' => 'Pauper', 'tournament_date' => now()->toDateString(), 'status' => 'league',
            'public_token' => Str::random(40), 'public_slug' => 'liga-'.Str::lower(Str::random(12)), 'invite_token' => Str::random(40)]);
        $p1 = $t->players()->create(['name' => 'Alice', 'player_profile_id' => $one->ensurePlayerProfile()->id]);
        $p2 = $t->players()->create(['name' => 'Convidado']);
        $m = $t->matches()->create(['round' => 1, 'stage' => 'league', 'status' => 'pending', 'player_one_id' => $p1->id, 'player_two_id' => $p2->id]);

        $players = $this->config($this->actingAs($owner)->get(route('life.tournament', [$t, $m])))['players'];
        $this->assertSame(['#2563eb', null], array_column($players, 'color'));
    }

    private function acceptedChallenge(User $a, User $b): \App\Models\Challenge
    {
        $service = app(ChallengeService::class);
        $challenge = $service->create($a, $b->playerProfile, 'Pauper', 3, now()->addDay());
        $service->transition($challenge, $b, 'accept');

        return $challenge;
    }

    private function config(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertSame(1, preg_match('/data-config="([^"]+)"/', $response->getContent(), $m));

        return json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array{0: User, 1: User} */
    private function friends(): array
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $a->friendProfiles()->attach($b->ensurePlayerProfile(), ['status' => 'accepted']);
        $b->friendProfiles()->attach($a->ensurePlayerProfile(), ['status' => 'accepted']);

        return [$a->fresh(), $b->fresh()];
    }
}
