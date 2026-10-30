<?php

namespace Tests\Feature;

use App\Models\Tournament;
use App\Models\User;
use App\Services\AchievementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_every_product_area_and_signup_actions(): void
    {
        $response = $this->get('/')->assertOk();

        $response->assertSee('Seu Magic inteiro', false)
            ->assertSee(route('register'), false)
            ->assertSee(route('login'), false)
            ->assertSee('css/landing.css', false)
            ->assertDontSee(route('dashboard'), false)
            ->assertDontSee('@else', false)
            ->assertDontSee('@endauth', false)
            ->assertDontSee('@include', false);

        // A landing nao e so de torneios: cada area do produto tem a sua secao, com ancora no menu.
        foreach (['decks', 'colecao', 'mesa', 'ranked', 'torneios', 'comunidade'] as $anchor) {
            $response->assertSee('id="'.$anchor.'"', false)->assertSee('href="#'.$anchor.'"', false);
        }
        foreach (['Simulador de mão', 'Fichário de trocas', 'Contador de vida', 'Rivalidades', 'Modo TV', 'Clubes', 'Política de Conteúdo de Fãs'] as $text) {
            $response->assertSee($text);
        }
        foreach (Tournament::SYSTEMS as $label) {
            $response->assertSee($label);
        }
        foreach (config('ranked.formats') as $format) {
            $response->assertSee($format);
        }
        $response->assertSee(count(AchievementService::definitions()).' conquistas');
    }

    public function test_landing_does_not_advertise_free_pricing(): void
    {
        $html = mb_strtolower($this->get('/')->assertOk()->getContent());

        $this->assertStringNotContainsString('grátis', $html);
        $this->assertStringNotContainsString('gratuito', $html);
    }

    public function test_signed_in_user_gets_app_shortcuts_instead_of_signup(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee(route('dashboard'), false)
            ->assertSee(route('decks.index'), false)
            ->assertSee(route('challenges.index'), false)
            ->assertDontSee('Já tenho conta')
            ->assertDontSee('@else', false);
    }
}
