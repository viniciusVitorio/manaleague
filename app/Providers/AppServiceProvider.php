<?php

namespace App\Providers;

use App\Models\CasualMatch;
use App\Models\Challenge;
use App\Models\CommunityNotification;
use App\Models\GameMatch;
use App\Models\MatchResultSubmission;
use App\Models\Player;
use App\Models\RankedMatch;
use App\Models\RankedResultSubmission;
use App\Models\Tournament;
use App\Observers\LiveChangeObserver;
use App\Services\Realtime\RealtimeService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Um por processo/requisicao: junta os avisos e envia uma vez no fim.
        $this->app->singleton(RealtimeService::class);
    }

    public function boot(): void
    {
        // Dados que as telas ao vivo mostram (mesmas tabelas do LiveVersionService).
        foreach ([Tournament::class, Player::class, GameMatch::class, MatchResultSubmission::class, Challenge::class,
            CasualMatch::class, RankedMatch::class, RankedResultSubmission::class, CommunityNotification::class] as $model) {
            $model::observe(LiveChangeObserver::class);
        }
    }
}
