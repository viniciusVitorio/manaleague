<?php

namespace App\Observers;

use App\Models\CommunityNotification;
use App\Models\User;
use App\Services\Community\NotificationService;
use App\Services\Realtime\LiveChannels;
use App\Services\Realtime\RealtimeService;
use Illuminate\Database\Eloquent\Model;

/**
 * Qualquer gravacao nos dados que as telas ao vivo mostram vira um aviso "mudou" nos canais afetados.
 * Observar os models (e nao cada service) cobre todos os caminhos de escrita: organizador, celular,
 * contestacao, Ranked, desafios. Update em massa (query builder) nao dispara: o polling lento cobre.
 */
class LiveChangeObserver
{
    public function __construct(
        private readonly RealtimeService $realtime,
        private readonly LiveChannels $channels,
    ) {}

    public function saved(Model $model): void
    {
        $this->touch($model);
    }

    public function deleted(Model $model): void
    {
        $this->touch($model);
    }

    public function created(Model $model): void
    {
        // Notificacao nova: alem de atualizar a tela, o sino e um aviso aparecem em qualquer pagina aberta.
        if (! $model instanceof CommunityNotification || ! $this->realtime->enabled()) {
            return;
        }
        $user = $model->user_id ? User::find($model->user_id) : null;
        if ($user && app(NotificationService::class)->canRead($user, $model)) {
            $this->realtime->send(LiveChannels::user($user->id), 'notification', [
                'title' => (string) $model->title,
                'url' => (string) $model->url,
            ]);
        }
    }

    private function touch(Model $model): void
    {
        if ($this->realtime->enabled()) {
            $this->realtime->touch(...$this->channels->affectedBy($model));
        }
    }
}
