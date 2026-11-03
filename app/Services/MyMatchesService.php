<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\MatchResultSubmission;
use App\Models\Player;
use App\Models\RankedMatch;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Minhas partidas": tudo o que a conta jogou (torneio, desafio 1v1 casual/ranqueado e Ranked do matchmaking),
 * sempre do ponto de vista de quem ve (voce a esquerda, placar seu–dele). "Em andamento" traz o que precisa
 * de voce primeiro; o historico e uma linha do tempo unica (ou, no filtro Torneios, agrupado por torneio).
 */
class MyMatchesService
{
    public const FILTERS = ['todas' => 'Todas', 'torneios' => 'Torneios', 'desafios' => 'Desafios', 'ranked' => 'Ranked'];

    private const RELATIONS = ['tournament.players.profile', 'playerOne.profile', 'playerTwo.profile'];

    public function for(User $user, string $filter = 'todas', int $perPage = 30): array
    {
        $filter = array_key_exists($filter, self::FILTERS) ? $filter : 'todas';
        $playerIds = Player::whereHas('profile', fn ($p) => $p->where('account_user_id', $user->id))
            ->pluck('id')->map(fn ($v) => (int) $v)->all();

        // Torneio
        $matches = $this->mine($playerIds)->with(self::RELATIONS)->orderBy('round')->orderBy('id')->get();
        $submissions = MatchResultSubmission::whereIn('match_id', $matches->pluck('id')->all())->get()->keyBy('match_id');
        $tournament = $matches->map(fn (GameMatch $m) => $this->row($m, $playerIds, $submissions->get($m->id), $user) + [
            'kind' => 'tournament', 'open' => $m->status === GameMatch::STATUS_PENDING,
            'date' => $m->played_at ?? $m->updated_at,
            'result' => $m->status === GameMatch::STATUS_FINISHED && ! $m->pod_player_ids ? $this->resultOf($m, $playerIds) : null,
        ]);

        // Desafios (casuais e ranqueados): as mesmas linhas da tela de Desafios.
        app(ChallengeService::class)->expire();
        $rowsService = app(ChallengeRowsService::class);
        $challenges = collect($rowsService->rows($user, $rowsService->query($user)->get()))->values()->map(fn (array $r) => array_merge($r, [
            'kind' => 'challenge', 'open' => in_array($r['challenge']->status, ['pending', 'accepted'], true),
            'href' => route('challenges.show', $r['challenge']), 'tone' => $r['tone'] ?? 'neutral',
        ]));

        // Ranked: desafios ranqueados, torneios ranqueados e (legado) partidas do matchmaking.
        $ranked = collect()->concat($challenges->filter(fn (array $r) => $r['challenge']->is_ranked))
            ->concat($tournament->filter(fn (array $r) => (bool) $r['tournament']->ranked))
            ->concat($matchmaking = $this->rankedRows($user));

        $all = collect()->concat($tournament)->concat($challenges)->concat($matchmaking);
        $shown = match ($filter) {
            'torneios' => $tournament, 'desafios' => $challenges, 'ranked' => $ranked, default => $all,
        };

        $open = $shown->where('open', true)->sortBy(fn (array $r) => ($r['needs_me'] ? 0 : 2) + ($r['kind'] === 'tournament' ? 0 : 1))->values();
        // Mais recente primeiro; no mesmo dia, a rodada/serie mais nova primeiro.
        $key = fn (array $r) => [$r['date']?->format('Ymd') ?? '0', $r['kind'] === 'tournament' ? $r['match']->round : 0, $r['date']?->getTimestamp() ?? 0];
        $past = $shown->where('open', false)->sort(fn (array $a, array $b) => $key($b) <=> $key($a))->values();
        $page = Paginator::resolveCurrentPage();
        $history = new LengthAwarePaginator($past->forPage($page, $perPage)->values(), $past->count(), $perPage, $page,
            ['path' => Paginator::resolveCurrentPath(), 'query' => array_filter(['tipo' => $filter === 'todas' ? null : $filter])]);

        $tally = fn (Collection $rows) => $this->tally($rows->pluck('result')->filter());

        return [
            'filter' => $filter,
            'filters' => self::FILTERS,
            'counts' => ['todas' => $all->count(), 'torneios' => $tournament->count(), 'desafios' => $challenges->count(), 'ranked' => $ranked->count()],
            'open' => $open,
            'history' => $history,
            // Filtro Torneios: historico agrupado por torneio, com a campanha de cada um.
            'groups' => $filter === 'torneios' ? collect($history->items())->groupBy(fn (array $r) => $r['match']->tournament_id) : collect(),
            'records' => $tournament->groupBy(fn (array $r) => $r['match']->tournament_id)->map($tally),
            'total' => $tally($shown),
            'playerIds' => $playerIds,
        ];
    }

    private function rankedRows(User $user): Collection
    {
        $profile = $user->playerProfile;
        if (! $profile) {
            return collect();
        }

        return RankedMatch::query()->with(['playerOne', 'playerTwo', 'submissions' => fn ($q) => $q->where('status', 'pending')])
            ->where('source_type', 'matchmaking')
            ->where(fn ($q) => $q->where('player_one_profile_id', $profile->id)->orWhere('player_two_profile_id', $profile->id))
            ->get()->map(function (RankedMatch $m) use ($profile, $user) {
                $one = (int) $m->player_one_profile_id === (int) $profile->id;
                [$mine, $theirs] = $one ? [$m->score_one, $m->score_two] : [$m->score_two, $m->score_one];
                $reportedByMe = (int) $m->submissions->first()?->submitted_by === (int) $user->id;
                [$label, $tone, $needsMe] = match ($m->status) {
                    'pending' => ['Jogar e enviar placar', 'info', true],
                    'reported' => $reportedByMe ? ['Aguardando confirmação', 'neutral', false] : ['Confirmar placar', 'warning', true],
                    'disputed' => ['Em disputa', 'danger', true],
                    'confirmed' => $mine > $theirs ? ['Vitória '.$mine.'–'.$theirs, 'success', false]
                        : ($mine < $theirs ? ['Derrota '.$mine.'–'.$theirs, 'danger', false] : ['Empate '.$mine.'–'.$theirs, 'neutral', false]),
                    'invalidated' => ['Anulada', 'neutral', false],
                    default => [$m->statusLabel(), 'neutral', false],
                };

                return ['kind' => 'ranked', 'ranked' => $m, 'opponent' => $one ? $m->playerTwo : $m->playerOne,
                    'open' => in_array($m->status, ['pending', 'reported', 'disputed'], true),
                    'label' => $label, 'tone' => $tone, 'needs_me' => $needsMe, 'href' => route('ranked.matches.show', $m),
                    'date' => $m->confirmed_at ?? $m->played_at ?? $m->updated_at,
                    'result' => $m->status === 'confirmed' ? ($mine > $theirs ? 'w' : ($mine < $theirs ? 'l' : 'd')) : null];
            });
    }

    /** @param list<int> $playerIds */
    private function resultOf(GameMatch $m, array $playerIds): string
    {
        $iAmTwo = ! in_array((int) $m->player_one_id, $playerIds, true);
        $mine = (int) ($iAmTwo ? $m->player_two_games : $m->player_one_games);
        $theirs = (int) ($iAmTwo ? $m->player_one_games : $m->player_two_games);

        return $mine > $theirs ? 'w' : ($mine < $theirs ? 'l' : 'd');
    }

    private function tally(Collection $results): array
    {
        $r = ['w' => $results->filter(fn ($x) => $x === 'w')->count(), 'l' => $results->filter(fn ($x) => $x === 'l')->count(), 'd' => $results->filter(fn ($x) => $x === 'd')->count()];
        $r['played'] = $r['w'] + $r['l'] + $r['d'];
        $r['label'] = $r['w'].'–'.$r['l'].'–'.$r['d'];

        return $r;
    }

    /** @param list<int> $playerIds */
    private function mine(array $playerIds): Builder
    {
        return GameMatch::query()->where(function ($q) use ($playerIds) {
            $q->whereIn('player_one_id', $playerIds)->orWhereIn('player_two_id', $playerIds);
            // Mesas Commander guardam todos os assentos num JSON ([3,7,9]): casa o id como elemento inteiro.
            foreach ($playerIds as $pid) {
                foreach (["[$pid]", "[$pid,%", "%,$pid,%", "%,$pid]"] as $pattern) {
                    $q->orWhere('pod_player_ids', 'like', $pattern);
                }
            }
        });
    }

    /** @param list<int> $playerIds */
    private function row(GameMatch $m, array $playerIds, ?MatchResultSubmission $submission, User $user): array
    {
        $iAmTwo = ! in_array((int) $m->player_one_id, $playerIds, true) && in_array((int) $m->player_two_id, $playerIds, true);
        $me = $iAmTwo ? $m->playerTwo : $m->playerOne;
        $opponent = $iAmTwo ? $m->playerOne : $m->playerTwo;
        $myGames = $iAmTwo ? $m->player_two_games : $m->player_one_games;
        $theirGames = $iAmTwo ? $m->player_one_games : $m->player_two_games;
        $pod = (bool) $m->pod_player_ids;

        $row = [
            'match' => $m, 'tournament' => $m->tournament, 'me' => $me, 'opponent' => $opponent, 'iAmTwo' => $iAmTwo,
            'pod' => $pod, 'podPlayers' => collect(), 'myPlace' => null,
            'submission' => $submission, 'needs_me' => false, 'action' => null,
            'label' => null, 'tone' => 'neutral', 'note' => null,
            'stage' => match ($m->stage) {
                GameMatch::STAGE_FINAL => 'Final',
                GameMatch::STAGE_THIRD_PLACE => 'Disputa de 3º lugar',
                default => 'Rodada '.$m->round,
            },
        ];

        if ($pod) {
            $row['podPlayers'] = $m->tournament->players->whereIn('id', $m->pod_player_ids)->values();
            $mySeat = $row['podPlayers']->first(fn ($p) => in_array((int) $p->id, $playerIds, true));
            $row['me'] = $mySeat;
            $row['myPlace'] = $mySeat ? (($m->pod_scores ?? [])[$mySeat->id] ?? null) : null;
            if ($m->status === GameMatch::STATUS_PENDING) {
                [$row['label'], $row['tone']] = ['Em jogo', 'info'];
                $row['note'] = 'Mesa Commander · o organizador lança as posições ao fim da mesa';
            } else {
                $row['label'] = $row['myPlace'] ? $row['myPlace'].'º lugar' : 'Encerrada';
                $row['tone'] = (int) $row['myPlace'] === 1 ? 'success' : 'neutral';
                $row['note'] = $row['myPlace'] ? 'Mesa Commander · você ficou em '.$row['myPlace'].'º' : 'Mesa Commander';
            }

            return $row;
        }

        if ($m->status === GameMatch::STATUS_BYE) {
            [$row['label'], $row['tone'], $row['note']] = ['Folga', 'neutral', 'Vitória automática'];

            return $row;
        }

        if ($m->status === GameMatch::STATUS_FINISHED) {
            $row['score'] = ($myGames ?? 0).'–'.($theirGames ?? 0);
            [$row['label'], $row['tone']] = match (true) {
                $myGames > $theirGames => ['Vitória '.$row['score'], 'success'],
                $myGames < $theirGames => ['Derrota '.$row['score'], 'danger'],
                default => ['Empate '.$row['score'], 'neutral'],
            };

            return $row;
        }

        // Em andamento (partida 1x1 com adversario).
        if ($submission) {
            $sMine = $iAmTwo ? $submission->player_two_games : $submission->player_one_games;
            $sTheirs = $iAmTwo ? $submission->player_one_games : $submission->player_two_games;
            $row['score'] = $sMine.'–'.$sTheirs;
            $row['claim'] = $sMine > $sTheirs ? 'vitória sua' : ($sMine < $sTheirs ? 'derrota sua' : 'empate');
            $fromMe = (int) $submission->submitted_by === (int) $user->id;
            if ($submission->status === 'pending' && ! $fromMe) {
                [$row['label'], $row['tone'], $row['needs_me'], $row['action']] = ['Confirme o placar', 'warning', true, 'review'];
            } else {
                [$row['label'], $row['tone']] = match ($submission->status) {
                    'pending' => ['Aguardando confirmação', 'info'],
                    'disputed' => ['Contestado · aguardando organizador', 'warning'],
                    'confirmed' => ['Confirmado', 'success'],
                    'resolved' => ['Resolvido pelo organizador', 'neutral'],
                    default => [$submission->status, 'neutral'],
                };
            }
        } elseif ($opponent) {
            [$row['label'], $row['tone'], $row['needs_me'], $row['action']] = ['Envie o resultado', 'warning', true, 'submit'];
        } else {
            [$row['label'], $row['tone']] = ['Aguardando', 'neutral'];
        }

        return $row;
    }
}
