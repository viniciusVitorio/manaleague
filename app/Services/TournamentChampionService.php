<?php

namespace App\Services;

use App\Models\Player;
use App\Models\Tournament;

/** Campeao de um torneio encerrado, para qualquer sistema de disputa. */
class TournamentChampionService
{
    public function for(Tournament $t): ?Player
    {
        if ($t->status !== Tournament::STATUS_FINISHED) {
            return null;
        }
        if (in_array($t->system, ['swiss', 'commander_pods'], true)) {
            return app(StandingsService::class)->for($t)->first()['player'] ?? null;
        }
        if (in_array($t->system, [null, 'round_robin'], true)) {
            return app(TournamentRecapService::class)->for($t)['champion'] ?? null;
        }
        $final = $t->matches()->where('status', 'finished')->orderByDesc('round')->orderByDesc('id')->first();
        if (! $final) {
            return null;
        }

        return $final->player_one_games > $final->player_two_games ? $final->playerOne : $final->playerTwo;
    }
}
