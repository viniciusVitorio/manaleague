<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Parabéns para quem subiu ao pódio, com o resultado no torneio.
 * Usa views próprias e não o modelo em Markdown: nome do torneio e de prêmio são escritos pelo organizador,
 * e em Markdown poderiam virar um link dentro de um e-mail enviado pelo ManaLeague.
 */
class TournamentPodiumNotification extends Notification
{
    /** @param  array<string, mixed>  $podium  montado pelo PodiumEmailService */
    public function __construct(public readonly array $podium) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->podium['medal']} {$this->podium['placement_label']} em {$this->podium['tournament']} — ManaLeague")
            ->view(['mail.tournament-podium', 'mail.tournament-podium-text'], ['podium' => $this->podium, 'name' => $notifiable->name]);
    }
}
