<?php

namespace Tests\Feature;

use App\Models\DeckCard;
use App\Models\GameMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Services\AchievementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AchievementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_lists_every_achievement_with_progress(): void
    {
        $profile = User::factory()->create()->ensurePlayerProfile();
        $catalog = app(AchievementService::class)->catalog($profile);

        $this->assertCount(count(AchievementService::definitions()), $catalog);
        $this->assertTrue($catalog->every(fn ($a) => ! $a['unlocked'] && $a['percent'] === 0));
        $this->assertEqualsCanonicalizing(array_keys(AchievementService::CATEGORIES), $catalog->pluck('category')->unique()->values()->all());
    }

    public function test_collection_achievements_unlock_from_registered_cards(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensurePlayerProfile();
        $deck = $profile->decks()->create(['name' => 'Cinco cores', 'format' => 'Commander']);
        $deck->cards()->create($this->card('Commander Legendário', 'commander', 1, 'WUBRG'));
        $deck->cards()->create($this->card('Plains', 'main', 99, null));

        $unlocked = app(AchievementService::class)->sync($profile);

        $this->assertContains('deckbuilder', $unlocked);
        $this->assertContains('commander_ready', $unlocked);
        $this->assertContains('rainbow', $unlocked);
        $this->assertNotContains('arsenal', $unlocked);
    }

    public function test_social_achievements_unlock_after_friendship_is_accepted(): void
    {
        $alice = User::factory()->create();
        $aliceProfile = $alice->ensurePlayerProfile();
        $bob = User::factory()->create();
        $bobProfile = $bob->ensurePlayerProfile();

        $this->actingAs($alice)->post(route('friends.store', $bobProfile->friend_code));
        $this->actingAs($bob)->patch(route('friends.accept-code', $aliceProfile->friend_code));

        $this->assertContains('first_friend', app(AchievementService::class)->sync($aliceProfile));
        $this->assertContains('first_friend', app(AchievementService::class)->sync($bobProfile));
    }

    public function test_unlocks_are_permanent_and_highlighted_once(): void
    {
        [$user, $profile, $tournament] = $this->playerInTournament();
        $this->win($tournament, $profile);

        $this->actingAs($user)->get(route('profiles.index'))
            ->assertOk()->assertSee('Conquista desbloqueada')->assertSee('First Blood')->assertSee('Nova!');

        // Segunda visita: continua desbloqueada, mas sem o destaque de "nova".
        $this->actingAs($user)->get(route('profiles.index'))
            ->assertOk()->assertDontSee('Conquista desbloqueada')->assertDontSee('Nova!');

        // Mesmo que o resultado seja apagado, a conquista nao some.
        $tournament->matches()->delete();
        app(AchievementService::class)->sync($profile);
        $this->assertTrue(app(AchievementService::class)->catalog($profile)->firstWhere('key', 'first_win')['unlocked']);
    }

    public function test_saving_a_result_syncs_achievements_for_both_players(): void
    {
        $organizer = User::factory()->create();
        $tournament = $this->tournament($organizer);
        $players = collect(['Ajani', 'Chandra', 'Jace', 'Liliana'])->map(function (string $name) use ($tournament) {
            $user = User::factory()->create(['name' => $name]);
            return $tournament->players()->create(['name' => $name, 'player_profile_id' => $user->ensurePlayerProfile()->id]);
        });
        $this->actingAs($organizer)->post(route('tournaments.start', $tournament));
        $match = $tournament->matches()->where('status', GameMatch::STATUS_PENDING)->firstOrFail();

        $this->actingAs($organizer)->put(route('matches.update', [$tournament, $match]), ['player_one_games' => 2, 'player_two_games' => 0]);

        $winner = $match->playerOne->profile;
        $loser = $match->playerTwo->profile;
        $this->assertTrue($winner->achievements()->where('achievement', 'clean_sweep')->exists());
        $this->assertTrue($loser->achievements()->where('achievement', 'zero_two')->exists());
        $this->assertTrue($loser->achievements()->where('achievement', 'debut')->exists());
    }

    public function test_progress_and_rarity_are_reported(): void
    {
        [$user, $profile, $tournament] = $this->playerInTournament();
        $this->win($tournament, $profile);
        $this->win($tournament, $profile);
        User::factory()->create()->ensurePlayerProfile(); // um segundo jogador sem conquistas

        app(AchievementService::class)->sync($profile);
        $catalog = app(AchievementService::class)->catalog($profile);

        $onFire = $catalog->firstWhere('key', 'on_fire');
        $this->assertFalse($onFire['unlocked']);
        $this->assertSame(2, $onFire['current']);
        $this->assertSame(66, $onFire['percent']);
        $this->assertSame(50, $catalog->firstWhere('key', 'first_win')['rarity']);
    }

    public function test_undefeated_requires_finished_tournament(): void
    {
        [$user, $profile, $tournament] = $this->playerInTournament();
        $this->win($tournament, $profile);
        $this->assertNotContains('undefeated', app(AchievementService::class)->sync($profile));

        $tournament->update(['status' => Tournament::STATUS_FINISHED]);
        $this->assertContains('undefeated', app(AchievementService::class)->sync($profile));
    }

    private function playerInTournament(): array
    {
        $user = User::factory()->create();
        $profile = $user->ensurePlayerProfile();
        $tournament = $this->tournament(User::factory()->create());
        $tournament->players()->create(['name' => $profile->nickname, 'player_profile_id' => $profile->id]);
        $tournament->players()->create(['name' => 'Oponente']);

        return [$user, $profile, $tournament];
    }

    private function win(Tournament $tournament, $profile): void
    {
        $me = $tournament->players()->where('player_profile_id', $profile->id)->sole();
        $opponent = $tournament->players()->where('name', 'Oponente')->sole();
        $tournament->matches()->create([
            'round' => (int) $tournament->matches()->max('round') + 1, 'stage' => GameMatch::STAGE_LEAGUE,
            'player_one_id' => $me->id, 'player_two_id' => $opponent->id, 'player_one_games' => 2, 'player_two_games' => 1,
            'status' => GameMatch::STATUS_FINISHED, 'played_at' => now()->addMinutes($tournament->matches()->count()),
        ]);
    }

    private function tournament(User $organizer): Tournament
    {
        return $organizer->tournaments()->create([
            'name' => 'Liga', 'format' => 'Pauper', 'tournament_date' => '2026-10-10',
            'public_token' => Str::random(40), 'public_slug' => 'liga-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40),
        ]);
    }

    private function card(string $name, string $board, int $quantity, ?string $identity): array
    {
        return [
            'scryfall_id' => (string) Str::uuid(), 'name' => $name, 'board' => $board, 'quantity' => $quantity,
            'cmc' => 0, 'type_line' => $board === 'commander' ? 'Legendary Creature' : 'Basic Land — Plains', 'color_identity' => $identity,
        ];
    }
}
