<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Mensagem enviada pelo Reverb. Os nomes dos canais ja vem completos ("private-user.5", "arena.abc"):
 * quem decide o canal e a autorizacao e o RealtimeService / routes/channels.php.
 * ShouldBroadcastNow: publica na hora (sem fila); o RealtimeService chama isto depois da resposta.
 */
class RealtimeMessage implements ShouldBroadcastNow
{
    /**
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly array $channels,
        public readonly string $name,
        public readonly array $payload = [],
    ) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return array_map(fn (string $channel) => new Channel($channel), $this->channels);
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
