<?php

namespace Tests\Feature;

use App\Models\CommunityNotification;
use App\Models\User;
use App\Services\AchievementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BuilderAchievementTest extends TestCase
{
    use RefreshDatabase;

    private function friends(User $a, User $b): void
    {
        $a->friendProfiles()->attach($b->ensurePlayerProfile(), ['status' => 'accepted']);
        $b->friendProfiles()->attach($a->ensurePlayerProfile(), ['status' => 'accepted']);
    }

    public function test_playing_never_unlocks_the_builder_achievement(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensurePlayerProfile();
        app(AchievementService::class)->sync($profile);

        $this->assertFalse($profile->achievements()->where('achievement', 'builder')->exists());
        $badge = app(AchievementService::class)->catalog($profile)->firstWhere('key', 'builder');
        $this->assertSame(['Construtor da Liga', 'special', false, true], [$badge['name'], $badge['category'], $badge['unlocked'], $badge['granted']]);
        $this->actingAs($user)->get('/me/profile')->assertOk()->assertSee('Especial')->assertSee('Construtor da Liga')
            ->assertSee('Concedida a quem ajuda a construir o ManaLeague')->assertDontSee('Conceder Construtor da Liga');
    }

    public function test_console_command_grants_once_notifies_and_revokes(): void
    {
        $user = User::factory()->create(['email' => 'amigo@example.com']);

        $this->artisan('ml:construtor', ['email' => 'amigo@example.com'])->expectsOutputToContain('agora é Construtor da Liga')->assertSuccessful();
        $this->artisan('ml:construtor', ['email' => 'amigo@example.com'])->expectsOutputToContain('já tinha a conquista')->assertSuccessful();
        $this->assertSame(1, $user->ensurePlayerProfile()->achievements()->where('achievement', 'builder')->count());
        $this->assertSame(1, CommunityNotification::where('user_id', $user->id)->where('type', 'achievement')->count());
        $this->actingAs($user)->get('/me/profile')->assertOk()->assertSee('Concedida a quem ajuda a construir o ManaLeague')->assertDontSee('código, testes');

        $this->artisan('ml:construtor', ['email' => 'amigo@example.com', '--revoke' => true])->expectsOutputToContain('removida')->assertSuccessful();
        $this->assertFalse($user->ensurePlayerProfile()->achievements()->where('achievement', 'builder')->exists());
        $this->artisan('ml:construtor', ['email' => 'nao-existe@example.com'])->assertFailed();
    }

    public function test_only_admins_grant_from_the_profile_page(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $friend = User::factory()->create();
        $this->friends($admin, $friend);
        $profile = $friend->ensurePlayerProfile();

        $this->actingAs($friend)->post(route('profiles.builder', $profile))->assertForbidden();
        $this->assertFalse($profile->achievements()->where('achievement', 'builder')->exists());

        $this->actingAs($admin)->get(route('profiles.show', $profile))->assertOk()->assertSee('Conceder Construtor da Liga');
        $this->actingAs($admin)->post(route('profiles.builder', $profile))->assertRedirect()->assertSessionHas('status');
        $this->assertTrue($profile->achievements()->where('achievement', 'builder')->exists());
        $this->actingAs($admin)->get(route('profiles.show', $profile))->assertSee('Remover Construtor da Liga');

        $this->actingAs($admin)->post(route('profiles.builder', $profile), ['revoke' => 1])->assertRedirect();
        $this->assertFalse($profile->achievements()->where('achievement', 'builder')->exists());
    }

    public function test_regular_achievements_cannot_be_granted_by_hand(): void
    {
        $profile = User::factory()->create()->ensurePlayerProfile();
        $this->expectException(HttpException::class);
        app(AchievementService::class)->grant($profile, 'champion');
    }
}
