<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\ResetPasswordNotification;
use Aws\Ses\SesClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_gives_the_same_answer_for_existing_and_unknown_emails(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'jace@example.com']);

        $this->get(route('password.request'))->assertOk()->assertSee('Esqueceu a senha?');
        $known = $this->post(route('password.email'), ['email' => 'jace@example.com'])->assertSessionHasNoErrors();
        $unknown = $this->post(route('password.email'), ['email' => 'ninguem@example.com'])->assertSessionHasNoErrors();

        $this->assertSame($known->getSession()->get('status'), $unknown->getSession()->get('status'));
        Notification::assertSentTo($user, ResetPasswordNotification::class);
        Notification::assertSentTimes(ResetPasswordNotification::class, 1);
    }

    public function test_reset_email_has_portuguese_copy_and_valid_link(): void
    {
        Notification::fake();
        $user = User::factory()->create(['name' => 'Chandra']);
        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user): bool {
            $mail = $notification->toMail($user);
            $this->assertSame('Redefinição de senha — ManaLeague', $mail->subject);
            $this->assertStringContainsString('/reset-password/'.$notification->token, $mail->actionUrl);
            $this->assertStringContainsString('email='.urlencode($user->email), $mail->actionUrl);
            $this->assertStringContainsString('Olá, Chandra!', $mail->greeting);
            return true;
        });
    }

    public function test_user_can_reset_password_and_old_sessions_are_revoked(): void
    {
        Notification::fake();
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'sessao-antiga', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $token = Password::createToken($user);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertOk()->assertSee('Criar nova senha');
        $this->post(route('password.update'), [
            'token' => $token, 'email' => $user->email,
            'password' => 'novaSenha123', 'password_confirmation' => 'novaSenha123',
        ])->assertRedirect(route('login'))->assertSessionHas('status');

        $this->assertTrue(Hash::check('novaSenha123', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'sessao-antiga']);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::assertSentTo($user, PasswordChangedNotification::class);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'novaSenha123'])->assertRedirect(route('dashboard'));
    }

    public function test_reset_token_cannot_be_reused_or_forged(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $payload = ['email' => $user->email, 'password' => 'novaSenha123', 'password_confirmation' => 'novaSenha123'];

        $this->post(route('password.update'), [...$payload, 'token' => 'token-forjado'])->assertSessionHasErrors('email');
        $this->post(route('password.update'), [...$payload, 'token' => $token])->assertRedirect(route('login'));
        $this->post(route('password.update'), [...$payload, 'token' => $token, 'password' => 'outraSenha456', 'password_confirmation' => 'outraSenha456'])
            ->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('novaSenha123', $user->fresh()->password));
    }

    public function test_reset_requires_strong_password(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'curta', 'password_confirmation' => 'curta'])
            ->assertSessionHasErrors('password');
    }

    public function test_logged_user_can_change_password_and_keeps_current_session(): void
    {
        Notification::fake();
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['password' => 'senhaAtual123']);
        DB::table('sessions')->insert(['id' => 'outro-dispositivo', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($user)->put(route('password.change'), [
            'current_password' => 'errada000', 'password' => 'novaSenha123', 'password_confirmation' => 'novaSenha123',
        ])->assertSessionHasErrorsIn('password', 'current_password');

        $this->actingAs($user)->put(route('password.change'), [
            'current_password' => 'senhaAtual123', 'password' => 'novaSenha123', 'password_confirmation' => 'novaSenha123',
        ])->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->assertTrue(Hash::check('novaSenha123', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'outro-dispositivo']);
        Notification::assertSentTo($user, PasswordChangedNotification::class);
    }

    public function test_login_page_links_to_password_recovery(): void
    {
        $this->get(route('login'))->assertOk()->assertSee(route('password.request'));
    }

    public function test_mail_provider_outage_never_breaks_password_flows(): void
    {
        // SMTP apontando para porta fechada: simula SES indisponivel / sem permissao.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);
        $user = User::factory()->create(['password' => 'senhaAtual123']);

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect()->assertSessionHas('status')->assertSessionHasNoErrors();

        $token = Password::createToken($user);
        $this->post(route('password.update'), [
            'token' => $token, 'email' => $user->email, 'password' => 'novaSenha123', 'password_confirmation' => 'novaSenha123',
        ])->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('novaSenha123', $user->fresh()->password));

        $this->actingAs($user->fresh())->put(route('password.change'), [
            'current_password' => 'novaSenha123', 'password' => 'outraSenha456', 'password_confirmation' => 'outraSenha456',
        ])->assertRedirect()->assertSessionHas('status');
        $this->assertTrue(Hash::check('outraSenha456', $user->fresh()->password));
    }

    public function test_ses_mailer_uses_role_credentials_and_configuration_set(): void
    {
        config([
            'services.ses' => ['key' => null, 'secret' => null, 'region' => 'us-east-1', 'options' => ['ConfigurationSetName' => 'manaleague-transactional']],
        ]);

        $transport = app('mail.manager')->createSymfonyTransport(['transport' => 'ses']);

        $this->assertInstanceOf(\Illuminate\Mail\Transport\SesTransport::class, $transport);
        $this->assertInstanceOf(SesClient::class, $transport->ses());
        $this->assertSame('us-east-1', $transport->ses()->getRegion());
        $this->assertSame(['ConfigurationSetName' => 'manaleague-transactional'], $transport->getOptions());
        $this->assertSame('nao-responda@manaleague.com.br', config('mail.from.address'));
    }
}
