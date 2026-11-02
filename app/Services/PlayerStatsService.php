<?php

namespace App\Services;

use App\Models\CasualMatch;
use App\Models\GameMatch;
use App\Models\PlayerProfile;
use App\Models\RankedMatch;
use App\Models\Tournament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Estatísticas do perfil. Soma toda partida 1v1 com resultado final: torneio (incluindo BYE),
 * desafio casual confirmado e Ranked confirmado. Ranked nascido de partida de torneio já entra
 * pelo torneio e não conta duas vezes. Mesas de Commander (pods) ficam de fora.
 */
class PlayerStatsService
{
    /** Tipos de partida, na ordem das abas do perfil. */
    public const KINDS = ['tournament' => 'Torneios', 'casual' => 'Desafios', 'ranked' => 'Ranked'];

    public function for(PlayerProfile $profile): array
    {
        $entries = $profile->entries()->with(['tournament', 'matchesAsPlayerOne', 'matchesAsPlayerTwo'])->get();
        $titles = $runnerUps = $thirdPlaces = $undefeatedRuns = $comebacks = 0;
        $bye = false;
        $decks = [];
        $deckRecords = []; // por deck salvo (players.deck_id): vitórias, derrotas e torneios
        $results = [];

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
                $isBye = $match->status === GameMatch::STATUS_BYE;
                $results[] = $this->result('tournament', $match->played_at ?? $match->updated_at ?? $match->created_at, $for, $against, $isBye);
                $bye = $bye || $isBye;

                if ($for > $against) {
                    $decks[$deckKey]['wins']++;
                    if ($entry->deck_id) $deckRecords[$entry->deck_id]['wins']++;
                    if ($against > 0 && $match->stage !== GameMatch::STAGE_LEAGUE) $comebacks++;
                } elseif ($for < $against) {
                    $entryLosses++;
                    $decks[$deckKey]['losses']++;
                    if ($entry->deck_id) $deckRecords[$entry->deck_id]['losses']++;
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

        $results = collect($results)->concat($this->casualResults($profile))->concat($this->rankedResults($profile))
            ->sortBy(fn (array $result) => $result['date']?->getTimestamp() ?? 0)->values();
        $overall = $this->aggregate($results);
        $tournaments = $entries->pluck('tournament')->filter()->unique('id');

        return $overall + [
            'by_kind' => collect(self::KINDS)->map(fn (string $label, string $kind) => $this->aggregate($results->where('kind', $kind)))->all(),
            'tournaments' => $tournaments->sortByDesc('tournament_date')->values(),
            'decks' => collect($decks)->values(),
            'deck_records' => $deckRecords,
            // Fatos consumidos pelo AchievementService.
            'facts' => [
                'titles' => $titles, 'runner_ups' => $runnerUps, 'third_places' => $thirdPlaces,
                'podiums' => $titles + $runnerUps + $thirdPlaces,
                'undefeated_runs' => $undefeatedRuns, 'comebacks' => $comebacks, 'bye' => $bye,
                // Valem para qualquer partida: torneio, desafio casual ou Ranked. BYE não conta.
                'clean_sweeps' => $results->filter(fn (array $r) => ! $r['bye'] && $r['for'] >= 2 && $r['against'] === 0)->count(),
                'zero_two' => $results->contains(fn (array $r) => $r['for'] === 0 && $r['against'] >= 2),
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

    /** Desafios casuais com placar confirmado pelos dois. */
    private function casualResults(PlayerProfile $profile): Collection
    {
        return CasualMatch::query()->with('challenge:id,challenger_id,opponent_id')->where('status', 'confirmed')
            ->whereHas('challenge', fn ($q) => $q->where(fn ($q) => $q->where('challenger_id', $profile->id)->orWhere('opponent_id', $profile->id)))
            ->get()
            ->map(function (CasualMatch $match) use ($profile) {
                $mine = $match->challenge->challenger_id === $profile->id;

                return $this->result('casual', $match->confirmed_at ?? $match->updated_at,
                    $mine ? $match->challenger_games : $match->opponent_games, $mine ? $match->opponent_games : $match->challenger_games);
            });
    }

    /** Ranked confirmado (inclui o decidido pela moderação). O de torneio já conta pelo torneio. */
    private function rankedResults(PlayerProfile $profile): Collection
    {
        return RankedMatch::query()->where('status', 'confirmed')->where('source_type', '!=', 'tournament_match')
            ->where(fn ($q) => $q->where('player_one_profile_id', $profile->id)->orWhere('player_two_profile_id', $profile->id))
            ->get()
            ->map(function (RankedMatch $match) use ($profile) {
                $one = $match->player_one_profile_id === $profile->id;

                return $this->result('ranked', $match->played_at ?? $match->confirmed_at ?? $match->updated_at,
                    $one ? $match->score_one : $match->score_two, $one ? $match->score_two : $match->score_one);
            });
    }

    private function result(string $kind, ?Carbon $date, ?int $for, ?int $against, bool $bye = false): array
    {
        return ['kind' => $kind, 'date' => $date, 'for' => (int) $for, 'against' => (int) $against, 'bye' => $bye];
    }

    /** Recorde de uma lista de partidas em ordem cronológica. Empate quebra a sequência de vitórias. */
    private function aggregate(Collection $results): array
    {
        $wins = $losses = $draws = $gamesFor = $gamesAgainst = $streak = $bestStreak = 0;

        foreach ($results as $result) {
            $gamesFor += $result['for'];
            $gamesAgainst += $result['against'];
            if ($result['for'] > $result['against']) {
                $wins++;
                $bestStreak = max($bestStreak, ++$streak);
            } else {
                $result['for'] < $result['against'] ? $losses++ : $draws++;
                $streak = 0;
            }
        }

        $played = $wins + $losses + $draws;

        return [
            'wins' => $wins, 'losses' => $losses, 'draws' => $draws, 'played' => $played,
            'games_for' => $gamesFor, 'games_against' => $gamesAgainst,
            'winrate' => $played ? (int) round($wins / $played * 100) : 0,
            'best_streak' => $bestStreak,
        ];
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
