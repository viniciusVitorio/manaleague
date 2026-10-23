<?php
namespace Tests\Feature;
use App\Models\{Deck, GameMatch, PlayerProfile, Tournament, User};
use App\Services\{AchievementService, ChallengeService};
use App\Services\Decks\DeckVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
class SecretAchievementsTest extends TestCase {
    use RefreshDatabase;
    public function test_secret_is_hidden_until_real_confirmed_comeback(): void {
        $a = User::factory()->create(); $b = User::factory()->create();
        $pa = $a->ensurePlayerProfile(); $pb = $b->ensurePlayerProfile();
        $a->friendProfiles()->attach($pb, ['status' => 'accepted']);
        $catalog = app(AchievementService::class)->catalog($pa);
        $hidden = $catalog->firstWhere('key', 'secret_comeback');
        $this->assertNotNull($hidden);
        $this->assertSame('Conquista secreta', $hidden['name']);
        $this->assertSame(0, $hidden['current']);
        $s = app(ChallengeService::class);
        $c = $s->create($a, $pb, 'Pauper', 3, now()->addDay()); $s->transition($c, $b, 'accept');
        $s->report($c, $a, 2, 1, [2, 1, 1]);
        $this->assertNotContains('secret_comeback', app(AchievementService::class)->sync($pa));
        $s->review($c, $b, 'confirm', 1);
        $this->assertTrue($pa->achievements()->where('achievement', 'secret_comeback')->exists());
        $this->assertFalse($pb->achievements()->where('achievement', 'secret_comeback')->exists());
        $this->assertFalse($pa->achievements()->where('achievement', 'first_win')->exists(), 'Casual wins must not inflate tournament metrics');
        $this->assertSame('De virada', app(AchievementService::class)->catalog($pa)->firstWhere('key', 'secret_comeback')['name']);
    }
    public function test_tribal_requires_real_creature_types_not_a_deck_name(): void {
        $p = User::factory()->create()->ensurePlayerProfile();
        $d = $p->decks()->create(['name' => 'Elfos tribal', 'format' => 'Pauper']);
        $d->cards()->create(['scryfall_id' => (string) Str::uuid(), 'name' => 'Forest', 'quantity' => 20, 'board' => 'main', 'type_line' => 'Basic Land — Forest', 'cmc' => 0]);
        [$t, $me, $opponent] = $this->tournamentWith($p, $d);
        $this->match($t, $me, $opponent, 2, 0);
        $this->assertNotContains('secret_tribal', app(AchievementService::class)->sync($p), 'Nome do deck e terrenos não contam');
    }

    public function test_tribal_requires_a_tournament_win_with_the_tribal_deck(): void {
        $p = User::factory()->create()->ensurePlayerProfile();
        $d = $p->decks()->create(['name' => 'Elfos', 'format' => 'Pauper']);
        $d->cards()->create(['scryfall_id' => (string) Str::uuid(), 'name' => 'Elvish Mystic', 'quantity' => 20, 'board' => 'main', 'type_line' => 'Creature — Elf Druid', 'cmc' => 1]);
        $this->assertNotContains('secret_tribal', app(AchievementService::class)->sync($p), 'Só montar o deck não basta');

        [$t, $me, $opponent] = $this->tournamentWith($p, $d);
        $this->match($t, $me, $opponent, 0, 2);
        $this->assertNotContains('secret_tribal', app(AchievementService::class)->sync($p), 'Derrota não conta');
        $t->matches()->create(['round' => 9, 'stage' => GameMatch::STAGE_LEAGUE, 'player_one_id' => $me->id, 'player_one_games' => 2, 'player_two_games' => 0, 'status' => GameMatch::STATUS_BYE]);
        $this->assertNotContains('secret_tribal', app(AchievementService::class)->sync($p), 'BYE não conta');

        $this->match($t, $me, $opponent, 2, 1);
        $this->assertContains('secret_tribal', app(AchievementService::class)->sync($p));
        $this->assertNotContains('secret_tribal', app(AchievementService::class)->sync($p));
    }

    public function test_tribal_uses_the_deck_as_registered_in_the_tournament(): void {
        $p = User::factory()->create()->ensurePlayerProfile();
        $d = $p->decks()->create(['name' => 'Elfos', 'format' => 'Pauper']);
        $elves = $d->cards()->create(['scryfall_id' => (string) Str::uuid(), 'name' => 'Elvish Mystic', 'quantity' => 19, 'board' => 'main', 'type_line' => 'Creature — Elf Druid', 'cmc' => 1]);
        $d->cards()->create(['scryfall_id' => (string) Str::uuid(), 'name' => 'Elvish Mystic', 'quantity' => 4, 'board' => 'side', 'type_line' => 'Creature — Elf Druid', 'cmc' => 1]);
        [$t, $me, $opponent] = $this->tournamentWith($p, $d);

        // Completar o deck depois da inscrição não vale para este torneio (e o sideboard nunca conta).
        $elves->update(['quantity' => 20]);
        $this->match($t, $me, $opponent, 2, 0);
        $this->assertNotContains('secret_tribal', app(AchievementService::class)->sync($p));
    }

    /** Torneio com o perfil inscrito usando o deck, e a foto do deck tirada (como no início do torneio). */
    private function tournamentWith(PlayerProfile $profile, Deck $deck): array {
        $t = User::factory()->create()->tournaments()->create([
            'name' => 'Liga', 'format' => 'Pauper', 'tournament_date' => '2026-10-10',
            'public_token' => Str::random(40), 'public_slug' => 'liga-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40),
        ]);
        $me = $t->players()->create(['name' => $profile->nickname, 'player_profile_id' => $profile->id, 'deck_id' => $deck->id]);
        $opponent = $t->players()->create(['name' => 'Oponente']);
        app(DeckVersionService::class)->snapshotForTournament($t);
        return [$t, $me, $opponent];
    }

    private function match(Tournament $t, $me, $opponent, int $mine, int $theirs): void {
        $t->matches()->create(['round' => (int) $t->matches()->max('round') + 1, 'stage' => GameMatch::STAGE_LEAGUE,
            'player_one_id' => $me->id, 'player_two_id' => $opponent->id, 'player_one_games' => $mine, 'player_two_games' => $theirs,
            'status' => GameMatch::STATUS_FINISHED]);
    }
}
