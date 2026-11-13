<?php

namespace App\Services;

use App\Models\Challenge;
use App\Models\GameMatch;
use App\Models\MatchLifeLog;
use App\Models\MatchResultSubmission;
use App\Models\PlayerProfile;
use App\Models\RankedMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Support\LifeChart;

/**
 * Histórico de vida de uma partida, enviado pelo contador durante o jogo (no máximo a cada poucos segundos e ao sair da
 * tela). Assim ele não se perde quando o placar sai por outro caminho: formulário, adversário ou organizador.
 * O filtro de misclick acontece no aparelho (life-log.js); aqui o servidor confere quem manda e se os números são possíveis.
 * O gráfico só aparece depois que a partida tem resultado.
 */
class LifeLogService
{
    public const MAX_POINTS = 400;

    public const MAX_SECONDS = 86400;

    public const MAX_LIFE = 999;

    public const MAX_POISON = 99;

    public function __construct(private readonly LifeCounterService $counter) {}

    /**
     * Grava ou atualiza o histórico. Um por partida: o primeiro aparelho que começou a gravar é o dono, e o dos outros é ignorado.
     * Histórico vazio do dono (ele zerou tudo no contador) apaga o que estava salvo.
     *
     * @param  list<array{winner?: int|null, points?: list<list<int>>, out?: list<list<int>>}>  $games
     * @return 'created'|'updated'|'deleted'|'ignored'
     */
    public function store(string $type, int $id, User $user, array $games): string
    {
        $config = $this->resolve($type, $id, $user);
        $clean = $this->sanitize($games, count($config['players']), $config['mode'] === 'pod' ? 1 : (int) $config['bestOf']);
        $empty = ! collect($clean)->contains(fn (array $g) => $g['points'] !== []);
        $existing = MatchLifeLog::of($type, $id);

        if ($existing && $existing->recorded_by !== $user->id) {
            return 'ignored';
        }
        if ($empty) {
            $existing?->delete();

            return $existing ? 'deleted' : 'ignored';
        }
        if ($existing) {
            $existing->update(['games' => $clean]);

            return 'updated';
        }

        $log = MatchLifeLog::createOrFirst(['match_type' => $type, 'match_id' => $id], [
            'recorded_by' => $user->id,
            'starting_life' => (int) $config['startingLife'],
            'seats' => array_map(fn (array $seat) => $seat['id'], $config['players']),
            'games' => $clean,
        ]);
        // Dois pedidos ao mesmo tempo: quem perdeu a corrida vira atualização se for o mesmo dono.
        if (! $log->wasRecentlyCreated) {
            return $log->recorded_by === $user->id && $log->update(['games' => $clean]) ? 'updated' : 'ignored';
        }

        return 'created';
    }

    /** A partida já tem placar (enviado ou confirmado)? Só então o gráfico aparece. */
    public function hasResult(string $type, int $id): bool
    {
        return match ($type) {
            'tournament' => GameMatch::whereKey($id)->where('status', GameMatch::STATUS_FINISHED)->exists()
                || MatchResultSubmission::where('match_id', $id)->exists(),
            'challenge' => Challenge::whereKey($id)->whereHas('casualMatch', fn ($q) => $q->whereIn('status', ['reported', 'confirmed', 'disputed']))->exists(),
            'ranked' => RankedMatch::whereKey($id)->whereIn('status', ['reported', 'confirmed', 'disputed'])->exists(),
            default => false,
        };
    }

    /**
     * Gráfico da partida para quem pode ver a partida: os jogadores dela; no torneio também o organizador e o clube.
     * Null quando não pode ver ou não há histórico (a rota responde 404, a URL é adivinhável).
     *
     * @return array{startingLife: int, names: list<string>, people: list<PlayerProfile|null>, games: list<array>}|null
     */
    public function chartFor(string $type, int $id, ?User $user): ?array
    {
        $log = $user ? MatchLifeLog::of($type, $id) : null;
        if (! $log || ! $this->hasResult($type, $id)) {
            return null;
        }

        $people = match ($type) {
            'tournament' => $this->tournamentPeople($id, $user, $log->seats),
            'challenge' => $this->challengePeople($id, $user),
            'ranked' => $this->rankedPeople($id, $user),
            default => null,
        };
        if ($people === null || count($people) !== count($log->seats)) {
            return null;
        }
        $names = array_column($people, 'name');

        return [
            'names' => $names,
            // Perfil de cada lugar, para o avatar no fim da linha (null: jogador avulso do torneio, sem perfil).
            'people' => array_column($people, 'profile'),
            'startingLife' => $log->starting_life,
            'games' => array_map(fn (array $game) => LifeChart::game($game, $log->starting_life, $names), $log->games),
        ];
    }

    /**
     * Quais destas partidas têm gráfico (busca em lote, para listas).
     *
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    public function withLog(string $type, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return MatchLifeLog::query()->where('match_type', $type)->whereIn('match_id', $ids)->pluck('match_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /**
     * Partidas encerradas do torneio com gráfico que esta pessoa pode abrir: todas para o organizador e o clube,
     * só as próprias para o jogador (a página pública não abre o gráfico dos outros). Espera players e matches carregados.
     *
     * @return array<int, true>
     */
    public function viewableInTournament(Tournament $tournament, ?User $user): array
    {
        $finished = $tournament->matches->where('status', GameMatch::STATUS_FINISHED)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $logs = $user ? $this->withLog('tournament', $finished) : [];
        if ($logs === [] || $user->can('view', $tournament) || $user->can('update', $tournament)) {
            return $logs;
        }
        $mine = $tournament->players->filter(fn ($p) => $p->profile?->account_user_id === $user->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $matches = $tournament->matches->keyBy('id');

        return array_filter($logs, function ($_, $id) use ($matches, $mine) {
            $match = $matches->get($id);
            $seats = array_map('intval', [$match?->player_one_id, $match?->player_two_id, ...($match?->pod_player_ids ?? [])]);

            return array_intersect($seats, $mine) !== [];
        }, ARRAY_FILTER_USE_BOTH);
    }

    /** @return list<array{name: string, profile: PlayerProfile|null}>|null */
    private function tournamentPeople(int $id, User $user, array $seats): ?array
    {
        $match = GameMatch::with(['tournament.players.profile'])->find($id);
        if (! $match) {
            return null;
        }
        $players = $match->tournament->players->keyBy('id');
        $mine = collect($seats)->contains(fn ($seat) => $players->get($seat)?->profile?->account_user_id === $user->id);
        if (! $mine && ! $user->can('view', $match->tournament) && ! $user->can('update', $match->tournament)) {
            return null;
        }

        return array_map(fn ($seat) => ['name' => $players->get($seat)?->displayName() ?? 'Jogador', 'profile' => $players->get($seat)?->profile], $seats);
    }

    /** @return list<array{name: string, profile: PlayerProfile}>|null */
    private function challengePeople(int $id, User $user): ?array
    {
        $challenge = Challenge::with(['challenger', 'opponent'])->find($id);

        return $challenge && $challenge->involves($user) ? self::people([$challenge->challenger, $challenge->opponent]) : null;
    }

    /** @return list<array{name: string, profile: PlayerProfile}>|null */
    private function rankedPeople(int $id, User $user): ?array
    {
        $match = config('ranked.enabled') ? RankedMatch::with(['playerOne', 'playerTwo'])->find($id) : null;

        return $match && $user->can('view', $match) ? self::people([$match->playerOne, $match->playerTwo]) : null;
    }

    /** @param  list<PlayerProfile>  $profiles */
    private static function people(array $profiles): array
    {
        return array_map(fn (PlayerProfile $profile) => ['name' => $profile->displayName(), 'profile' => $profile], $profiles);
    }

    /** Mesma autorização do contador: quem pode abrir o contador daquela partida pode gravar o histórico dela. */
    private function resolve(string $type, int $id, User $user): array
    {
        return match ($type) {
            'tournament' => (function () use ($id, $user) {
                $match = GameMatch::with('tournament')->findOrFail($id);

                return $this->counter->forTournament($match->tournament, $match, $user);
            })(),
            'challenge' => (function () use ($id, $user) {
                $challenge = Challenge::findOrFail($id);
                abort_if($challenge->is_ranked, 404);

                return $this->counter->forChallenge($challenge, $user);
            })(),
            'ranked' => (function () use ($id, $user) {
                abort_unless(config('ranked.enabled'), 404);

                return $this->counter->forRanked(RankedMatch::findOrFail($id), $user);
            })(),
            default => abort(404),
        };
    }

    /**
     * Descarta o que não pode acontecer numa mesa (lugar inexistente, vida absurda) e deixa o tempo sempre crescente.
     *
     * @return list<array{winner: int|null, points: list<array{int, int, int, int}>, out: list<array{int, int}>}>
     */
    private function sanitize(array $games, int $seats, int $maxGames): array
    {
        $seat = fn ($s) => is_int($s) && $s >= 0 && $s < $seats;
        $clean = [];
        foreach (array_slice(array_values($games), 0, max(1, $maxGames)) as $game) {
            $winner = $game['winner'] ?? null;
            $points = [];
            $last = 0;
            foreach (array_slice($game['points'] ?? [], 0, self::MAX_POINTS) as $point) {
                [$t, $s, $life, $poison] = array_values($point) + [null, null, null, null];
                if (! is_int($t) || ! $seat($s) || ! is_int($life) || abs($life) > self::MAX_LIFE || ! is_int($poison) || $poison < 0 || $poison > self::MAX_POISON) {
                    continue;
                }
                $last = min(self::MAX_SECONDS, max($last, $t));
                $points[] = [$last, $s, $life, $poison];
            }
            $out = [];
            foreach ($game['out'] ?? [] as $elimination) {
                [$t, $s] = array_values($elimination) + [null, null];
                if (is_int($t) && $seat($s)) {
                    $out[] = [min(self::MAX_SECONDS, max(0, $t)), $s];
                }
            }
            $clean[] = ['winner' => $seat($winner) ? $winner : null, 'points' => $points, 'out' => $out];
        }

        return $clean;
    }
}
