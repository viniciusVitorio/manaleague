<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;
use Throwable;

class PasswordController extends Controller
{
    private const GENERIC_SENT = 'Se existir uma conta com este e-mail, enviamos um link para criar uma nova senha. Confira também o spam.';

    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);

        try {
            $status = Password::sendResetLink($request->only('email'));
        } catch (Throwable $exception) {
            // Falha do provedor de e-mail nao pode virar erro 500 nem revelar se a conta existe.
            $status = 'mail_failed';
            Log::error('mail.send_failed', ['context' => 'password_reset_link', 'error' => $exception->getMessage()]);
        }

        // Mesma resposta para e-mail existente, inexistente ou em cooldown: impede enumeracao de contas.
        Log::info('auth.password_reset_requested', ['status' => $status, 'ip' => $request->ip()]);

        return back()->with('status', self::GENERIC_SENT);
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($request): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $this->revokeSessions($user);
                event(new PasswordReset($user));
                $this->notifyPasswordChanged($user, $request->ip());
                Log::info('auth.password_reset', ['user_id' => $user->id, 'ip' => $request->ip()]);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => 'Este link de redefinição é inválido ou expirou. Peça um novo.']);
        }

        return redirect()->route('login')->with('status', 'Senha redefinida. Entre com a nova senha.');
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('password', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $user = $request->user();
        $user->forceFill(['password' => $validated['password'], 'remember_token' => Str::random(60)])->save();
        $this->revokeSessions($user, except: $request->session()->getId());
        $this->notifyPasswordChanged($user, $request->ip());
        Log::info('auth.password_changed', ['user_id' => $user->id, 'ip' => $request->ip()]);

        return back()->with('status', 'Senha alterada. As outras sessões foram encerradas.');
    }

    /** O aviso e importante, mas a senha ja foi trocada: falha no envio so e registrada. */
    private function notifyPasswordChanged(User $user, ?string $ip): void
    {
        try {
            $user->notify(new PasswordChangedNotification($ip));
        } catch (Throwable $exception) {
            Log::error('mail.send_failed', ['context' => 'password_changed', 'user_id' => $user->id, 'error' => $exception->getMessage()]);
        }
    }

    /** Encerra sessoes persistidas do usuario (driver database), opcionalmente mantendo a atual. */
    private function revokeSessions(User $user, ?string $except = null): void
    {
        if (config('session.driver') !== 'database') return;

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->when($except, fn ($query) => $query->where('id', '!=', $except))
            ->delete();
    }
}
