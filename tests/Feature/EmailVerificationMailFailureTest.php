<?php

namespace Tests\Feature;

use App\Models\User;
use Aws\Exception\CredentialsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Falha de credenciais do SES tambem preserva a conta e o estado pendente. */
class EmailVerificationMailFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_ses_credentials_failure_does_not_break_registration_or_expose_provider_details(): void
    {
        Notification::shouldReceive('send')->once()->andThrow(new CredentialsException('sensitive provider context'));
        Log::spy();

        $this->post('/register', [
            'name' => 'Mail Failure', 'email' => 'mail-failure@example.test',
            'password' => 'Secure123', 'password_confirmation' => 'Secure123',
        ])->assertRedirect('/email/verify')->assertSessionHasErrors('email')->assertSessionMissing('status');

        $user = User::firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->email_verified_at);
        $this->get('/dashboard')->assertRedirect('/email/verify');
        Log::shouldHaveReceived('warning')->once()->with('auth.verification_mail_failed', [
            'user_id' => $user->id, 'exception' => CredentialsException::class,
        ]);
    }
}
