<?php

namespace App\Services\Ranked;

use App\Models\PlayerRating;
use App\Models\RankedMatch;
use App\Models\RankedSeason;
use App\Models\RatingTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Correcao auditada: recalcula o pool (temporada + formato) em ordem cronologica
 * usando o K persistido em cada partida e lanca um evento "correction" para quem
 * mudou. Lancamentos antigos nunca sao editados.
 */
class RatingReplayService
{
    public function __construct(private readonly EloCalculator $elo) {}

    /** @return array<int,array{before:int,after:int}> jogadores corrigidos */
    public function replay(RankedSeason $season, string $format, RankedMatch $trigger, string $note): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('replay exige transação.');
        }
        [$state, $start, $provisional] = $this->simulate($season, $format);

        $ratings = PlayerRating::query()->where(['season_id' => $season->id, 'format' => $format])->orderBy('player_profile_id')->lockForUpdate()->get();
        $seq = (int) RatingTransaction::query()->where('ranked_match_id', $trigger->id)->where('event_type', 'correction')->max('correction_seq') + 1;
        $changed = [];
        foreach ($ratings as $rating) {
            $target = $state[$rating->player_profile_id] ?? ['rating' => $start, 'peak' => $start, 'played' => 0, 'wins' => 0, 'losses' => 0, 'draws' => 0, 'prov' => $provisional];
            if ($target['rating'] !== $rating->rating) {
                RatingTransaction::create([
                    'ranked_match_id' => $trigger->id, 'season_id' => $season->id, 'format' => $format,
                    'player_profile_id' => $rating->player_profile_id, 'rating_before' => $rating->rating,
                    'rating_after' => $target['rating'], 'delta' => $target['rating'] - $rating->rating,
                    'event_type' => 'correction', 'correction_seq' => $seq, 'rules_version' => $trigger->rules_version,
                    'note' => mb_substr($note, 0, 255), 'effective_at' => now(),
                ]);
                $changed[$rating->player_profile_id] = ['before' => $rating->rating, 'after' => $target['rating']];
            }
            $rating->forceFill([
                'rating' => $target['rating'], 'peak_rating' => $target['peak'], 'played' => $target['played'], 'wins' => $target['wins'],
                'losses' => $target['losses'], 'draws' => $target['draws'], 'provisional_games_remaining' => $target['prov'], 'version' => $rating->version + 1,
            ])->save();
        }
        Log::info('ranked.replay', ['season_id' => $season->id, 'format' => $format, 'trigger' => $trigger->id, 'changed' => count($changed)]);

        return $changed;
    }

    /**
     * Quanto uma partida confirmada vale hoje, com o pool recalculado em ordem (a mesma conta do replay, só leitura).
     * Depois de uma correção (placar invertido, partida anulada antes desta), o lançamento "match" original fica velho;
     * isto dá o antes e o depois atuais de cada jogador nesta partida.
     *
     * @return array<int, array{before: int, after: int}> por player_profile_id (vazio se a partida não conta)
     */
    public function worth(RankedMatch $match): array
    {
        $found = [];
        $this->simulate($match->season()->firstOrFail(), $match->format, function (RankedMatch $m, array $before, array $after) use ($match, &$found) {
            if ($m->id === $match->id) {
                foreach ($before as $id => $rating) {
                    $found[$id] = ['before' => $rating, 'after' => $after[$id]];
                }
            }
        });

        return $found;
    }

    /**
     * Recalcula o pool (temporada + formato) do zero, em ordem de confirmação. $each recebe cada partida com o rating
     * dos dois antes e depois dela.
     *
     * @return array{0: array<int, array>, 1: int, 2: int} estado final por perfil, rating inicial e provisórias
     */
    private function simulate(RankedSeason $season, string $format, ?callable $each = null): array
    {
        $season->loadMissing('formats');
        $start = $season->formatConfig($format)?->starting_rating ?? (int) config('ranked.defaults.starting_rating');
        $provisional = (int) $season->setting('provisional_games');

        $state = [];
        $init = function (int $id) use (&$state, $start, $provisional) {
            $state[$id] ??= ['rating' => $start, 'peak' => $start, 'played' => 0, 'wins' => 0, 'losses' => 0, 'draws' => 0, 'prov' => $provisional];
        };
        $matches = RankedMatch::query()->where(['season_id' => $season->id, 'format' => $format, 'status' => 'confirmed', 'is_ranked' => true])
            ->orderBy('confirmed_at')->orderBy('id')->get();
        foreach ($matches as $m) {
            $a = $m->player_one_profile_id;
            $b = $m->player_two_profile_id;
            $init($a);
            $init($b);
            $before = [$a => $state[$a]['rating'], $b => $state[$b]['rating']];
            $s = RatingLedgerService::scoreForPlayerOne($m->score_one, $m->score_two);
            $c = $this->elo->calculate($state[$a]['rating'], $state[$b]['rating'], $s, $m->k_factor);
            foreach ([[$a, $c['delta_a'], $s], [$b, $c['delta_b'], 1 - $s]] as [$id, $delta, $actual]) {
                $state[$id]['rating'] = max(0, $state[$id]['rating'] + $delta);
                $state[$id]['peak'] = max($state[$id]['peak'], $state[$id]['rating']);
                $state[$id]['played']++;
                $state[$id][$actual === 1.0 ? 'wins' : ($actual === 0.0 ? 'losses' : 'draws')]++;
                $state[$id]['prov'] = max(0, $state[$id]['prov'] - 1);
            }
            if ($each) {
                $each($m, $before, [$a => $state[$a]['rating'], $b => $state[$b]['rating']]);
            }
        }

        return [$state, $start, $provisional];
    }
}
