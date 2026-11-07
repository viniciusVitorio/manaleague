<?php

namespace App\Services\Realtime;

use App\Events\RealtimeMessage;
use Throwable;

/**
 * Avisos em tempo real (Reverb). Tudo e juntado durante a requisicao e enviado uma vez so, depois da
 * resposta (terminating): varios saves viram um aviso por canal e nada atrasa a pagina de quem clicou.
 *  - touch(): "esta tela mudou". O aviso nao leva dado: o navegador pergunta a versao/os dados pelos
 *    endpoints HTTP de sempre, que autorizam e filtram.
 *  - send(): mensagem com conteudo (jogadas do contador de vida, titulo de uma notificacao nova),
 *    so em canal privado ja autorizado.
 *
 * Reverb fora do ar nunca quebra a requisicao: o erro e registrado e as telas seguem no polling.
 */
class RealtimeService
{
    /** A API do Pusher/Reverb aceita ate 100 canais por mensagem. */
    private const CHANNELS_PER_MESSAGE = 90;

    /** @var array<string, true> */
    private array $changed = [];

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private array $messages = [];

    private bool $flushScheduled = false;

    public function enabled(): bool
    {
        return config('broadcasting.default') === 'reverb';
    }

    public function touch(string ...$channels): void
    {
        foreach ($channels as $channel) {
            if ($channel !== '') {
                $this->changed[$channel] = true;
            }
        }
        $this->scheduleFlush();
    }

    /** @param  array<string, mixed>  $payload */
    public function send(string $channel, string $name, array $payload): void
    {
        $this->messages[] = [$channel, $name, $payload];
        $this->scheduleFlush();
    }

    public function flush(): void
    {
        [$messages, $channels] = [$this->messages, array_keys($this->changed)];
        $this->messages = [];
        $this->changed = [];
        foreach ($messages as [$channel, $name, $payload]) {
            $this->dispatch([$channel], $name, $payload);
        }
        foreach (array_chunk($channels, self::CHANNELS_PER_MESSAGE) as $chunk) {
            $this->dispatch($chunk, 'changed', []);
        }
    }

    private function scheduleFlush(): void
    {
        if (! $this->flushScheduled && ($this->changed !== [] || $this->messages !== [])) {
            $this->flushScheduled = true;
            app()->terminating(fn () => $this->flush());
        }
    }

    /**
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(array $channels, string $name, array $payload): void
    {
        try {
            event(new RealtimeMessage($channels, $name, $payload));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
