<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dark_theme_is_the_default(): void
    {
        $this->get('/login')->assertOk()->assertSee('data-theme="dark"', false);
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()->assertSee('data-theme="dark"', false);
    }

    public function test_user_saves_theme_and_deck_counter_preference(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/me/profile/edit')->assertOk()->assertSee('Preferências')->assertSee('Tema');
        $this->patch('/me/preferences', ['theme' => 'light', 'show_deck_ownership' => 0])
            ->assertRedirect()->assertSessionHas('status', 'Preferências salvas.')->assertCookie('ml_theme', 'light');

        $user->refresh();
        $this->assertSame('light', $user->theme);
        $this->assertFalse($user->show_deck_ownership);
        $this->actingAs($user)->get('/dashboard')->assertSee('data-theme="light"', false)->assertSee('Mudar para o tema escuro');

        $this->patch('/me/preferences', ['theme' => 'neon'])->assertSessionHasErrors('theme');
    }

    public function test_menu_shortcut_toggles_theme(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patch('/me/preferences/theme')->assertRedirect()->assertCookie('ml_theme', 'light');
        $this->assertSame('light', $user->fresh()->theme);
        $this->actingAs($user->fresh())->patch('/me/preferences/theme');
        $this->assertSame('dark', $user->fresh()->theme);
    }

    public function test_logged_out_pages_follow_the_theme_cookie(): void
    {
        $this->withCookie('ml_theme', 'light')->get('/login')->assertSee('data-theme="light"', false);
        $this->withCookie('ml_theme', 'qualquer')->get('/login')->assertSee('data-theme="dark"', false);
    }

    public function test_preferences_require_login(): void
    {
        $this->patch('/me/preferences', ['theme' => 'light'])->assertRedirect('/login');
    }
}
