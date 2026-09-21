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

        $response->assertRedirect('/tournaments');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'vini@example.com']);
    }

    public function test_user_can_login_and_logout(): void
    {
        $user = User::factory()->create(['password' => 'Secure123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'Secure123'])
            ->assertRedirect('/tournaments');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}
