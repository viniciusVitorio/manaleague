<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\Tournament;
use Illuminate\Support\Facades\DB;
use LogicException;

class PairingService
{
    public function __construct(private readonly StandingsService $standings) {}

    public function generateLeague(Tournament $tournament): void
    {
        DB::transaction(function () use ($tournament): void {
            $locked = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);

            if ($locked->status !== Tournament::STATUS_SETUP || $locked->matches()->exists()) {
                throw new LogicException('Este torneio ja foi iniciado.');
            }

            $slots = $locked->players()->pluck('id')->all();

            if (count($slots) < 4) {
                throw new LogicException('Cadastre pelo menos quatro jogadores.');
            }

            if (count($slots) % 2 !== 0) {
                $slots[] = null;
            }

            $slotCount = count($slots);
            $roundCount = $slotCount - 1;

            for ($round = 1; $round <= $roundCount; $round++) {
                for ($index = 0; $index < $slotCount / 2; $index++) {
                    $playerOne = $slots[$index];
                    $playerTwo = $slots[$slotCount - 1 - $index];

                    if ($playerOne === null) {
                        [$playerOne, $playerTwo] = [$playerTwo, null];
                    }

                    $isBye = $playerTwo === null;

                    $locked->matches()->create([
                        'round' => $round,
                        'stage' => GameMatch::STAGE_LEAGUE,
                        'player_one_id' => $playerOne,
                        'player_two_id' => $playerTwo,
                        'player_one_games' => $isBye ? 2 : null,
                        'player_two_games' => $isBye ? 0 : null,
                        'status' => $isBye ? GameMatch::STATUS_BYE : GameMatch::STATUS_PENDING,
                        'played_at' => $isBye ? now() : null,
                    ]);
                }

                $fixed = array_shift($slots);
                $last = array_pop($slots);
                array_unshift($slots, $fixed, $last);
            }

            $locked->update(['status' => Tournament::STATUS_LEAGUE]);
        });
    }

    public function generateFinals(Tournament $tournament): void
    {
        DB::transaction(function () use ($tournament): void {
            $locked = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);

            if ($locked->status !== Tournament::STATUS_LEAGUE) {
                throw new LogicException('As finais nao podem ser geradas neste momento.');
            }

            if ($locked->matches()->where('stage', GameMatch::STAGE_LEAGUE)->where('status', GameMatch::STATUS_PENDING)->exists()) {
                throw new LogicException('Registre todos os resultados da fase de liga primeiro.');
            }

            $ranking = $this->standings->for($locked);

            if ($ranking->count() < 4) {
                throw new LogicException('Sao necessarios quatro classificados.');
            }

            $round = ((int) $locked->matches()->max('round')) + 1;

            $locked->matches()->create([
                'round' => $round,
                'stage' => GameMatch::STAGE_FINAL,
                'player_one_id' => $ranking[0]['player']->id,
                'player_two_id' => $ranking[1]['player']->id,
                'status' => GameMatch::STATUS_PENDING,
            ]);

            $locked->matches()->create([
                'round' => $round,
                'stage' => GameMatch::STAGE_THIRD_PLACE,
                'player_one_id' => $ranking[2]['player']->id,
                'player_two_id' => $ranking[3]['player']->id,
                'status' => GameMatch::STATUS_PENDING,
            ]);

            $locked->update(['status' => Tournament::STATUS_FINALS]);
        });
    }
}
