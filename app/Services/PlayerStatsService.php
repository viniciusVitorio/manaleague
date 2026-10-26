<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\PlayerProfile;
use App\Models\Tournament;
use Illuminate\Support\Collection;

class PlayerStatsService
{
    public function for(PlayerProfile $profile): array
    {
        $entries = $profile->entries()->with(['tournament', 'matchesAsPlayerOne', 'matchesAsPlayerTwo'])->get();
        $wins = $losses = $draws = $gamesFor = $gamesAgainst = $currentStreak = $bestStreak = 0;
        $titles = $runnerUps = $thirdPlaces = $undefeatedRuns = $cleanSweeps = $comebacks = 0;
        $zeroTwo = $bye = false;
        $decks = [];
        $deckRecords = []; // por deck salvo (players.deck_id): vitórias, derrotas e torneios

        foreach ($entries as $entry) {
            $matches = $this->matchesFor($entry);
            $entryLosses = 0;
            $deckName = $entry->deck_name ?: 'Deck não informado';
            $deckKey = $deckName.'|'.($entry->deck_colors ?? '');
            $decks[$deckKey] ??= ['name' => $deckName, 'colors' => $entry->deck_colors, 'wins' => 0, 'losses' => 0];
            if ($entry->deck_id) {
                $deckRecords[$entry->deck_id] ??= ['wins' => 0, 'losses' => 0, 'tournaments' => 0];
                $deckRecords[$entry->deck_id]['tournaments']++;
            }

            foreach ($matches as $match) {
                [$for, $against] = $this->score($match, $entry->id);
                $gamesFor += $for;
                $gamesAgainst += $against;
                $bye = $bye || $match->status === GameMatch::STATUS_BYE;

                if ($for > $against) {
                    $wins++;
                    $decks[$deckKey]['wins']++;
                    if ($entry->deck_id) $deckRecords[$entry->deck_id]['wins']++;
                    $bestStreak = max($bestStreak, ++$currentStreak);
                    if ($for === 2 && $against === 0 && $match->status === GameMatch::STATUS_FINISHED) $cleanSweeps++;
                    if ($against > 0 && $match->stage !== GameMatch::STAGE_LEAGUE) $comebacks++;
                } elseif ($for < $against) {
                    $losses++;
                    $entryLosses++;
                    $decks[$deckKey]['losses']++;
                    if ($entry->deck_id) $deckRecords[$entry->deck_id]['losses']++;
                    $currentStreak = 0;
                    $zeroTwo = $zeroTwo || ($for === 0 && $against === 2);
                } else {
                    $draws++;
                    $currentStreak = 0;
                }

                if ($match->status === GameMatch::STATUS_FINISHED) {
                    if ($match->stage === GameMatch::STAGE_FINAL) {
                        $for > $against ? $titles++ : $runnerUps++;
                    } elseif ($match->stage === GameMatch::STAGE_THIRD_PLACE && $for > $against) {
                        $thirdPlaces++;
                    }
                }
            }

            if ($entry->tournament?->status === Tournament::STATUS_FINISHED && $matches->isNotEmpty() && $entryLosses === 0) {
                $undefeatedRuns++;
            }
        }

        $played = $wins + $losses + $draws;
        $tournaments = $entries->pluck('tournament')->filter()->unique('id');

        return [
            'wins' => $wins, 'losses' => $losses, 'draws' => $draws, 'played' => $played,
            'games_for' => $gamesFor, 'games_against' => $gamesAgainst,
            'winrate' => $played ? round($wins / $played * 100) : 0,
            'best_streak' => $bestStreak,
            'tournaments' => $tournaments->sortByDesc('tournament_date')->values(),
            'decks' => collect($decks)->values(),
            'deck_records' => $deckRecords,
            // Fatos consumidos pelo AchievementService.
            'facts' => [
                'titles' => $titles, 'runner_ups' => $runnerUps, 'third_places' => $thirdPlaces,
                'podiums' => $titles + $runnerUps + $thirdPlaces,
                'undefeated_runs' => $undefeatedRuns, 'clean_sweeps' => $cleanSweeps, 'comebacks' => $comebacks,
                'zero_two' => $zeroTwo, 'bye' => $bye,
                'tournaments_played' => $tournaments->count(),
                'tournaments_finished' => $tournaments->where('status', Tournament::STATUS_FINISHED)->count(),
                'formats_played' => $tournaments->pluck('format')->unique()->count(),
            ],
        ];
    }

    public function tournamentSummary(PlayerProfile $profile, int $tournamentId): array
    {
        $entry = $profile->entries()->where('tournament_id', $tournamentId)
            ->with(['matchesAsPlayerOne', 'matchesAsPlayerTwo'])->firstOrFail();
        $matches = $this->matchesFor($entry);
        $wins = $losses = $gamesFor = $gamesAgainst = $streak = $bestStreak = 0;

        foreach ($matches as $match) {
            [$for, $against] = $this->score($match, $entry->id);
            $gamesFor += $for; $gamesAgainst += $against;
            if ($for > $against) { $wins++; $bestStreak = max($bestStreak, ++$streak); }
            elseif ($for < $against) { $losses++; $streak = 0; }
        }

        return compact('wins', 'losses', 'gamesFor', 'gamesAgainst', 'bestStreak') + ['entry' => $entry];
    }

    /** @return array{0:int,1:int} games a favor e contra, do ponto de vista do jogador */
    private function score(GameMatch $match, int $playerId): array
    {
        $isPlayerOne = $match->player_one_id === $playerId;

        return [
            (int) ($isPlayerOne ? $match->player_one_games : $match->player_two_games),
            (int) ($isPlayerOne ? $match->player_two_games : $match->player_one_games),
        ];
    }

    private function matchesFor($entry): Collection
    {
        return $entry->matchesAsPlayerOne->concat($entry->matchesAsPlayerTwo)
            ->whereIn('status', [GameMatch::STATUS_FINISHED, GameMatch::STATUS_BYE])
            ->sortBy(fn (GameMatch $match) => $match->played_at?->timestamp ?? $match->id)->values();
    }
}
