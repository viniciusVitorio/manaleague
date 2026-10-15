<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    public function __construct(public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Redefinição de senha — ManaLeague')
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line('Recebemos um pedido para redefinir a senha da sua conta no ManaLeague.')
            ->action('Criar nova senha', $url)
            ->line("Este link expira em {$minutes} minutos e só pode ser usado uma vez.")
            ->line('Se você não pediu a troca, ignore este e-mail: sua senha atual continua valendo.')
            ->salutation('Até a próxima mesa, equipe ManaLeague');
    }
}
