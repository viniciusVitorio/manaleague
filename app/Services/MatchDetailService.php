<?php

namespace App\Services;

use App\Models\Challenge;
use App\Models\GameMatch;
use App\Models\MatchResultSubmission;
use App\Models\Player;
use App\Models\PlayerProfile;
use App\Models\RankedMatch;
use App\Models\RatingTransaction;
use App\Models\User;
use App\Policies\MatchResultSubmissionPolicy;
use App\Services\Ranked\RankTierResolver;
use App\Services\Ranked\RatingReplayService;
use Carbon\CarbonInterface;

/**
 * Resumo de uma partida num lugar só (Minhas partidas → clique na linha): quem jogou, deck de cada um, placar,
 * vencedor, o ELO que cada um ganhou ou perdeu (partida ranqueada) e o gráfico de vida, quando a partida foi jogada
 * com o contador. Mesmo formato para torneio, desafio e Ranked.
 * Quem não pode ver a partida recebe null (a rota responde 404, a URL é adivinhável).
 */
class MatchDetailService
{
    public const TYPES = ['tournament', 'challenge', 'ranked'];

    public function __construct(private readonly LifeLogService $logs, private readonly RankTierResolver $tiers, private readonly RatingReplayService $replay) {}

    /**
     * @return array{type: string, kind: string, ranked: bool, context: list<string>, date: CarbonInterface|null,
     *     status: string|null, pod: bool, players: list<array{name: string, profile: PlayerProfile|null, deck: array{name: string, colors: string|null, url: string|null}|null,
     *     games: int|null, place: int|null, winner: bool, me: bool, elo: array|null}>, result: string|null, eloNote: string|null, played: bool, chart: array|null,
     *     link: array{url: string, label: string}|null}|null
     */
    public function for(string $type, int $id, User $user): ?array
    {
        return match ($type) {
            'tournament' => $this->tournament($id, $user),
            'challenge' => $this->challenge($id, $user),
            'ranked' => $this->ranked($id, $user),
            default => null,
        };
    }

    private function tournament(int $id, User $user): ?array
    {
        $match = GameMatch::with(['tournament.players.profile', 'tournament.players.deck'])->find($id);
        if (! $match) {
            return null;
        }
        $t = $match->tournament;
        $organizer = $user->can('view', $t) || $user->can('update', $t);
        $mine = fn (?Player $p) => $p?->profile?->account_user_id === $user->id;
        $pod = (bool) $match->pod_player_ids;
        $seats = $pod
            ? collect($match->pod_player_ids)->map(fn ($pid) => $t->players->firstWhere('id', (int) $pid))->filter()->values()
            : collect([$t->players->firstWhere('id', $match->player_one_id), $t->players->firstWhere('id', $match->player_two_id)]);
        if (! $organizer && ! $seats->contains(fn ($p) => $mine($p)) && ! (new MatchResultSubmissionPolicy)->participate($user, $match)) {
            return null;
        }

        $finished = $match->status === GameMatch::STATUS_FINISHED;
        $submission = $finished ? null : MatchResultSubmission::where('match_id', $match->id)->latest('id')->first();
        if ($pod) {
            $places = $match->pod_scores ?? [];
            $players = $seats->map(fn (Player $p) => $this->seat($p->displayName(), $p->profile, $this->deck($p, $user), null,
                $finished ? (isset($places[$p->id]) ? (int) $places[$p->id] : null) : null, $mine($p)))
                ->sortBy(fn (array $s) => $s['place'] ?? 99)->values()->all();
        } else {
            [$one, $two] = $finished
                ? [$match->player_one_games, $match->player_two_games]
                : [$submission?->player_one_games, $submission?->player_two_games];
            $players = $seats->map(fn (?Player $p, int $i) => $this->seat($p?->displayName() ?? 'BYE', $p?->profile, $p ? $this->deck($p, $user) : null,
                [$one, $two][$i] !== null ? (int) [$one, $two][$i] : null, null, $mine($p)))->all();
        }

        $status = match (true) {
            $match->status === GameMatch::STATUS_BYE => 'Folga · vitória automática',
            $finished => null,
            $submission?->status === 'disputed' => 'Placar contestado · aguardando o organizador',
            $submission !== null => 'Placar enviado · aguardando confirmação',
            default => 'Ainda não jogada',
        };
        $stage = match ($match->stage) {
            GameMatch::STAGE_FINAL => 'Final',
            GameMatch::STAGE_THIRD_PLACE => 'Disputa de 3º lugar',
            default => 'Rodada '.$match->round,
        };
        $link = $organizer
            ? ['url' => route('tournaments.show', $t), 'label' => 'Ver torneio']
            : ($t->public_slug ? ['url' => route('arena.public', $t->public_slug), 'label' => 'Ver torneio'] : null);

        // Torneio ranqueado: o ELO vem da partida ranqueada que nasce da partida do torneio.
        $ranked = $t->ranked && ! $pod ? RankedMatch::where(['source_type' => 'tournament_match', 'source_id' => $match->id])->first() : null;

        return $this->summary('tournament', $t->ranked ? 'Torneio ranqueado' : 'Torneio', (bool) $t->ranked,
            [$t->name, $pod ? 'Mesa Commander' : $stage, $t->format], $finished ? ($match->played_at ?? $match->updated_at) : null,
            $status, $pod, $players, $finished, $this->logs->chartFor('tournament', $match->id, $user), $link, $this->elo($ranked, $user));
    }

    private function challenge(int $id, User $user): ?array
    {
        $challenge = Challenge::with(['challenger', 'opponent', 'casualMatch'])->find($id);
        if (! $challenge || ! $challenge->involves($user)) {
            return null;
        }
        $ranked = $challenge->is_ranked ? RankedMatch::where(['source_type' => 'challenge', 'source_id' => $challenge->id])->first() : null;
        $match = $challenge->casualMatch;
        $state = $ranked?->status ?? $match?->status;
        $scored = in_array($state, ['reported', 'confirmed', 'disputed'], true);
        [$one, $two] = ! $scored ? [null, null]
            : ($ranked ? [$ranked->score_one, $ranked->score_two] : [$match->challenger_games, $match->opponent_games]);
        $players = [
            $this->seat($challenge->challenger->displayName(), $challenge->challenger, null, $one, null, $challenge->challenger->account_user_id === $user->id),
            $this->seat($challenge->opponent->displayName(), $challenge->opponent, null, $two, null, $challenge->opponent->account_user_id === $user->id),
        ];
        $status = match (true) {
            $state === 'invalidated' => 'Anulada',
            $state === 'confirmed' => $this->autoConfirmed($ranked ?? $match),
            $state === 'reported' => 'Placar enviado · aguardando confirmação',
            $state === 'disputed' => 'Placar contestado',
            default => $challenge->statusLabel(),
        };
        $chart = $this->logs->chartFor($ranked ? 'ranked' : 'challenge', $ranked ? $ranked->id : $challenge->id, $user);

        return $this->summary('challenge', $challenge->is_ranked ? 'Desafio ranqueado' : 'Desafio casual', (bool) $challenge->is_ranked,
            [$challenge->format, 'MD'.$challenge->best_of], $scored ? ($ranked?->confirmed_at ?? $match?->confirmed_at ?? $challenge->updated_at) : null,
            $status, false, $players, $state === 'confirmed', $chart, null, $this->elo($ranked, $user));
    }

    private function ranked(int $id, User $user): ?array
    {
        $match = config('ranked.enabled') ? RankedMatch::with(['playerOne', 'playerTwo'])->find($id) : null;
        if (! $match || ! $user->can('view', $match)) {
            return null;
        }
        $scored = in_array($match->status, ['reported', 'confirmed', 'disputed'], true);
        $players = [
            $this->seat($match->playerOne->displayName(), $match->playerOne, null, $scored ? $match->score_one : null, null, $match->playerOne->account_user_id === $user->id),
            $this->seat($match->playerTwo->displayName(), $match->playerTwo, null, $scored ? $match->score_two : null, null, $match->playerTwo->account_user_id === $user->id),
        ];
        $status = match ($match->status) {
            'confirmed' => $this->autoConfirmed($match),
            'reported' => 'Placar enviado · aguardando confirmação',
            default => $match->statusLabel(),
        };

        return $this->summary('ranked', 'Ranked', true, [$match->format, 'MD'.$match->best_of], $scored ? ($match->confirmed_at ?? $match->played_at ?? $match->updated_at) : null,
            $status, false, $players, $match->status === 'confirmed', $this->logs->chartFor('ranked', $match->id, $user),
            ['url' => route('ranked.matches.show', $match), 'label' => 'Abrir partida ranqueada'], $this->elo($match, $user));
    }

    /**
     * ELO da partida ranqueada, por perfil: antes, depois, quanto mudou e a divisão (subiu/caiu). Só para quem pode abrir
     * a partida ranqueada (os dois jogadores e a moderação), como na página dela. Sem lançamento (placar ainda não
     * confirmado, contestado ou anulado), vem só a nota explicando por que não há pontos.
     * Se o formato já teve correção (placar invertido, partida anulada), o lançamento original pode estar velho: o valor
     * vem do recálculo em ordem (RatingReplayService::worth), o mesmo que a correção aplicou.
     *
     * @return array{byProfile: array<int, array>, note: string|null}
     */
    private function elo(?RankedMatch $match, User $user): array
    {
        if (! $match || ! $match->is_ranked || ! $user->can('view', $match)) {
            return ['byProfile' => [], 'note' => null];
        }
        $note = match ($match->status) {
            'pending', 'reported' => 'Os pontos de ELO entram quando o placar for confirmado.',
            'disputed' => 'Pontos de ELO congelados até a moderação resolver.',
            'invalidated' => 'Partida anulada: os pontos de ELO foram desfeitos.',
            default => null,
        };
        if ($match->status !== 'confirmed') {
            return ['byProfile' => [], 'note' => $note];
        }

        $applied = $match->transactions()->where('event_type', 'match')->get()
            ->mapWithKeys(fn (RatingTransaction $tx) => [$tx->player_profile_id => ['before' => $tx->rating_before, 'after' => $tx->rating_after]])->all();
        if (! $applied) {
            return ['byProfile' => [], 'note' => null]; // confirmada sem pontos (ex.: temporada já encerrada)
        }
        $corrected = RatingTransaction::where(['season_id' => $match->season_id, 'format' => $match->format, 'event_type' => 'correction'])->exists();
        $ratings = $corrected ? ($this->replay->worth($match) ?: $applied) : $applied;

        $byProfile = [];
        foreach ($ratings as $profileId => ['before' => $from, 'after' => $to]) {
            [$before, $after] = [$this->tiers->resolve($from), $this->tiers->resolve($to)];
            $byProfile[$profileId] = ['before' => $from, 'after' => $to, 'delta' => $to - $from, 'division' => $after['division'], 'key' => $after['key'],
                'moved' => $before['division'] === $after['division'] ? null : ($to > $from ? 'up' : 'down')];
        }

        return ['byProfile' => $byProfile, 'note' => null];
    }

    /** Placar que o adversário não confirmou nem contestou no prazo: avisa que foi confirmado sozinho, e quando. */
    private function autoConfirmed($match): ?string
    {
        if (! $match?->auto_confirmed || ! $match->confirmed_at) {
            return null;
        }

        return 'Confirmado automaticamente em '.$match->confirmed_at->timezone(config('app.timezone'))->format('d/m/Y')
            .': o adversário não confirmou nem contestou em '.Challenge::CONFIRM_DAYS.' dias';
    }

    /** Vencedor e frase do resultado ("Ana venceu por 2–1", "Empate em 1–1", "Ana venceu a mesa"). */
    private function summary(string $type, string $kind, bool $ranked, array $context, $date, ?string $status, bool $pod, array $players,
        bool $played, ?array $chart, ?array $link, array $elo = ['byProfile' => [], 'note' => null]): array
    {
        $result = null;
        foreach ($players as $i => $p) {
            $players[$i]['elo'] = $p['profile'] ? ($elo['byProfile'][$p['profile']->id] ?? null) : null;
        }
        // Do ponto de vista de quem vê, como em Minhas partidas: você à esquerda (o organizador vê na ordem da partida).
        if (! $pod && $players[1]['me'] && ! $players[0]['me']) {
            $players = [$players[1], $players[0]];
        }
        if ($pod) {
            foreach ($players as $i => $p) {
                $players[$i]['winner'] = $p['place'] === 1;
            }
            $first = collect($players)->firstWhere('place', 1);
            $result = $first ? $first['name'].' venceu a mesa' : null;
        } elseif ($players[0]['games'] !== null && $players[1]['games'] !== null) {
            [$a, $b] = [$players[0]['games'], $players[1]['games']];
            $score = max($a, $b).'–'.min($a, $b);
            if ($a === $b) {
                $result = 'Empate em '.$a.'–'.$b;
            } else {
                $win = $a > $b ? 0 : 1;
                $players[$win]['winner'] = true;
                $result = $players[$win]['name'].' venceu por '.$score;
            }
        }

        return ['type' => $type, 'kind' => $kind, 'ranked' => $ranked, 'context' => array_values(array_filter($context)), 'date' => $date,
            'status' => $status, 'pod' => $pod, 'players' => $players, 'result' => $result, 'eloNote' => $elo['note'], 'played' => $played,
            'chart' => $chart, 'link' => $link];
    }

    private function seat(string $name, ?PlayerProfile $profile, ?array $deck, ?int $games, ?int $place, bool $me): array
    {
        return ['name' => $name, 'profile' => $profile, 'deck' => $deck, 'games' => $games, 'place' => $place, 'winner' => false, 'me' => $me, 'elo' => null];
    }

    /** Deck do inscrito no torneio: nome e cores (o mesmo que a página pública mostra); link só se a pessoa pode abrir o deck. */
    private function deck(Player $player, User $user): ?array
    {
        $name = $player->deckLabel();
        if (! $name) {
            return null;
        }

        return ['name' => $name, 'colors' => $player->deckColors(),
            'url' => $player->deck && $user->can('view', $player->deck) ? route('decks.show', $player->deck) : null];
    }
}
