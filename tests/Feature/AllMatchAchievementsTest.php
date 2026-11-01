<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\PlayerProfile;
use App\Models\RankedSeason;
use App\Models\Tournament;
use App\Models\User;
use App\Services\AchievementService;
use App\Services\ChallengeService;
use App\Services\Ranked\RankedMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Conquistas de partida valem para torneio, desafio casual e Ranked. */
class AllMatchAchievementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_casual_md3_two_zero_unlocks_clean_sweep_and_first_blood_right_away(): void
    {
        [$ana, $bia] = $this->friends();
        $this->casual($ana, $bia, 3, 2, 0);

        // Liberadas na confirmação, sem precisar abrir o perfil.
        $this->assertTrue($this->has($ana, 'clean_sweep'));
        $this->assertTrue($this->has($ana, 'first_win'));
        $this->assertTrue($this->has($bia, 'zero_two'));
        $this->assertFalse($this->has($bia, 'clean_sweep'));
    }

    public function test_md1_win_and_two_one_win_are_not_clean_sweeps(): void
    {
        [$ana, $bia] = $this->friends();
        $this->casual($ana, $bia, 1, 1, 0);
        $this->casual($ana, $bia, 3, 2, 1);

        $this->assertTrue($this->has($ana, 'first_win'));
        $this->assertFalse($this->has($ana, 'clean_sweep'));
        $this->assertFalse($this->has($bia, 'zero_two'));
    }

    public function test_ranked_confirmation_unlocks_match_achievements(): void
    {
        [$ana, $bia] = $this->friends();
        $season = RankedSeason::create(['name' => 'Temporada', 'slug' => 'temporada-'.Str::random(5), 'status' => 'active', 'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(),
            'scope' => 'global', 'rules_version' => 'elo-v2']);
        $season->formats()->create(['format' => 'Pauper', 'k_factor' => 32, 'starting_rating' => 600, 'allow_draws' => true]);
        $service = app(RankedMatchService::class);
        $match = $service->create($season->fresh('formats'), 'Pauper', $ana->playerProfile, $bia->playerProfile, 'challenge', 77, 3);
        $service->submit($match, $bia, 0, 2, (string) Str::uuid());
        $service->confirm($match, $ana);

        $this->assertTrue($this->has($bia, 'clean_sweep'));
        $this->assertTrue($this->has($bia, 'first_win'));
        $this->assertTrue($this->has($ana, 'zero_two'));
    }

    public function test_win_streak_mixes_tournament_and_challenges(): void
    {
        [$ana, $bia] = $this->friends();
        $this->travelTo(now()->subDays(2));
        $tournament = $ana->tournaments()->create(['name' => 'Liga', 'format' => 'Pauper', 'tournament_date' => now()->toDateString(), 'status' => Tournament::STATUS_LEAGUE,
            'public_token' => Str::random(40), 'public_slug' => 'liga-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40)]);
        $one = $tournament->players()->create(['name' => 'Ana', 'player_profile_id' => $ana->playerProfile->id]);
        $two = $tournament->players()->create(['name' => 'Bia', 'player_profile_id' => $bia->playerProfile->id]);
        $tournament->matches()->create(['round' => 1, 'stage' => GameMatch::STAGE_LEAGUE, 'player_one_id' => $one->id, 'player_two_id' => $two->id,
            'player_one_games' => 2, 'player_two_games' => 1, 'status' => GameMatch::STATUS_FINISHED, 'played_at' => now()]);
        $this->travelBack();

        $this->casual($ana, $bia, 3, 2, 1);
        $this->assertFalse($this->has($ana, 'on_fire'));
        $this->casual($ana, $bia, 1, 1, 0);
        $this->assertTrue($this->has($ana, 'on_fire'), 'Torneio + 2 desafios = 3 vitórias seguidas');
    }

    public function test_bye_is_not_a_clean_sweep(): void
    {
        [$ana] = $this->friends();
        $tournament = $ana->tournaments()->create(['name' => 'Liga', 'format' => 'Pauper', 'tournament_date' => now()->toDateString(), 'status' => Tournament::STATUS_LEAGUE,
            'public_token' => Str::random(40), 'public_slug' => 'liga-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40)]);
        $entry = $tournament->players()->create(['name' => 'Ana', 'player_profile_id' => $ana->playerProfile->id]);
        $tournament->matches()->create(['round' => 1, 'stage' => GameMatch::STAGE_LEAGUE, 'player_one_id' => $entry->id,
            'player_one_games' => 2, 'player_two_games' => 0, 'status' => GameMatch::STATUS_BYE]);

        app(AchievementService::class)->sync($ana->playerProfile->fresh());
        $this->assertTrue($this->has($ana, 'bye'));
        $this->assertFalse($this->has($ana, 'clean_sweep'));
    }

    private function casual(User $a, User $b, int $bestOf, int $one, int $two): void
    {
        $service = app(ChallengeService::class);
        $challenge = $service->create($a, $b->playerProfile, 'Pauper', $bestOf, now()->addDay());
        $service->transition($challenge, $b, 'accept');
        $match = $service->report($challenge, $a, $one, $two);
        $service->review($challenge, $b, 'confirm', $match->revision);
    }

    private function has(User $user, string $key): bool
    {
        return PlayerProfile::query()->where('account_user_id', $user->id)->sole()->achievements()->where('achievement', $key)->exists();
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
