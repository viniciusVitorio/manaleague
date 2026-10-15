<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerta de seguranca: avisa o dono da conta sempre que a senha muda,
 * para que uma troca nao autorizada seja percebida rapidamente.
 */
class PasswordChangedNotification extends Notification
{
    public function __construct(private readonly ?string $ip = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Sua senha foi alterada — ManaLeague')
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line('A senha da sua conta no ManaLeague acabou de ser alterada'.($this->ip ? " (IP {$this->ip})" : '').'.')
            ->line('Por segurança, todas as outras sessões abertas foram encerradas.')
            ->line('Se foi você, está tudo certo. Se não foi, redefina sua senha agora:')
            ->action('Redefinir senha', route('password.request'))
            ->salutation('Equipe ManaLeague');
    }
}
