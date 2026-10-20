<?php

namespace App\Services\Ranked;

use App\Models\Challenge;
use App\Models\PlayerProfile;
use App\Models\RankedMatch;
use App\Models\RankedResultSubmission;
use App\Models\RankedSeason;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo da partida ranqueada: criada (com aceite previo dos dois) -> placar enviado ->
 * confirmado pelo adversario (ou resolvido por quem modera) -> Elo aplicado uma vez.
 * O navegador nunca calcula delta, patente ou autorizacao.
 */
class RankedMatchService
{
    public function __construct(
        private readonly RatingLedgerService $ledger,
        private readonly RatingReplayService $replay,
        private readonly RankedFraudReviewService $fraud,
        private readonly RankedNotifier $notifier,
    ) {}

    /** Motivo (pt-BR) pelo qual a dupla nao pode jogar ranqueada agora, ou null. */
    public function eligibilityError(RankedSeason $season, string $format, PlayerProfile $a, PlayerProfile $b): ?string
    {
        $season->loadMissing('formats');
        if (! $season->acceptsMatches()) {
            return 'A temporada não está em andamento.';
        }
        if (! $season->formatConfig($format)) {
            return "{$format} não é ranqueado nesta temporada.";
        }
        if ($a->id === $b->id) {
            return 'Uma partida ranqueada precisa de dois jogadores diferentes.';
        }
        if (! $a->account_user_id || ! $b->account_user_id || $a->account_user_id === $b->account_user_id) {
            return 'Os dois jogadores precisam ter contas próprias.';
        }
        if ($a->rankedSuspended() || $b->rankedSuspended()) {
            return 'Um dos jogadores está suspenso do ranked.';
        }
        if ($season->scope === 'club') {
            $members = $season->club?->members()->pluck('users.id')->all() ?? [];
            if (! in_array($a->account_user_id, $members, true) || ! in_array($b->account_user_id, $members, true)) {
                return 'Temporada de clube: os dois precisam ser membros.';
            }
        }
        $limit = (int) $season->setting('pair_limit_24h');
        if ($this->recentPairCount($season, $format, $a->id, $b->id) >= $limit) {
            return "Limite de {$limit} partidas ranqueadas entre vocês em 24h neste formato. Joguem como casual.";
        }

        return null;
    }

    public function recentPairCount(RankedSeason $season, string $format, int $a, int $b): int
    {
        return RankedMatch::query()->where(['season_id' => $season->id, 'format' => $format, 'is_ranked' => true])
            ->whereNotIn('status', ['cancelled', 'invalidated'])->where('created_at', '>=', now()->subDay())
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('player_one_profile_id', $a)->where('player_two_profile_id', $b))
                ->orWhere(fn ($q) => $q->where('player_one_profile_id', $b)->where('player_two_profile_id', $a)))
            ->count();
    }

    /** Chamado quando OS DOIS ja aceitaram jogar ranqueado (desafio aceito ou proposta aceita). */
    public function create(RankedSeason $season, string $format, PlayerProfile $one, PlayerProfile $two, string $sourceType, int $sourceId, int $bestOf = 3): RankedMatch
    {
        $this->fail($sourceType !== 'challenge', 'Origem inválida.');
        $this->fail(! in_array($bestOf, [1, 3, 5], true), 'Melhor de 1, 3 ou 5.');
        $match = DB::transaction(function () use ($season, $format, $one, $two, $sourceType, $sourceId, $bestOf) {
            $season = RankedSeason::query()->with('formats')->lockForUpdate()->findOrFail($season->id);
            $error = $this->eligibilityError($season, $format, $one, $two);
            $this->fail($error !== null, (string) $error);
            $existing = RankedMatch::query()->where(['source_type' => $sourceType, 'source_id' => $sourceId])->first();
            if ($existing) {
                return $existing;
            }

            return RankedMatch::create([
                'season_id' => $season->id, 'format' => $format, 'player_one_profile_id' => $one->id, 'player_two_profile_id' => $two->id,
                'source_type' => $sourceType, 'source_id' => $sourceId, 'status' => 'pending', 'best_of' => $bestOf, 'is_ranked' => true,
                'k_factor' => $season->formatConfig($format)->k_factor, 'rules_version' => $season->rules_version,
            ]);
        }, 3);
        if ($match->wasRecentlyCreated) {
            $this->notifier->matchCreated($match);
        }

        return $match;
    }

    public function submit(RankedMatch $match, User $user, int $scoreOne, int $scoreTwo, string $idempotencyKey, ?string $ip = null): RankedResultSubmission
    {
        $submission = DB::transaction(function () use ($match, $user, $scoreOne, $scoreTwo, $idempotencyKey, $ip) {
            $m = RankedMatch::query()->with(['season.formats', 'playerOne', 'playerTwo'])->lockForUpdate()->findOrFail($match->id);
            abort_unless($m->involvesUser($user), 403);
            $previous = RankedResultSubmission::query()->where(['submitted_by' => $user->id, 'idempotency_key' => $idempotencyKey])->first();
            if ($previous) {
                abort_unless($previous->ranked_match_id === $m->id && $previous->score_one === $scoreOne && $previous->score_two === $scoreTwo, 409, 'Envio repetido com dados diferentes.');

                return $previous;
            }
            $this->fail(! in_array($m->status, ['pending', 'disputed'], true), 'Esta partida já tem um placar aguardando revisão ou foi encerrada.');
            $this->fail(! in_array($m->season->status, ['active', 'closing'], true), 'A temporada foi encerrada.');
            $this->assertScore($m, $scoreOne, $scoreTwo);
            RankedResultSubmission::query()->where('ranked_match_id', $m->id)->whereIn('status', ['pending', 'disputed'])->update(['status' => 'superseded']);
            $s = RankedResultSubmission::create(['ranked_match_id' => $m->id, 'submitted_by' => $user->id, 'score_one' => $scoreOne, 'score_two' => $scoreTwo,
                'status' => 'pending', 'idempotency_key' => $idempotencyKey, 'ip_address' => $ip]);
            $m->update(['status' => 'reported', 'score_one' => $scoreOne, 'score_two' => $scoreTwo, 'played_at' => $m->played_at ?? now()]);

            return $s;
        }, 3);
        if ($submission->wasRecentlyCreated) {
            $this->notifier->resultSubmitted($match->fresh(['playerOne', 'playerTwo']), $user);
        }

        return $submission;
    }

    /** O adversario de quem enviou confirma: aplica o Elo uma unica vez. */
    public function confirm(RankedMatch $match, User $user): RankedMatch
    {
        $deltas = null;
        $m = DB::transaction(function () use ($match, $user, &$deltas) {
            $m = RankedMatch::query()->with(['playerOne', 'playerTwo'])->lockForUpdate()->findOrFail($match->id);
            abort_unless($m->involvesUser($user), 403);
            if ($m->status === 'confirmed') {
                return $m;
            } // segunda confirmacao concorrente: nada muda
            $this->fail($m->status !== 'reported', 'Não há placar aguardando confirmação.');
            $s = RankedResultSubmission::query()->where(['ranked_match_id' => $m->id, 'status' => 'pending'])->lockForUpdate()->latest('id')->firstOrFail();
            abort_if($s->submitted_by === $user->id, 403, 'Quem enviou o placar não pode confirmá-lo.');
            $s->update(['status' => 'confirmed', 'reviewed_by' => $user->id, 'reviewed_at' => now()]);
            $m->update(['status' => 'confirmed', 'confirmed_at' => now()]);
            $deltas = $this->ledger->applyMatch($m);

            return $m;
        }, 3);
        if ($deltas) {
            $this->afterConfirmed($m, $deltas);
        }

        return $m;
    }

    public function dispute(RankedMatch $match, User $user, string $reason): RankedMatch
    {
        $m = DB::transaction(function () use ($match, $user, $reason) {
            $m = RankedMatch::query()->with(['playerOne', 'playerTwo'])->lockForUpdate()->findOrFail($match->id);
            abort_unless($m->involvesUser($user), 403);
            $this->fail($m->status !== 'reported', 'Não há placar para contestar.');
            $s = RankedResultSubmission::query()->where(['ranked_match_id' => $m->id, 'status' => 'pending'])->latest('id')->firstOrFail();
            abort_if($s->submitted_by === $user->id, 403);
            $s->update(['status' => 'disputed', 'dispute_reason' => trim($reason), 'reviewed_by' => $user->id, 'reviewed_at' => now()]);
            $m->update(['status' => 'disputed']);

            return $m;
        }, 3);
        $this->notifier->disputed($m, $user);

        return $m;
    }

    /** Moderacao decide o placar de uma disputa. Autorizacao feita pela policy (resolve). */
    public function resolve(RankedMatch $match, User $moderator, int $scoreOne, int $scoreTwo, string $reason): RankedMatch
    {
        $deltas = null;
        $m = DB::transaction(function () use ($match, $moderator, $scoreOne, $scoreTwo, $reason, &$deltas) {
            $m = RankedMatch::query()->with(['season.formats', 'playerOne', 'playerTwo'])->lockForUpdate()->findOrFail($match->id);
            $this->fail(! in_array($m->status, ['reported', 'disputed'], true), 'Só é possível resolver partidas com placar em revisão.');
            $this->fail(! in_array($m->season->status, ['active', 'closing'], true), 'A temporada foi encerrada.');
            $this->assertScore($m, $scoreOne, $scoreTwo);
            RankedResultSubmission::query()->where('ranked_match_id', $m->id)->whereIn('status', ['pending', 'disputed'])
                ->update(['status' => 'resolved', 'resolution_reason' => trim($reason), 'reviewed_by' => $moderator->id, 'reviewed_at' => now()]);
            $m->update(['status' => 'confirmed', 'score_one' => $scoreOne, 'score_two' => $scoreTwo, 'confirmed_at' => now(), 'played_at' => $m->played_at ?? now()]);
            $deltas = $this->ledger->applyMatch($m);

            return $m;
        }, 3);
        if ($deltas) {
            $this->afterConfirmed($m, $deltas);
        }

        return $m;
    }

    /** Admin invalida um resultado confirmado: replay cronologico + lancamentos de correcao. */
    public function invalidate(RankedMatch $match, User $admin, string $reason): array
    {
        $changed = DB::transaction(function () use ($match, $admin, $reason) {
            $m = RankedMatch::query()->with('season.formats')->lockForUpdate()->findOrFail($match->id);
            $this->fail($m->status !== 'confirmed', 'Somente partidas confirmadas podem ser invalidadas.');
            $this->fail(! in_array($m->season->status, ['active', 'closing'], true), 'Temporada encerrada: o histórico é imutável.');
            $m->update(['status' => 'invalidated', 'invalidated_at' => now(), 'invalidated_by' => $admin->id, 'invalidation_reason' => trim($reason)]);

            return $this->replay->replay($m->season, $m->format, $m, 'Partida #'.$m->id.' invalidada: '.trim($reason));
        }, 3);
        $this->notifier->invalidated($match->fresh(['playerOne', 'playerTwo']), $changed);

        return $changed;
    }

    /** Antes de qualquer placar, qualquer um dos dois pode cancelar (nao pontua). */
    public function cancel(RankedMatch $match, User $user): void
    {
        DB::transaction(function () use ($match, $user) {
            $m = RankedMatch::query()->lockForUpdate()->findOrFail($match->id);
            abort_unless($m->involvesUser($user), 403);
            $this->fail($m->status !== 'pending', 'Depois de enviado o placar a partida não pode ser cancelada.');
            $m->update(['status' => 'cancelled']);
        });
        $this->notifier->cancelled($match->fresh(['playerOne', 'playerTwo']), $user);
    }

    public function assertScore(RankedMatch $m, int $one, int $two): void
    {
        $need = $m->winsNeeded();
        $this->fail($one < 0 || $two < 0 || max($one, $two) > $need, "Placar inválido para melhor de {$m->best_of}.");
        if ($one === $two) {
            $m->loadMissing('season.formats');
            $this->fail(! ($m->season->formatConfig($m->format)?->allow_draws ?? false), 'Empate não é permitido neste formato.');
            $this->fail($one >= $need, 'Placar inválido.');

            return;
        }
        $this->fail(max($one, $two) !== $need, "O vencedor precisa de {$need} game(s).");
    }

    private function afterConfirmed(RankedMatch $m, array $deltas): void
    {
        $this->notifier->confirmed($m->fresh(['playerOne', 'playerTwo']), $deltas);
        $this->fraud->review($m);
        if ($m->source_type === 'challenge') {
            Challenge::query()->whereKey($m->source_id)->where('status', 'accepted')->update(['status' => 'completed']);
        }
    }

    private function fail(bool $condition, string $message): void
    {
        if ($condition) {
            throw ValidationException::withMessages(['ranked' => $message]);
        }
    }
}
