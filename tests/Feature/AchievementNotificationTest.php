<?php

namespace Tests\Feature;

use App\Models\CommunityNotification;
use App\Models\GameMatch;
use App\Models\NotificationPreference;
use App\Models\Tournament;
use App\Models\User;
use App\Services\ChallengeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Conquista nova avisa no sino; várias de uma vez viram um aviso só. */
class AchievementNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirming_a_challenge_rings_the_bell_once_with_every_new_achievement(): void
    {
        [$ana, $bia] = $this->friends();
        $this->casual($ana, $bia, 3, 2, 0);

        // Ana e Bia já eram amigas: Parceiro de Mesa sai no mesmo aviso.
        $this->assertSame(['3 conquistas desbloqueadas: 🗡️ First Blood, 🧹 Clean Sweep e 🤝 Parceiro de Mesa'], $this->titles($ana));
        $this->assertSame(['2 conquistas desbloqueadas: 💀 0–2 Enjoyer e 🤝 Parceiro de Mesa'], $this->titles($bia));
        $this->assertSame('/me/profile', CommunityNotification::where('user_id', $ana->id)->where('type', 'achievement')->sole()->url);

        // Já tinha as duas: outra vitória 2–0 não avisa de novo.
        $this->casual($ana, $bia, 3, 2, 0);
        $this->assertCount(1, $this->titles($ana));

        $this->actingAs($ana)->get(route('notifications.index'))->assertOk()->assertSee('3 conquistas desbloqueadas: 🗡️ First Blood, 🧹 Clean Sweep e 🤝 Parceiro de Mesa');
    }

    public function test_long_lists_are_shortened(): void
    {
        [$ana, $bia] = $this->friends();
        $tournament = $ana->tournaments()->create(['name' => 'Liga', 'format' => 'Pauper', 'tournament_date' => now()->toDateString(), 'status' => Tournament::STATUS_LEAGUE,
            'public_token' => Str::random(40), 'public_slug' => 'liga-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40)]);
        $one = $tournament->players()->create(['name' => 'Ana', 'player_profile_id' => $ana->playerProfile->id]);
        $two = $tournament->players()->create(['name' => 'Bia', 'player_profile_id' => $bia->playerProfile->id]);
        $tournament->matches()->create(['round' => 1, 'stage' => GameMatch::STAGE_LEAGUE, 'player_one_id' => $one->id, 'player_two_id' => $two->id,
            'player_one_games' => 2, 'player_two_games' => 0, 'status' => GameMatch::STATUS_FINISHED, 'played_at' => now()->subDay()]);
        $this->travel(1)->seconds();

        // Torneio guardado antes + este desafio: First Blood, Clean Sweep, Estreia e Parceiro de Mesa saem juntas.
        $this->casual($ana, $bia, 3, 2, 0);

        $this->assertSame(['4 conquistas desbloqueadas: 🗡️ First Blood, 🧹 Clean Sweep, 🎟️ Estreia e mais 1'], $this->titles($ana));
    }

    public function test_opening_your_own_profile_shows_new_ones_without_ringing_the_bell(): void
    {
        [$ana] = $this->friends();

        $this->actingAs($ana)->get(route('profiles.index'))->assertOk()->assertSee('Parceiro de Mesa');
        $this->assertTrue($ana->playerProfile->achievements()->where('achievement', 'first_friend')->exists());
        $this->assertSame([], $this->titles($ana));
    }

    public function test_muting_achievements_keeps_the_bell_quiet(): void
    {
        [$ana, $bia] = $this->friends();
        NotificationPreference::forceCreate(['user_id' => $ana->id, 'enabled' => true, 'muted_types' => ['achievement']]);
        $this->casual($ana, $bia, 3, 2, 0);

        $this->assertTrue($ana->playerProfile->achievements()->where('achievement', 'clean_sweep')->exists());
        $this->assertSame([], $this->titles($ana));
    }

    private function casual(User $a, User $b, int $bestOf, int $one, int $two): void
    {
        $service = app(ChallengeService::class);
        $challenge = $service->create($a, $b->playerProfile, 'Pauper', $bestOf, now()->addDay());
        $service->transition($challenge, $b, 'accept');
        $match = $service->report($challenge, $a, $one, $two);
        $service->review($challenge, $b, 'confirm', $match->revision);
    }

    /** @return list<string> */
    private function titles(User $user): array
    {
        return CommunityNotification::where('user_id', $user->id)->where('type', 'achievement')->orderBy('id')->pluck('title')->all();
    }

    /** @return array{0: User, 1: User} */
    private function friends(): array
    {
        $a = User::factory()->create(['name' => 'Ana']);
        $b = User::factory()->create(['name' => 'Bia']);
        $a->friendProfiles()->attach($b->ensurePlayerProfile(), ['status' => 'accepted']);
        $b->friendProfiles()->attach($a->ensurePlayerProfile(), ['status' => 'accepted']);

        return [$a->fresh(), $b->fresh()];
    }
}
