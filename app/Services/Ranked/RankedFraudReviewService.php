<?php

namespace App\Services\Ranked;

use App\Models\RankedMatch;
use App\Models\RankedReviewFlag;

/** Marca padroes atipicos para revisao humana. Nunca pune automaticamente. */
class RankedFraudReviewService
{
    public function review(RankedMatch $m): void
    {
        [$a, $b] = [min($m->player_one_profile_id, $m->player_two_profile_id), max($m->player_one_profile_id, $m->player_two_profile_id)];
        $pair = RankedMatch::query()->where(['season_id' => $m->season_id, 'format' => $m->format, 'status' => 'confirmed', 'is_ranked' => true])
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('player_one_profile_id', $a)->where('player_two_profile_id', $b))
                ->orWhere(fn ($q) => $q->where('player_one_profile_id', $b)->where('player_two_profile_id', $a)))
            ->where('confirmed_at', '>=', now()->subDays(7))->orderBy('confirmed_at')->orderBy('id')->get();

        if ($pair->count() >= 8) {
            $this->flag($m, $a, $b, 'pair_volume', "{$pair->count()} partidas ranqueadas entre a mesma dupla em 7 dias.");
        }
        $winners = $pair->map(fn ($x) => $x->score_one === $x->score_two ? 0 : ($x->score_one > $x->score_two ? $x->player_one_profile_id : $x->player_two_profile_id))->values();
        if ($winners->count() >= 6) {
            $six = $winners->slice(-6)->values();
            $alternating = $six->every(fn ($w, $i) => $w !== 0 && ($i === 0 || $w !== $six[$i - 1]));
            if ($alternating) {
                $this->flag($m, $a, $b, 'alternating', 'Vencedor alterna a cada partida nas últimas 6.');
            }
        }
        $last = $winners->slice(-6);
        if ($last->count() >= 6 && $last->unique()->count() === 1 && $last->first() !== 0) {
            $this->flag($m, $a, $b, 'one_sided', 'O mesmo jogador venceu as últimas 6 partidas da dupla (possível transferência de pontos).');
        }
    }

    private function flag(RankedMatch $m, int $a, int $b, string $reason, string $details): void
    {
        RankedReviewFlag::query()->firstOrCreate(
            ['season_id' => $m->season_id, 'format' => $m->format, 'profile_a_id' => $a, 'profile_b_id' => $b, 'reason' => $reason],
            ['details' => $details, 'status' => 'open'],
        );
    }
}
