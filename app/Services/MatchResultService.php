<?php
namespace App\Services;

use App\Models\{Tournament, GameMatch, Player};
use Illuminate\Support\Facades\{DB, Log, Validator};
use Illuminate\Validation\ValidationException;
use LogicException;

/** Canonical result writer for organizers AND confirmed player submissions.
 * Caller must authorize the actor. This service enforces tournament/match integrity.
 * Duel scores: player_one_games, player_two_games (integers 0..3).
 * Pod scores: ['placements' => [playerId => rank, ...]]; implemented below.
 * ValidationException = invalid scores; LogicException = immutable/conflicting state.
 */
class MatchResultService
{
    public function record(Tournament $tournament, GameMatch $match, array $scores): void
    {
        DB::transaction(function () use ($tournament, $match, $scores): void {
            $t = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);
            $m = GameMatch::query()->lockForUpdate()->findOrFail($match->id);
            if ($m->tournament_id !== $t->id) throw new LogicException('Partida de outro torneio.');
            if ($m->status === 'bye') throw new LogicException('BYE não pode ser alterado.');
            if (! $this->editable($t, $m)) throw new LogicException('Resultado travado: uma fase dependente já foi gerada ou o torneio não começou.');
            if ($m->pod_player_ids) {
                $data = Validator::make($scores, ['placements'=>['required','array'], 'placements.*'=>['required','integer','min:1','max:'.count($m->pod_player_ids)]])->validate();
                $ids=array_map('intval',array_keys($data['placements'])); sort($ids);
                $expected=$m->pod_player_ids; sort($expected);
                $ranks=array_map('intval',array_values($data['placements'])); sort($ranks);
                if ($ids!==$expected || $ranks!==range(1,count($expected))) throw ValidationException::withMessages(['placements'=>'Informe uma posição única para cada participante da mesa.']);
                $values=['pod_scores'=>array_map('intval',$data['placements'])];
            } else {
            $values = Validator::make($scores, [
                'player_one_games' => ['required', 'integer', 'min:0', 'max:3'],
                'player_two_games' => ['required', 'integer', 'min:0', 'max:3'],
            ])->validate();
            $values = array_map('intval', $values);
            if (array_sum($values) === 0) throw ValidationException::withMessages(['score' => 'O placar não pode ser 0 a 0.']);
            if (($m->stage !== 'league' || in_array($t->system, ['single_elimination', 'double_elimination'])) && $values['player_one_games'] === $values['player_two_games']) {
                throw ValidationException::withMessages(['score' => 'Partidas decisivas não podem terminar empatadas.']);
            }
            }
            $before = $m->only(['player_one_games', 'player_two_games', 'pod_scores', 'status']);
            $m->update([...$values, 'status' => 'finished', 'played_at' => $m->played_at ?? now()]);
            if ($t->system === 'round_robin' && $t->status === 'finals' && ! $t->matches()->whereIn('stage', ['final', 'third_place'])->where('status', 'pending')->exists()) {
                $t->update(['status' => 'finished']);
            }
            Log::info('match.result_saved', ['user_id' => auth()->id(), 'tournament_id' => $t->id, 'match_id' => $m->id, 'before' => $before, 'after' => $values]);
            foreach (Player::query()->whereIn('id', $m->pod_player_ids ?? [$m->player_one_id, $m->player_two_id])->with('profile')->get() as $player) {
                if ($player->profile) app(AchievementService::class)->sync($player->profile);
            }
        });
    }

    public function editable(Tournament $t, GameMatch $m): bool
    {
        if ($m->status === 'bye' || $t->status === 'setup') return false;
        if ($t->system !== 'round_robin') {
            if ($t->status === 'finished') return false;
            return ! $t->matches()->where('round', '>', $m->round)->exists();
        }
        return $m->stage === 'league' ? $t->status === 'league' : in_array($t->status, ['finals', 'finished'], true);
    }
}
