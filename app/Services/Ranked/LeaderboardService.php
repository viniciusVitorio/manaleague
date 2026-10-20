<?php

namespace App\Services\Ranked;

use App\Models\Club;
use App\Models\PlayerRating;
use App\Models\RankedSeason;
use Illuminate\Support\Collection;

class LeaderboardService
{
    public function __construct(private readonly RankTierResolver $tiers) {}

    /**
     * Desempate: rating, vitorias, partidas jogadas (mais amostra), menor id do perfil.
     * $all = true inclui quem ainda nao tem o minimo (para snapshot), mas sem posicao.
     *
     * @return Collection<int,array{rank:?int,rating:PlayerRating,tier:array,eligible:bool,name:string}>
     */
    public function rows(RankedSeason $season, string $format, ?int $clubId = null, bool $all = false): Collection
    {
        $min = (int) $season->setting('leaderboard_min_games');
        $ratings = PlayerRating::query()->with('profile')->where(['season_id' => $season->id, 'format' => $format])
            ->where('played', '>', 0)
            ->when($clubId, fn ($q) => $q->whereHas('profile', fn ($p) => $p->whereIn('account_user_id', Club::find($clubId)?->members()->pluck('users.id') ?? [])))
            ->orderByDesc('rating')->orderByDesc('wins')->orderByDesc('played')->orderBy('player_profile_id')->get();
        $rank = 0;

        return $ratings->map(function (PlayerRating $r) use (&$rank, $min) {
            $eligible = $r->played >= $min;

            return ['rank' => $eligible ? ++$rank : null, 'rating' => $r, 'tier' => $this->tiers->resolve($r->rating), 'eligible' => $eligible,
                'name' => $r->profile?->ranked_public ? $r->profile->nickname : 'Jogador oculto'];
        })->filter(fn ($row) => $all || $row['eligible'])->values();
    }
}
