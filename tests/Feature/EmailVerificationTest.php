<?php

namespace Tests\Feature;

use App\Http\Middleware\Authenticate;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function pending(): User
    {
        return User::factory()->create(['email_verified_at' => null]);
    }

    public function test_pending_users_are_blocked_centrally_including_modules_and_broadcasting(): void
    {
        $this->actingAs($this->pending());
        foreach (['/dashboard', '/me/decks', '/me/profile', '/me/friends'] as $url) {
            $this->get($url)->assertRedirect('/email/verify');
            $this->getJson($url)->assertForbidden();
        }
        $this->postJson('/broadcasting/auth', ['channel_name' => 'private-user.1', 'socket_id' => '1.1'])->assertForbidden();
        $this->withHeaders(['X-ManaLeague-Async' => '1', 'Accept' => 'application/json'])
            ->post('/me/decks', [])->assertOk()->assertJson(['redirect' => url('/email/verify')]);
    }

    public function test_pending_login_ignores_intended_private_destination(): void
    {
        $user = $this->pending();
        $this->withSession(['url.intended' => url('/me/decks')])
            ->post('/login', ['email' => $user->email, 'password' => 'Password123'])->assertRedirect('/email/verify');
    }

    public function test_legacy_accounts_keep_access_without_a_false_verified_timestamp(): void
    {
        $user = User::factory()->create(['email_verified_at' => null, 'email_verification_required' => false]);
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_registration_consumes_resend_cooldown_then_authenticated_resend_works(): void
    {
        Notification::fake();
        $this->post('/register', $this->registration())->assertRedirect('/email/verify');
        $this->post('/email/verification-notification')->assertStatus(429);
        Notification::assertSentTimes(VerifyEmailNotification::class, 1);
        $this->travel(61)->seconds();
        $this->post('/email/verification-notification')->assertRedirect('/email/verify')->assertSessionHas('status');
        Notification::assertSentTimes(VerifyEmailNotification::class, 2);
        $this->post('/email/verification-notification')->assertStatus(429);
    }

    public function test_real_mail_transport_failure_preserves_account_and_allows_later_retry(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp' => [
            'transport' => 'smtp', 'scheme' => 'smtp', 'host' => '127.0.0.1', 'port' => 1,
            'username' => null, 'password' => null, 'timeout' => 1,
        ]]);
        Log::spy();
        $this->post('/register', $this->registration())->assertRedirect('/email/verify')
            ->assertSessionHasErrors('email')->assertSessionMissing('status');
        $user = User::firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->playerProfile);
        $this->assertNull($user->email_verified_at);
        $this->travel(61)->seconds();
        $this->post('/email/verification-notification')->assertRedirect('/email/verify')
            ->assertSessionHasErrors('email')->assertSessionMissing('status');
        Log::shouldHaveReceived('warning')->with('auth.verification_mail_failed', ['user_id' => $user->id, 'exception' => TransportException::class])->twice();
        Notification::fake();
        $this->travel(61)->seconds();
        $this->post('/email/verification-notification')->assertRedirect('/email/verify')->assertSessionHas('status');
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_guests_cannot_access_verification_endpoints(): void
    {
        $this->get('/email/verify')->assertRedirect('/login');
        $this->post('/email/verification-notification')->assertRedirect('/login');
        $this->get('/email/verify/1/hash')->assertRedirect('/login');
    }

    public function test_signed_link_verifies_once_and_rejects_invalid_expired_or_wrong_user_links(): void
    {
        Event::fake([Verified::class]);
        $user = $this->pending();
        $url = (new VerifyEmailNotification)->toMail($user)->actionUrl;
        $this->actingAs($this->pending())->get($url)->assertForbidden();
        $this->actingAs($user)->get($url.'&tampered=1')->assertForbidden();
        $wrongHash = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('wrong')]);
        $this->get($wrongHash)->assertForbidden();
        $this->travel(61)->minutes();
        $this->get($url)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
        $url = (new VerifyEmailNotification)->toMail($user)->actionUrl;
        $this->get($url)->assertRedirect('/dashboard');
        $timestamp = $user->fresh()->email_verified_at;
        $this->get($url)->assertRedirect('/dashboard');
        $this->assertEquals($timestamp, $user->fresh()->email_verified_at);
        Event::assertDispatchedTimes(Verified::class, 1);
        $this->get('/dashboard')->assertOk();
    }

    public function test_pending_user_can_logout_and_request_password_reset(): void
    {
        Notification::fake();
        $user = $this->pending();
        $this->actingAs($user)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/forgot-password')->assertOk();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPasswordNotification::class);
        $notification = Notification::sent($user, ResetPasswordNotification::class)->first();
        $this->get(route('password.reset', ['token' => $notification->token, 'email' => $user->email]))->assertOk();
        $this->post('/reset-password', [
            'token' => $notification->token, 'email' => $user->email,
            'password' => 'Changed456', 'password_confirmation' => 'Changed456',
        ])->assertRedirect('/login');
        $this->post('/login', ['email' => $user->email, 'password' => 'Changed456'])->assertRedirect('/email/verify');
        $this->assertNull($user->fresh()->email_verified_at);
        $this->get('/dashboard')->assertRedirect('/email/verify');
    }

    public function test_forward_migration_exempts_existing_accounts_without_changing_verified_timestamps(): void
    {
        $migration = require database_path('migrations/2026_11_09_000001_add_email_verification_required_to_users.php');
        $migration->down();
        $verifiedAt = '2026-01-01 12:00:00';
        DB::table('users')->insert([
            ['name' => 'Legacy', 'email' => 'legacy@example.com', 'password' => 'hash', 'email_verified_at' => null],
            ['name' => 'Verified', 'email' => 'verified@example.com', 'password' => 'hash', 'email_verified_at' => $verifiedAt],
        ]);
        $migration->up();
        $this->assertDatabaseHas('users', ['email' => 'legacy@example.com', 'email_verification_required' => false, 'email_verified_at' => null]);
        $this->assertDatabaseHas('users', ['email' => 'verified@example.com', 'email_verification_required' => false, 'email_verified_at' => $verifiedAt]);
        $id = DB::table('users')->insertGetId(['name' => 'New', 'email' => 'new@example.com', 'password' => 'hash']);
        $this->assertTrue(User::findOrFail($id)->email_verification_required);
    }

    public function test_flag_is_not_mass_assignable_and_verified_users_do_not_receive_another_email(): void
    {
        $user = User::create(['name' => 'Forged', 'email' => 'forged@example.com', 'password' => 'Secure123', 'email_verification_required' => false]);
        $this->assertTrue($user->fresh()->email_verification_required);
        $user->markEmailAsVerified();
        Notification::fake();
        $this->actingAs($user)->post('/email/verification-notification')->assertRedirect('/dashboard');
        $this->get('/email/verify')->assertRedirect('/dashboard');
        Notification::assertNothingSent();
    }

    public function test_every_auth_route_resolves_to_the_composite_verification_gate(): void
    {
        $this->get('/login')->assertOk(); // Boot the HTTP kernel and its aliases.
        $routes = collect(Route::getRoutes()->getRoutes())->filter(
            fn ($route) => in_array('auth', $route->gatherMiddleware(), true)
        );
        $this->assertGreaterThan(100, $routes->count());
        foreach ($routes as $route) {
            $middleware = app('router')->gatherRouteMiddleware($route);
            $this->assertContains(Authenticate::class, $middleware, $route->uri());
        }
        $broadcast = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === 'broadcasting/auth');
        $this->assertNotNull($broadcast);
        $this->assertContains(Authenticate::class, app('router')->gatherRouteMiddleware($broadcast));
    }

    public function test_guest_signed_link_survives_login_and_finishes_verification(): void
    {
        $user = $this->pending();
        $url = (new VerifyEmailNotification)->toMail($user)->actionUrl;
        $this->get($url)->assertRedirect('/login');
        $this->assertSame($url, session('url.intended'));
        $this->post('/login', ['email' => $user->email, 'password' => 'Password123'])->assertRedirect($url);
        $this->get($url)->assertRedirect('/dashboard');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    private function registration(): array
    {
        return ['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'Secure123', 'password_confirmation' => 'Secure123', 'email_verification_required' => false];
    }

    public function test_registration_requires_verification_and_sends_a_signed_portuguese_notification(): void
    {
        Notification::fake();
        $this->post('/register', $this->registration())->assertRedirect('/email/verify')->assertSessionHas('status');
        $user = User::firstOrFail();
        $this->assertTrue($user->email_verification_required);
        $this->assertNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->playerProfile);
        Notification::assertSentTo($user, VerifyEmailNotification::class, function ($notification) use ($user) {
            $mail = $notification->toMail($user);
            $this->assertSame('Confirmar e-mail', $mail->actionText);
            parse_str(parse_url($mail->actionUrl, PHP_URL_QUERY), $query);
            $this->assertEquals(now()->addMinutes(60)->timestamp, $query['expires']);
            $request = Request::create($mail->actionUrl);
            $this->assertTrue(URL::hasValidSignature($request));

            return true;
        });
    }
}
