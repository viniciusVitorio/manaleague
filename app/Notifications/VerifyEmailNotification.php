<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmailNotification extends VerifyEmail
{
    public function toMail($notifiable)
    {
        $minutes = config('auth.verification.expire', 60);

        return (new MailMessage)
            ->subject('Confirme seu e-mail — ManaLeague')
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line('Confirme seu endereço de e-mail para acessar sua conta no ManaLeague.')
            ->action('Confirmar e-mail', $this->verificationUrl($notifiable))
            ->line("Este link expira em {$minutes} minutos.")
            ->line('Se você não criou esta conta, ignore este e-mail.')
            ->salutation('Até a próxima mesa, equipe ManaLeague');
    }
}
