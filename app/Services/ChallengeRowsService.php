<?php

namespace App\Services;

use App\Models\Challenge;
use App\Models\RankedMatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Linhas de desafio do ponto de vista de quem esta logado: adversario, placar (vitoria/derrota) e o estado em
 * palavras ("Confirmar placar", "Aguardando resposta"...). needs_me = falta uma acao sua.
 * Usado pela lista de Desafios e por "Minhas partidas" (as duas telas mostram o mesmo estado).
 */
class ChallengeRowsService
{
    public function query(User $user): Builder
    {
        $id = $user->ensurePlayerProfile()->id;

        return Challenge::query()->where(fn ($q) => $q->where('challenger_id', $id)->orWhere('opponent_id', $id))->with(['challenger', 'opponent', 'casualMatch']);
    }

    /** @return array<int, array> indexado pelo id do desafio */
    public function rows(User $user, $challenges): array
    {
        $me = $user->ensurePlayerProfile()->id;
        $ranked = RankedMatch::query()->with(['submissions' => fn ($q) => $q->where('status', 'pending')])
            ->where('source_type', 'challenge')->whereIn('source_id', $challenges->where('is_ranked', true)->pluck('id'))->get()->keyBy('source_id');
        $rows = [];
        foreach ($challenges as $c) {
            $mine = $c->challenger_id === $me;
            $r = $c->is_ranked ? $ranked->get($c->id) : null;
            $match = $c->casualMatch;
            [$myScore, $theirScore] = $r
                ? [$mine ? $r->score_one : $r->score_two, $mine ? $r->score_two : $r->score_one]
                : [$mine ? $match?->challenger_games : $match?->opponent_games, $mine ? $match?->opponent_games : $match?->challenger_games];
            $status = $r?->status ?? $match?->status;
            $reportedByMe = (int) ($r ? $r->submissions->first()?->submitted_by : $match?->reported_by) === $user->id;

            [$label, $tone, $needsMe] = match (true) {
                $c->status === 'pending' => $mine ? ['Aguardando resposta', null, false] : ['Convite recebido', 'warning', true],
                $c->status === 'accepted' && $status === 'reported' => $reportedByMe ? ['Aguardando confirmação', null, false] : ['Confirmar placar', 'warning', true],
                $c->status === 'accepted' && $status === 'disputed' => ['Placar contestado', 'danger', true],
                $c->status === 'accepted' => ['Jogar e enviar placar', 'info', true],
                $c->status === 'completed' && $status === 'invalidated' => ['Anulada', null, false],
                $c->status === 'completed' && $myScore !== null => $myScore > $theirScore ? ['Vitória '.$myScore.'–'.$theirScore, 'success', false]
                    : ($myScore < $theirScore ? ['Derrota '.$myScore.'–'.$theirScore, 'danger', false] : ['Empate '.$myScore.'–'.$theirScore, null, false]),
                default => [$c->statusLabel(), null, false],
            };
            // Resultado que conta na campanha: serie concluida, confirmada e nao anulada.
            $result = $c->status === 'completed' && $status !== 'invalidated' && $myScore !== null
                ? ($myScore > $theirScore ? 'w' : ($myScore < $theirScore ? 'l' : 'd')) : null;
            $rows[$c->id] = ['challenge' => $c, 'opponent' => $mine ? $c->opponent : $c->challenger, 'invited_me' => ! $mine,
                'label' => $label, 'tone' => $tone, 'needs_me' => $needsMe, 'result' => $result,
                'date' => $r?->confirmed_at ?? $match?->confirmed_at ?? $c->updated_at];
        }

        return $rows;
    }
}
