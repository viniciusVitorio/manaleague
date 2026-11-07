<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\Tournament;
use Illuminate\Support\Collection;

class StandingsService
{
    public function for(Tournament $tournament): Collection
    {
        $players = $tournament->players()->with(['profile', 'deck'])->get();
        $table = [];

        foreach ($players as $player) {
            $table[$player->id] = [
                'player' => $player,
                'played' => 0,
                'wins' => 0,
                'draws' => 0,
                'losses' => 0,
                'games_for' => 0,
                'games_against' => 0,
                'game_diff' => 0,
                'points' => 0,
                'streak' => 0,
            ];
        }

        $matches = $tournament->matches()
            ->when(!in_array($tournament->system, ['single_elimination','double_elimination']), fn ($query) => $query->where('stage', GameMatch::STAGE_LEAGUE))
            ->whereIn('status', [GameMatch::STATUS_FINISHED, GameMatch::STATUS_BYE])
            ->orderBy('round')->orderBy('id')
            ->get();

        foreach ($matches as $match) {
            if ($match->pod_player_ids) {
                foreach ($match->pod_scores ?? [] as $id=>$rank) {
                    $table[$id]['played']++;
                    $table[$id][$rank===1?'wins':'losses']++;
                    $table[$id]['points'] += count($match->pod_player_ids) - $rank;
                    $this->streak($table[$id], $rank === 1);
                }
                continue;
            }
            $one = $match->player_one_id;
            $two = $match->player_two_id;
            $oneGames = (int) $match->player_one_games;
            $twoGames = (int) $match->player_two_games;

            $table[$one]['played']++;
            $table[$one]['games_for'] += $oneGames;
            $table[$one]['games_against'] += $twoGames;

            if ($two === null) {
                // BYE conta como vitoria na tabela, mas nao entra (nem zera) a sequencia de vitorias.
                $table[$one]['wins']++;
                $table[$one]['points'] += 3;
                continue;
            }

            $table[$two]['played']++;
            $table[$two]['games_for'] += $twoGames;
            $table[$two]['games_against'] += $oneGames;

            if ($oneGames > $twoGames) {
                $table[$one]['wins']++;
                $table[$one]['points'] += 3;
                $table[$two]['losses']++;
                $this->streak($table[$one], true);
                $this->streak($table[$two], false);
            } elseif ($twoGames > $oneGames) {
                $table[$two]['wins']++;
                $table[$two]['points'] += 3;
                $table[$one]['losses']++;
                $this->streak($table[$two], true);
                $this->streak($table[$one], false);
            } else {
                $table[$one]['draws']++;
                $table[$two]['draws']++;
                $table[$one]['points']++;
                $table[$two]['points']++;
                $this->streak($table[$one], false);
                $this->streak($table[$two], false);
            }
        }

        foreach ($table as &$row) {
            $row['game_diff'] = $row['games_for'] - $row['games_against'];
        }
        unset($row);

        return collect(array_values($table))
            ->sort(function (array $left, array $right): int {
                return ($right['points'] <=> $left['points'])
                    ?: ($right['game_diff'] <=> $left['game_diff'])
                    ?: ($right['games_for'] <=> $left['games_for'])
                    ?: strcmp($left['player']->name, $right['player']->name);
            })
            ->values()
            ->map(function (array $row, int $index): array {
                $row['rank'] = $index + 1;

                return $row;
            });
    }

    /**
     * Classificação final com o pódio no topo: campeão e vice pela final (na eliminação, pela última partida),
     * 3º pela disputa de 3º lugar; sem essas partidas (suíço, pods), vale a própria classificação.
     * As linhas do pódio ganham 'podium' => 1, 2 ou 3 e o resto segue a ordem da liga.
     * Fora do torneio encerrado, devolve a classificação como veio.
     */
    public function withPodium(Tournament $tournament, Collection $ranking): Collection
    {
        if ($tournament->status !== Tournament::STATUS_FINISHED || $ranking->isEmpty()) {
            return $ranking;
        }

        $matches = $tournament->relationLoaded('matches') ? $tournament->matches : $tournament->matches()->get();
        // [vencedor, perdedor] de uma partida encerrada entre dois jogadores.
        $result = fn (?GameMatch $match): ?array => $match && $match->status === GameMatch::STATUS_FINISHED && $match->player_two_id
            ? ($match->player_one_games > $match->player_two_games ? [$match->player_one_id, $match->player_two_id] : [$match->player_two_id, $match->player_one_id])
            : null;

        $final = $matches->firstWhere('stage', GameMatch::STAGE_FINAL);
        if (! $final && in_array($tournament->system, ['single_elimination', 'double_elimination'], true)) {
            $final = $matches->where('status', GameMatch::STATUS_FINISHED)->sortBy([['round', 'desc'], ['id', 'desc']])->first();
        }

        $podium = $result($final) ?? [];
        if ($podium && ($third = $result($matches->firstWhere('stage', GameMatch::STAGE_THIRD_PLACE)))) {
            $podium[] = $third[0];
        }
        foreach ($ranking as $row) {
            if (count($podium) >= 3) {
                break;
            }
            if (! in_array($row['player']->id, $podium, true)) {
                $podium[] = $row['player']->id;
            }
        }

        $byPlayer = $ranking->keyBy(fn (array $row) => $row['player']->id);
        $top = collect($podium)->filter(fn ($id) => $byPlayer->has($id))->values()
            ->map(fn ($id, int $index) => [...$byPlayer[$id], 'podium' => $index + 1]);

        return $top->concat($ranking->reject(fn (array $row) => in_array($row['player']->id, $podium, true)))
            ->values()
            ->map(function (array $row, int $index): array {
                $row['rank'] = $index + 1;

                return $row;
            });
    }

    /** Vitorias seguidas mais recentes no torneio (partidas em ordem de rodada); empate ou derrota zera. */
    private function streak(array &$row, bool $won): void
    {
        $row['streak'] = $won ? $row['streak'] + 1 : 0;
    }
}
