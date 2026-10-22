<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Vini',
            'email' => 'vini@example.com',
            'password' => 'Secure123',
            'password_confirmation' => 'Secure123',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'vini@example.com']);
    }

    public function test_user_can_login_and_logout(): void
    {
        $user = User::factory()->create(['password' => 'Secure123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'Secure123'])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_login_without_remember_does_not_set_remember_cookie(): void
    {
        $user = User::factory()->create(['password' => 'Secure123']);

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'Secure123']);

        $response->assertRedirect('/dashboard');
        $this->assertNull($this->rememberCookie($response));
    }

    public function test_login_with_remember_sets_long_lived_cookie(): void
    {
        $user = User::factory()->create(['password' => 'Secure123']);

        $response = $this->post('/login', [
            'email' => $user->email, 'password' => 'Secure123', 'remember' => '1',
        ]);

        $response->assertRedirect('/dashboard');
        $cookie = $this->rememberCookie($response);
        $this->assertNotNull($cookie);
        $days = ($cookie->getExpiresTime() - time()) / 86400;
        $this->assertEqualsWithDelta(365, $days, 1);
    }

    public function test_remember_cookie_restores_login_after_session_expires(): void
    {
        $user = User::factory()->create(['password' => 'Secure123']);

        $cookie = $this->rememberCookie($this->post('/login', [
            'email' => $user->email, 'password' => 'Secure123', 'remember' => '1',
        ]));

        // Simula a sessão expirada: esquece o usuário em memória e envia só o cookie de lembrança
        // (o valor já vem criptografado na resposta, por isso "unencrypted").
        $this->app['auth']->forgetGuards();

        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
            ->get('/dashboard')
            ->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    private function rememberCookie($response): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if (str_starts_with($cookie->getName(), 'remember_web_')) {
                return $cookie;
            }
        }
        return null;
    }
}
