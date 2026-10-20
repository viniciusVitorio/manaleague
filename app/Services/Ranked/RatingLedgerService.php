<?php

namespace App\Services\Ranked;

use App\Models\PlayerProfile;
use App\Models\PlayerRating;
use App\Models\RankedMatch;
use App\Models\RankedSeason;
use App\Models\RatingTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Unico lugar que altera rating. Sempre dentro de transacao, com os dois
 * registros travados na mesma ordem (id do perfil) e indice unico no ledger.
 */
class RatingLedgerService
{
    public function __construct(private readonly EloCalculator $elo) {}

    public function ratingFor(RankedSeason $season, string $format, PlayerProfile|int $profile, bool $lock = false): PlayerRating
    {
        $profileId = $profile instanceof PlayerProfile ? $profile->id : $profile;
        $query = PlayerRating::query()->where(['season_id' => $season->id, 'format' => $format, 'player_profile_id' => $profileId]);
        if ($lock) {
            $query->lockForUpdate();
        }
        $rating = $query->first();
        if ($rating) {
            return $rating;
        }

        $start = $season->formatConfig($format)?->starting_rating ?? (int) config('ranked.defaults.starting_rating');

        return PlayerRating::query()->firstOrCreate(
            ['season_id' => $season->id, 'format' => $format, 'player_profile_id' => $profileId],
            ['rating' => $start, 'peak_rating' => $start, 'provisional_games_remaining' => (int) $season->setting('provisional_games')],
        );
    }

    /** 1 = jogador um venceu, 0 = perdeu, 0.5 = empate. */
    public static function scoreForPlayerOne(int $scoreOne, int $scoreTwo): float
    {
        return $scoreOne === $scoreTwo ? 0.5 : ($scoreOne > $scoreTwo ? 1.0 : 0.0);
    }

    /**
     * Aplica o Elo de uma partida confirmada exatamente uma vez.
     *
     * @return array<int,int> delta por player_profile_id
     */
    public function applyMatch(RankedMatch $match): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('applyMatch exige transação.');
        }
        if ($match->status !== 'confirmed' || ! $match->is_ranked) {
            throw new LogicException('Somente partidas ranqueadas confirmadas alteram rating.');
        }
        if (RatingTransaction::query()->where('ranked_match_id', $match->id)->where('event_type', 'match')->exists()) {
            return []; // replay de request ou job: sem novo delta
        }
        $season = $match->season()->with('formats')->firstOrFail();
        if (! in_array($season->status, ['active', 'closing'], true)) {
            throw new LogicException('Temporada encerrada não recebe pontuação.');
        }

        $ids = [$match->player_one_profile_id, $match->player_two_profile_id];
        sort($ids);
        $ratings = [];
        foreach ($ids as $id) {
            $this->ratingFor($season, $match->format, $id); // garante que existe antes do lock
        }
        foreach ($ids as $id) {
            $ratings[$id] = $this->ratingFor($season, $match->format, $id, true);
        }
        $one = $ratings[$match->player_one_profile_id];
        $two = $ratings[$match->player_two_profile_id];
        $s = self::scoreForPlayerOne($match->score_one, $match->score_two);
        $calc = $this->elo->calculate($one->rating, $two->rating, $s, $match->k_factor);
        $at = $match->confirmed_at ?? now();

        $deltas = [];
        foreach ([[$one, $calc['delta_a'], $calc['expected_a'], $s], [$two, $calc['delta_b'], $calc['expected_b'], 1 - $s]] as [$rating, $delta, $expected, $actual]) {
            $before = $rating->rating;
            $after = $before + $delta;
            $floor = $after < 0;
            if ($floor) {
                $after = 0;
            } // piso auditado: soma zero deixa de valer apenas neste caso
            RatingTransaction::create([
                'ranked_match_id' => $match->id, 'season_id' => $season->id, 'format' => $match->format,
                'player_profile_id' => $rating->player_profile_id, 'rating_before' => $rating->rating, 'rating_after' => $after,
                'delta' => $after - $rating->rating, 'expected_score' => round($expected, 4), 'actual_score' => $actual,
                'k_factor' => $match->k_factor, 'event_type' => 'match', 'correction_seq' => 0, 'floor_applied' => $floor,
                'rules_version' => $match->rules_version, 'effective_at' => $at,
            ]);
            $rating->forceFill([
                'rating' => $after,
                'peak_rating' => max($rating->peak_rating, $after),
                'played' => $rating->played + 1,
                'wins' => $rating->wins + ($actual === 1.0 ? 1 : 0),
                'losses' => $rating->losses + ($actual === 0.0 ? 1 : 0),
                'draws' => $rating->draws + ($actual === 0.5 ? 1 : 0),
                'provisional_games_remaining' => max(0, $rating->provisional_games_remaining - 1),
                'version' => $rating->version + 1,
            ])->save();
            $deltas[$rating->player_profile_id] = $after - $before;
        }
        Log::info('ranked.rating_applied', ['ranked_match_id' => $match->id, 'season_id' => $season->id, 'format' => $match->format, 'k' => $match->k_factor, 'deltas' => $deltas]);

        return $deltas;
    }
}
