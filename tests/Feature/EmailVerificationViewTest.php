<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A confirmacao orienta o usuario sem oferecer recursos privados ainda bloqueados. */
class EmailVerificationViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_account_sees_email_resend_and_logout_without_private_navigation(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($user)->get('/email/verify')
            ->assertOk()
            ->assertSee('Confirme seu e-mail')
            ->assertSee($user->email)
            ->assertSee('Reenviar e-mail')
            ->assertSee('Já confirmei meu e-mail')
            ->assertSee('Sair da conta')
            ->assertSee('name="_token"', false)
            ->assertDontSee('site-nav-link', false)
            ->assertDontSee('id="live-user"', false);
    }
}
