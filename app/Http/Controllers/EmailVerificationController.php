<?php

namespace App\Http\Controllers;

use App\Services\EmailVerificationSender;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        if (! $request->user()->email_verification_required || $request->user()->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        return view('auth.verify-email');
    }

    public function send(Request $request, EmailVerificationSender $sender): RedirectResponse
    {
        if (! $request->user()->email_verification_required || $request->user()->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }
        if (! $sender->send($request->user())) {
            return redirect()->route('verification.notice')->withErrors(['email' => 'Não foi possível enviar o e-mail de confirmação. Aguarde um minuto e tente reenviar.']);
        }

        return redirect()->route('verification.notice')->with('status', 'Link de confirmação enviado. Confira sua caixa de entrada e o spam.');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect()->route('dashboard')->with('status', 'E-mail confirmado com sucesso.');
    }
}
