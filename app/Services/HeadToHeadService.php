<?php

namespace App\Services;

use App\Models\Challenge;
use App\Models\GameMatch;
use App\Models\PlayerProfile;
use App\Models\RankedMatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Confronto direto entre dois perfis, do ponto de vista de $me.
 * Soma partidas 1v1 de torneio, desafios casuais confirmados e Ranked confirmado.
 * Ranked vindo de torneio ranqueado já entra pela partida do torneio (não conta duas vezes).
 * Mesas de Commander (pods) ficam de fora: não há vencedor "contra" um adversário só.
 */
class HeadToHeadService
{
    /** @return array{played: int, wins: int, losses: int, draws: int, last: ?array{date: Carbon, kind: string, me: int, them: int, result: string}} */
    public function between(PlayerProfile $me, PlayerProfile $them): array
    {
        $series = $this->tournamentMatches($me, $them)
            ->concat($this->casualChallenges($me, $them))
            ->concat($this->rankedMatches($me, $them));

        $last = $series->sortByDesc(fn (array $s) => $s['date']->getTimestamp())->first();

        return [
            'played' => $series->count(),
            'wins' => $series->where('result', 'win')->count(),
            'losses' => $series->where('result', 'loss')->count(),
            'draws' => $series->where('result', 'draw')->count(),
            'last' => $last,
        ];
    }

    private function tournamentMatches(PlayerProfile $me, PlayerProfile $them): Collection
    {
        return GameMatch::query()
            ->with(['tournament:id,name', 'playerOne:id,player_profile_id', 'playerTwo:id,player_profile_id'])
            ->where('status', GameMatch::STATUS_FINISHED)->whereNull('pod_player_ids')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereHas('playerOne', fn ($p) => $p->where('player_profile_id', $me->id))->whereHas('playerTwo', fn ($p) => $p->where('player_profile_id', $them->id)))
                ->orWhere(fn ($q) => $q->whereHas('playerOne', fn ($p) => $p->where('player_profile_id', $them->id))->whereHas('playerTwo', fn ($p) => $p->where('player_profile_id', $me->id))))
            ->get()
            ->map(function (GameMatch $m) use ($me) {
                $one = $m->playerOne->player_profile_id === $me->id;

                return $this->entry($m->played_at ?? $m->updated_at, $m->tournament?->name ?? 'Torneio',
                    (int) ($one ? $m->player_one_games : $m->player_two_games), (int) ($one ? $m->player_two_games : $m->player_one_games));
            });
    }

    private function casualChallenges(PlayerProfile $me, PlayerProfile $them): Collection
    {
        return Challenge::query()->with('casualMatch')->where('is_ranked', false)
            ->where(fn ($q) => $q->where(['challenger_id' => $me->id, 'opponent_id' => $them->id])->orWhere(fn ($q) => $q->where(['challenger_id' => $them->id, 'opponent_id' => $me->id])))
            ->whereHas('casualMatch', fn ($q) => $q->where('status', 'confirmed'))
            ->get()
            ->map(function (Challenge $c) use ($me) {
                $mine = $c->challenger_id === $me->id;

                return $this->entry($c->casualMatch->confirmed_at ?? $c->casualMatch->updated_at, 'Desafio casual',
                    (int) ($mine ? $c->casualMatch->challenger_games : $c->casualMatch->opponent_games), (int) ($mine ? $c->casualMatch->opponent_games : $c->casualMatch->challenger_games));
            });
    }

    private function rankedMatches(PlayerProfile $me, PlayerProfile $them): Collection
    {
        return RankedMatch::query()->where('status', 'confirmed')->where('source_type', '!=', 'tournament_match')
            ->where(fn ($q) => $q->where(['player_one_profile_id' => $me->id, 'player_two_profile_id' => $them->id])->orWhere(fn ($q) => $q->where(['player_one_profile_id' => $them->id, 'player_two_profile_id' => $me->id])))
            ->get()
            ->map(function (RankedMatch $m) use ($me) {
                $one = $m->player_one_profile_id === $me->id;

                return $this->entry($m->confirmed_at ?? $m->played_at ?? $m->updated_at, 'Ranked '.$m->format,
                    (int) ($one ? $m->score_one : $m->score_two), (int) ($one ? $m->score_two : $m->score_one));
            });
    }

    private function entry(Carbon $date, string $kind, int $me, int $them): array
    {
        return ['date' => $date, 'kind' => $kind, 'me' => $me, 'them' => $them,
            'result' => $me > $them ? 'win' : ($me < $them ? 'loss' : 'draw')];
    }
}
