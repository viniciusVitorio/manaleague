<?php
namespace App\Services\Decks;
use App\Models\{DeckVersion, GameMatch};
class DeckUsageService {
    public function results(DeckVersion $version): array {
        $wins = $losses = $draws = 0;
        foreach ($version->uses as $use) {
            $matches = GameMatch::where('tournament_id', $use->tournament_id)->where('status', GameMatch::STATUS_FINISHED)
                ->whereNotNull('player_two_id')->whereNotNull('player_one_games')->whereNotNull('player_two_games')
                ->where(fn ($q) => $q->where('player_one_id', $use->player_id)->orWhere('player_two_id', $use->player_id))->get();
            foreach ($matches as $match) {
                $mine = $match->player_one_id === $use->player_id ? $match->player_one_games : $match->player_two_games;
                $opponent = $match->player_one_id === $use->player_id ? $match->player_two_games : $match->player_one_games;
                if ($mine > $opponent) $wins++; elseif ($mine < $opponent) $losses++; else $draws++;
            }
        }
        $total = $wins + $losses + $draws;
        return compact('wins','losses','draws','total') + ['rate' => $total ? round(100 * $wins / $total, 1) : null];
    }
}
