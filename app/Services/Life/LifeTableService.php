<?php

namespace App\Services\Life;

use App\Models\Challenge;
use App\Models\GameMatch;
use App\Models\LifeOp;
use App\Models\LifeTable;
use App\Models\RankedMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Policies\MatchResultSubmissionPolicy;
use App\Services\Realtime\LiveChannels;
use App\Services\Realtime\RealtimeService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Contador de vida compartilhado: uma "mesa" vinculada a cada partida e a lista de jogadas.
 *
 * O servidor nao calcula vida: ele so valida o formato da jogada, da a ordem (seq) e repassa pelo Reverb.
 * Cada celular aplica as mesmas jogadas na mesma ordem (public/js/life-core.js) e chega ao mesmo placar.
 * Jogadas sao variacoes ("-3 no lugar 2"), entao toques ao mesmo tempo nos dois celulares somam em vez de
 * um sobrescrever o outro; as que dependem do momento (fim de game, desfazer) levam o game/a pilha esperada
 * e viram nada se a mesa ja andou (dois celulares confirmando o mesmo game nao dao duas vitorias).
 */
class LifeTableService
{
    public const MAX_OPS_PER_REQUEST = 50;

    public const MAX_OPS_PER_TABLE = 5000;

    private const TYPES = ['life', 'poison', 'cmd', 'loss', 'undo', 'reset_game', 'reset_all', 'end_early', 'roll', 'submitted'];

    public function __construct(private readonly RealtimeService $realtime) {}

    /** Mesa de uma partida cadastrada; criada na primeira vez que alguem abre o contador. */
    public function forSubject(string $kind, int $subjectId, int $seats): LifeTable
    {
        $attributes = ['kind' => $kind, 'subject_id' => $subjectId];
        try {
            return LifeTable::firstOrCreate($attributes, ['seats' => $seats, 'last_activity_at' => now()]);
        } catch (QueryException) {
            // Os dois celulares abriram ao mesmo tempo: o outro criou primeiro.
            return LifeTable::where($attributes)->firstOrFail();
        }
    }

    /** Quem pode ver e mexer na mesa: as mesmas regras de quem abre o contador da partida. */
    public function allows(User $user, LifeTable $table): bool
    {
        return match ($table->kind) {
            LifeTable::KIND_TOURNAMENT => $this->allowsTournament($user, GameMatch::find($table->subject_id)),
            LifeTable::KIND_RANKED => (bool) RankedMatch::with(['playerOne', 'playerTwo'])->find($table->subject_id)?->involvesUser($user),
            LifeTable::KIND_CHALLENGE => (bool) Challenge::with(['challenger', 'opponent'])->find($table->subject_id)?->involves($user),
            default => false,
        };
    }

    /**
     * Jogadas depois de $after, em ordem.
     *
     * @return array{seq: int, ops: list<array{seq: int, id: string, by: int|null, op: array<string, mixed>}>}
     */
    public function since(LifeTable $table, int $after): array
    {
        $ops = LifeOp::where('life_table_id', $table->id)->where('seq', '>', max(0, $after))->orderBy('seq')
            ->limit(self::MAX_OPS_PER_TABLE)->get()->map(fn (LifeOp $op) => $this->present($op))->all();

        return ['seq' => (int) LifeTable::whereKey($table->id)->value('seq'), 'ops' => $ops];
    }

    /**
     * Grava as jogadas (em ordem, sem repetir as que ja chegaram) e devolve tudo depois de $after,
     * inclusive o que o outro celular mandou no meio. Avisa a mesa pelo Reverb depois da resposta.
     *
     * @param  list<mixed>  $ops
     * @return array{seq: int, ops: list<array{seq: int, id: string, by: int|null, op: array<string, mixed>}>}
     */
    public function append(LifeTable $table, User $user, array $ops, int $after): array
    {
        $this->require(count($ops) >= 1 && count($ops) <= self::MAX_OPS_PER_REQUEST, 'ops', 'Envie de 1 a '.self::MAX_OPS_PER_REQUEST.' jogadas.');
        $clean = array_map(fn ($op) => $this->normalize($op, $table->seats), $ops);

        $created = DB::transaction(function () use ($table, $user, $clean) {
            $locked = LifeTable::whereKey($table->id)->lockForUpdate()->firstOrFail();
            $known = LifeOp::where('life_table_id', $locked->id)->whereIn('client_id', array_column($clean, 'id'))->pluck('client_id')->all();
            $fresh = array_values(array_filter($clean, fn ($op) => ! in_array($op['id'], $known, true)));
            $fresh = array_values(array_intersect_key($fresh, array_unique(array_column($fresh, 'id'))));
            $this->require($locked->seq + count($fresh) <= self::MAX_OPS_PER_TABLE, 'ops', 'Esta mesa chegou ao limite de jogadas. Abra uma mesa nova.');

            $seq = $locked->seq;
            $rows = [];
            foreach ($fresh as $op) {
                $id = $op['id'];
                unset($op['id']);
                $rows[] = LifeOp::create(['life_table_id' => $locked->id, 'seq' => ++$seq, 'client_id' => $id, 'user_id' => $user->id, 'op' => $op]);
            }
            $locked->update(['seq' => $seq, 'last_activity_at' => now()]);

            return $rows;
        });

        // Mensagens pequenas (o Reverb recusa corpo grande): ate 40 jogadas por aviso.
        foreach (array_chunk(array_map(fn (LifeOp $op) => $this->present($op), $created), 40) as $chunk) {
            $this->realtime->send(LiveChannels::life($table->id), 'ops', ['ops' => $chunk]);
        }

        return $this->since($table, $after);
    }

    private function allowsTournament(User $user, ?GameMatch $match): bool
    {
        $tournament = $match ? Tournament::find($match->tournament_id) : null;
        if (! $match || ! $tournament) {
            return false;
        }
        if ($user->can('update', $tournament)) {
            return true;
        }
        if ($match->pod_player_ids) {
            return $tournament->players()->whereKey($match->pod_player_ids)
                ->whereHas('profile', fn ($q) => $q->where('account_user_id', $user->id))->exists();
        }

        return (new MatchResultSubmissionPolicy)->participate($user, $match->loadMissing(['playerOne.profile', 'playerTwo.profile']));
    }

    /**
     * Confere e limpa uma jogada: so os campos do tipo, numeros dentro dos limites, lugares que existem na mesa.
     *
     * @return array<string, mixed>
     */
    private function normalize(mixed $raw, int $seats): array
    {
        $this->require(is_array($raw), 'ops', 'Jogada inválida.');
        $type = $raw['type'] ?? null;
        $id = $raw['id'] ?? null;
        $this->require(is_string($id) && preg_match('/^[A-Za-z0-9-]{8,40}$/', $id) === 1, 'ops', 'Jogada sem identificador.');
        $this->require(is_string($type) && in_array($type, self::TYPES, true), 'ops', 'Tipo de jogada inválido.');

        $int = function (string $key, int $min, int $max) use ($raw): int {
            $value = $raw[$key] ?? null;
            $this->require(is_int($value) && $value >= $min && $value <= $max, 'ops', 'Valor inválido na jogada.');

            return $value;
        };
        $seat = fn () => $int('seat', 0, $seats - 1);

        $op = match ($type) {
            'life' => ['seat' => $seat(), 'd' => $int('d', -999, 999)],
            'poison' => ['seat' => $seat(), 'd' => $int('d', -99, 99)],
            'cmd' => ['seat' => $seat(), 'from' => $int('from', 0, $seats - 1), 'd' => $int('d', -99, 99)],
            'loss' => ['seat' => $seat(), 'game' => $int('game', 1, 99)],
            'undo' => ['depth' => $int('depth', 1, 99)],
            'reset_game', 'end_early' => ['game' => $int('game', 1, 99)],
            'roll' => ['rolls' => $this->rolls($raw['rolls'] ?? null, $seats)],
            default => [],
        };
        if ($type === 'cmd') {
            $this->require($op['from'] !== $op['seat'], 'ops', 'Dano de comandante precisa vir de outro jogador.');
        }
        if (in_array($type, ['life', 'poison', 'cmd'], true)) {
            $this->require($op['d'] !== 0, 'ops', 'Jogada sem variação.');
        }

        return ['id' => $id, 'type' => $type] + $op;
    }

    /** @return array<string, int> d20 de cada lugar (o sorteio e feito num celular e repassado igual para todos) */
    private function rolls(mixed $rolls, int $seats): array
    {
        $this->require(is_array($rolls) && $rolls !== [] && count($rolls) <= $seats, 'ops', 'Sorteio inválido.');
        $clean = [];
        foreach ($rolls as $seat => $value) {
            $this->require(is_numeric($seat) && (int) $seat >= 0 && (int) $seat < $seats && is_int($value) && $value >= 1 && $value <= 20, 'ops', 'Sorteio inválido.');
            $clean[(string) (int) $seat] = $value;
        }

        return $clean;
    }

    /** @return array{seq: int, id: string, by: int|null, op: array<string, mixed>} */
    private function present(LifeOp $op): array
    {
        return ['seq' => $op->seq, 'id' => $op->client_id, 'by' => $op->user_id, 'op' => $op->op];
    }

    private function require(bool $ok, string $field, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    /** Chave do estado salvo no aparelho, por mesa e conta. */
    public static function storageKey(LifeTable $table, User $user): string
    {
        return 'ml-life2:'.$table->id.':'.$user->id.':'.Str::substr(sha1((string) $table->created_at), 0, 6);
    }
}
