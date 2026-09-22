<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\PlayerProfile;
use Illuminate\Support\Collection;

class PlayerStatsService
{
    public function for(PlayerProfile $profile): array
    {
        $entries = $profile->entries()->with(['tournament', 'matchesAsPlayerOne', 'matchesAsPlayerTwo'])->get();
        $wins = $losses = $draws = $gamesFor = $gamesAgainst = $currentStreak = $bestStreak = 0;
        $cleanSweep = $zeroTwo = $bye = $champion = $runnerUp = false;
        $decks = [];

        foreach ($entries as $entry) {
            $matches = $this->matchesFor($entry);

            foreach ($matches as $match) {
                $isPlayerOne = $match->player_one_id === $entry->id;
                $for = (int) ($isPlayerOne ? $match->player_one_games : $match->player_two_games);
                $against = (int) ($isPlayerOne ? $match->player_two_games : $match->player_one_games);
                $gamesFor += $for;
                $gamesAgainst += $against;
                $bye = $bye || $match->status === GameMatch::STATUS_BYE;

                if ($for > $against) {
                    $wins++;
                    $bestStreak = max($bestStreak, ++$currentStreak);
                    $cleanSweep = $cleanSweep || ($for === 2 && $against === 0);
                } elseif ($for < $against) {
                    $losses++;
                    $currentStreak = 0;
                    $zeroTwo = $zeroTwo || ($for === 0 && $against === 2);
                } else {
                    $draws++;
                    $currentStreak = 0;
                }

                if ($match->stage === GameMatch::STAGE_FINAL && $match->status === GameMatch::STATUS_FINISHED) {
                    $champion = $champion || $for > $against;
                    $runnerUp = $runnerUp || $for < $against;
                }
            }

            $deckName = $entry->deck_name ?: 'Deck não informado';
            $deckKey = $deckName.'|'.($entry->deck_colors ?? '');
            $decks[$deckKey] ??= ['name' => $deckName, 'colors' => $entry->deck_colors, 'wins' => 0, 'losses' => 0];

            foreach ($matches as $match) {
                $isPlayerOne = $match->player_one_id === $entry->id;
                $for = (int) ($isPlayerOne ? $match->player_one_games : $match->player_two_games);
                $against = (int) ($isPlayerOne ? $match->player_two_games : $match->player_one_games);
                if ($for > $against) $decks[$deckKey]['wins']++;
                elseif ($for < $against) $decks[$deckKey]['losses']++;
            }
        }

        $played = $wins + $losses + $draws;
        $achievements = array_values(array_filter([
            $cleanSweep ? ['icon' => '🧹', 'name' => 'Clean Sweep', 'description' => 'Venceu uma partida por 2–0'] : null,
            $bestStreak >= 3 ? ['icon' => '🔥', 'name' => 'On Fire', 'description' => 'Conquistou 3 vitórias seguidas'] : null,
            $champion ? ['icon' => '👑', 'name' => 'Champion', 'description' => 'Venceu um torneio'] : null,
            $runnerUp ? ['icon' => '🥈', 'name' => 'Almost There', 'description' => 'Chegou à final do torneio'] : null,
            $zeroTwo ? ['icon' => '💀', 'name' => '0–2 Enjoyer', 'description' => 'Sobreviveu a uma derrota por 0–2'] : null,
            $played > 0 && $losses === 0 ? ['icon' => '🐐', 'name' => 'Undefeated', 'description' => 'Ainda não sofreu derrotas'] : null,
            $bye ? ['icon' => '🎲', 'name' => 'BYE Merchant', 'description' => 'Ganhou uma rodada de BYE'] : null,
        ]));

        return [
            'wins' => $wins, 'losses' => $losses, 'draws' => $draws,
            'games_for' => $gamesFor, 'games_against' => $gamesAgainst,
            'winrate' => $played ? round($wins / $played * 100) : 0,
            'best_streak' => $bestStreak,
            'tournaments' => $entries->pluck('tournament')->filter()->unique('id')->sortByDesc('tournament_date')->values(),
            'decks' => collect($decks)->values(), 'achievements' => $achievements,
        ];
    }

    public function tournamentSummary(PlayerProfile $profile, int $tournamentId): array
    {
        $entry = $profile->entries()->where('tournament_id', $tournamentId)
            ->with(['matchesAsPlayerOne', 'matchesAsPlayerTwo'])->firstOrFail();
        $matches = $this->matchesFor($entry);
        $wins = $losses = $gamesFor = $gamesAgainst = $streak = $bestStreak = 0;

        foreach ($matches as $match) {
            $isPlayerOne = $match->player_one_id === $entry->id;
            $for = (int) ($isPlayerOne ? $match->player_one_games : $match->player_two_games);
            $against = (int) ($isPlayerOne ? $match->player_two_games : $match->player_one_games);
            $gamesFor += $for; $gamesAgainst += $against;
            if ($for > $against) { $wins++; $bestStreak = max($bestStreak, ++$streak); }
            elseif ($for < $against) { $losses++; $streak = 0; }
        }

        return compact('wins', 'losses', 'gamesFor', 'gamesAgainst', 'bestStreak') + ['entry' => $entry];
    }

    private function matchesFor($entry): Collection
    {
        return $entry->matchesAsPlayerOne->concat($entry->matchesAsPlayerTwo)
            ->whereIn('status', [GameMatch::STATUS_FINISHED, GameMatch::STATUS_BYE])
            ->sortBy(fn (GameMatch $match) => $match->played_at?->timestamp ?? $match->id)->values();
    }
}
