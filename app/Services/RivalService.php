<?php

namespace App\Services;

use App\Models\Challenge;
use App\Models\PlayerProfile;
use App\Models\RankedMatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rival: o amigo que a pessoa mais enfrentou em desafios (casuais e ranqueados), com placares confirmados.
 * Torneio não conta: lá o adversário vem do emparelhamento, não é necessariamente um rival 1v1.
 * Empate no número de desafios: vale o mais recente.
 */
class RivalService
{
    /** @return ?array{profile: PlayerProfile, played: int, wins: int, losses: int, draws: int, last: Carbon} */
    public function for(PlayerProfile $profile): ?array
    {
        $series = $this->casual($profile)->concat($this->ranked($profile));
        if ($series->isEmpty()) return null;

        $top = $series->groupBy('opponent')
            ->map(fn (Collection $games, int $opponent) => [
                'opponent' => $opponent,
                'played' => $games->count(),
                'wins' => $games->filter(fn ($g) => $g['me'] > $g['them'])->count(),
                'losses' => $games->filter(fn ($g) => $g['me'] < $g['them'])->count(),
                'draws' => $games->filter(fn ($g) => $g['me'] === $g['them'])->count(),
                'last' => $games->max('date'),
            ])
            ->sort(fn (array $a, array $b) => [$b['played'], $b['last']] <=> [$a['played'], $a['last']])
            ->first();

        $rival = PlayerProfile::query()->find($top['opponent']);

        return $rival ? ['profile' => $rival] + $top : null;
    }

    private function casual(PlayerProfile $profile): Collection
    {
        return Challenge::query()->with('casualMatch')->where('is_ranked', false)
            ->where(fn ($q) => $q->where('challenger_id', $profile->id)->orWhere('opponent_id', $profile->id))
            ->whereHas('casualMatch', fn ($q) => $q->where('status', 'confirmed'))
            ->get()
            ->map(function (Challenge $c) use ($profile) {
                $mine = $c->challenger_id === $profile->id;
                $match = $c->casualMatch;

                return $this->entry($mine ? $c->opponent_id : $c->challenger_id, $match->confirmed_at ?? $match->updated_at,
                    $mine ? $match->challenger_games : $match->opponent_games, $mine ? $match->opponent_games : $match->challenger_games);
            });
    }

    /** Ranked que nasceu de um desafio (não de torneio ranqueado). */
    private function ranked(PlayerProfile $profile): Collection
    {
        return RankedMatch::query()->where('status', 'confirmed')->where('source_type', 'challenge')
            ->where(fn ($q) => $q->where('player_one_profile_id', $profile->id)->orWhere('player_two_profile_id', $profile->id))
            ->get()
            ->map(function (RankedMatch $m) use ($profile) {
                $one = $m->player_one_profile_id === $profile->id;

                return $this->entry($one ? $m->player_two_profile_id : $m->player_one_profile_id, $m->confirmed_at ?? $m->played_at ?? $m->updated_at,
                    $one ? $m->score_one : $m->score_two, $one ? $m->score_two : $m->score_one);
            });
    }

    private function entry(int $opponent, ?Carbon $date, ?int $me, ?int $them): array
    {
        return ['opponent' => $opponent, 'date' => $date ?? now(), 'me' => (int) $me, 'them' => (int) $them];
    }
}
