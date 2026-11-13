<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\EmailVerificationSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function __construct(private readonly EmailVerificationSender $verificationSender) {}

    public function showLogin(): View { return view('auth.login'); }
    public function showRegister(): View { return view('auth.register'); }

    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $user = User::create($validated);
            $user->playerProfile()->create([
                'user_id' => $user->id, 'nickname' => $validated['name'],
            ]);
            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();
        // Falha na rede não pode desfazer a conta e o perfil já persistidos.
        if (! $this->verificationSender->send($user)) {
            return redirect()->route('verification.notice')->withErrors(['email' => 'Conta criada, mas não foi possível enviar o e-mail de confirmação. Aguarde um minuto e tente reenviar.']);
        }
        return redirect()->route('verification.notice')->with('status', 'Conta criada. Enviamos um link para confirmar seu e-mail.');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            // Evidencia para deteccao de forca bruta; nunca registra a senha.
            Log::warning('auth.login_failed', ['email' => $credentials['email'], 'ip' => $request->ip()]);
            return back()->withErrors(['email' => 'Credenciais invalidas.'])->onlyInput('email');
        }
        $request->session()->regenerate();
        if (Auth::user()->email_verification_required && ! Auth::user()->hasVerifiedEmail()) {
            $intended = $request->session()->get('url.intended');
            $verificationUrl = route('verification.verify', [
                'id' => Auth::id(), 'hash' => sha1(Auth::user()->getEmailForVerification()),
            ]);
            // Só retoma o link de confirmação assinado desta conta; nunca uma área privada.
            if (is_string($intended) && strtok($intended, '?') === $verificationUrl
                && URL::hasValidSignature(Request::create($intended))) {
                return redirect()->intended(route('verification.notice'));
            }
            return redirect()->route('verification.notice');
        }
        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
