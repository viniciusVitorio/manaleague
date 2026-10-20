<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\{AchievementService, ChallengeService};
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
        $this->assertNotContains('secret_tribal', app(AchievementService::class)->sync($p));
        $d->cards()->create(['scryfall_id' => (string) Str::uuid(), 'name' => 'Forest', 'quantity' => 20, 'board' => 'main', 'type_line' => 'Basic Land — Forest', 'cmc' => 0]);
        $this->assertNotContains('secret_tribal', app(AchievementService::class)->sync($p));
        $d->cards()->create(['scryfall_id' => (string) Str::uuid(), 'name' => 'Elvish Mystic', 'quantity' => 20, 'board' => 'main', 'type_line' => 'Creature — Elf Druid', 'cmc' => 1]);
        $this->assertContains('secret_tribal', app(AchievementService::class)->sync($p));
        $this->assertNotContains('secret_tribal', app(AchievementService::class)->sync($p));
    }
}
