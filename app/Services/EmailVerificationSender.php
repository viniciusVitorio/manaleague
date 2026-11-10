<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class EmailVerificationSender
{
    /** Cadastro e reenvio compartilham o cooldown por conta, inclusive quando o transporte falha. */
    public function send(User $user): bool
    {
        $key = 'email-verification:'.$user->getKey();
        if (RateLimiter::tooManyAttempts($key, 1)) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($key), 'Aguarde antes de reenviar o e-mail.');
        }
        RateLimiter::hit($key, 60);

        try {
            $user->sendEmailVerificationNotification();

            return true;
        } catch (Throwable $exception) {
            // O texto da exceção pode conter credenciais ou URLs assinadas; registre apenas a classe.
            Log::warning('auth.verification_mail_failed', ['user_id' => $user->getKey(), 'exception' => $exception::class]);

            return false;
        }
    }
}
