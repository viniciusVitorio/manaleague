<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\Tournament;

class TournamentRecapService
{
    public function for(Tournament $tournament): ?array
    {
        if ($tournament->status !== Tournament::STATUS_FINISHED) return null;
        $final = $tournament->matches->firstWhere('stage', GameMatch::STAGE_FINAL);
        if (! $final || $final->status !== GameMatch::STATUS_FINISHED) return null;

        $champion = $final->player_one_games > $final->player_two_games ? $final->playerOne : $final->playerTwo;
        if (! $champion) return null;

        $matches = $tournament->matches->filter(fn ($match) =>
            in_array($match->status, [GameMatch::STATUS_FINISHED, GameMatch::STATUS_BYE], true)
            && ($match->player_one_id === $champion->id || $match->player_two_id === $champion->id)
        )->sortBy(fn ($match) => $match->played_at?->timestamp ?? $match->id);

        $wins = $losses = $gamesFor = $gamesAgainst = $streak = $bestStreak = 0;
        foreach ($matches as $match) {
            $one = $match->player_one_id === $champion->id;
            $for = (int) ($one ? $match->player_one_games : $match->player_two_games);
            $against = (int) ($one ? $match->player_two_games : $match->player_one_games);
            $gamesFor += $for; $gamesAgainst += $against;
            if ($for > $against) { $wins++; $bestStreak = max($bestStreak, ++$streak); }
            elseif ($for < $against) { $losses++; $streak = 0; }
        }

        return compact('champion', 'wins', 'losses', 'gamesFor', 'gamesAgainst', 'bestStreak');
    }
}
