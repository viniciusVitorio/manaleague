<?php

namespace App\Services\Ranked;

use App\Models\Club;
use App\Models\MatchmakingEntry;
use App\Models\MatchmakingProposal;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Matchmaking PRESENCIAL: so encontra e propoe confrontos entre jogadores
 * disponiveis; ninguem joga online. Sem workers: roda ao entrar, no heartbeat
 * da pagina e no comando `ranked:tick`.
 */
class MatchmakingService
{
    public function __construct(
        private readonly SeasonService $seasons,
        private readonly RatingLedgerService $ledger,
        private readonly RankedMatchService $matches,
        private readonly RankedNotifier $notifier,
    ) {}

    public function join(User $user, string $format, ?int $clubId, ?string $note): MatchmakingEntry
    {
        $profile = $user->ensurePlayerProfile();
        $this->fail($profile->rankedSuspended(), 'Sua conta está suspensa do ranked.');
        if ($clubId) {
            $this->fail(Club::find($clubId)?->roleFor($user) === null, 'Você não é membro deste clube.');
        }
        $season = $this->seasons->activeFor($format, $clubId) ?? ($clubId ? $this->seasons->activeFor($format) : null);
        $this->fail(! $season, "Não há temporada ranqueada ativa para {$format}.");

        $entry = DB::transaction(function () use ($profile, $season, $format, $clubId, $note) {
            $this->expire();
            $active = MatchmakingEntry::query()->where('player_profile_id', $profile->id)->whereIn('status', ['searching', 'proposed'])->lockForUpdate()->first();
            $this->fail($active !== null, 'Você já está na fila. Saia dela para trocar de formato.');
            $rating = $this->ledger->ratingFor($season, $format, $profile);

            return MatchmakingEntry::create([
                'player_profile_id' => $profile->id, 'season_id' => $season->id, 'format' => $format, 'club_id' => $clubId,
                'status' => 'searching', 'rating_at_join' => $rating->rating, 'session_note' => $note ? mb_substr(trim($note), 0, 120) : null,
                'joined_at' => now(), 'last_confirmed_at' => now(), 'expires_at' => $this->expiry(now()),
            ]);
        }, 3);
        $this->runMatching();

        return $entry;
    }

    /** Sinal de presenca enviado pela pagina aberta; tambem roda a fila. */
    public function heartbeat(User $user): array
    {
        $profile = $user->ensurePlayerProfile();
        DB::transaction(function () use ($profile) {
            $entry = MatchmakingEntry::query()->where('player_profile_id', $profile->id)->whereIn('status', ['searching', 'proposed'])->lockForUpdate()->first();
            if ($entry && $entry->expires_at->isFuture()) {
                $entry->update(['last_confirmed_at' => now(), 'expires_at' => min($this->expiry(now()), $entry->joined_at->copy()->addMinutes((int) config('ranked.matchmaking.max_queue_minutes')))]);
            }
        }, 3);
        $this->runMatching();

        return $this->status($user);
    }

    public function leave(User $user): void
    {
        $profile = $user->ensurePlayerProfile();
        DB::transaction(function () use ($profile) {
            $entry = MatchmakingEntry::query()->where('player_profile_id', $profile->id)->whereIn('status', ['searching', 'proposed'])->lockForUpdate()->first();
            if (! $entry) {
                return;
            }
            $proposal = $this->openProposalFor($entry);
            if ($proposal) {
                $this->releaseProposal($proposal, 'declined', $entry->id);
            }
            $entry->update(['status' => 'cancelled']);
        }, 3);
    }

    public function accept(MatchmakingProposal $proposal, User $user): MatchmakingProposal
    {
        $created = null;
        $p = DB::transaction(function () use ($proposal, $user, &$created) {
            $p = MatchmakingProposal::query()->with(['entryOne.profile', 'entryTwo.profile', 'entryOne.season.formats'])->lockForUpdate()->findOrFail($proposal->id);
            $side = $this->side($p, $user);
            if ($p->status === 'accepted') {
                return $p;
            }
            $this->fail($p->status !== 'pending' || $p->expires_at->isPast(), 'Esta proposta expirou.');
            $p->update([$side === 1 ? 'accepted_one_at' : 'accepted_two_at' => now()]);
            if ($p->accepted_one_at && $p->accepted_two_at) {
                $one = $p->entryOne;
                $two = $p->entryTwo;
                $created = $this->matches->create($one->season, $one->format, $one->profile, $two->profile, 'matchmaking', $p->id, 3);
                $p->update(['status' => 'accepted', 'ranked_match_id' => $created->id]);
                MatchmakingEntry::query()->whereKey([$one->id, $two->id])->update(['status' => 'matched']);
            }

            return $p;
        }, 3);

        return $p;
    }

    public function decline(MatchmakingProposal $proposal, User $user): void
    {
        DB::transaction(function () use ($proposal, $user) {
            $p = MatchmakingProposal::query()->with(['entryOne.profile', 'entryTwo.profile'])->lockForUpdate()->findOrFail($proposal->id);
            $side = $this->side($p, $user);
            if ($p->status !== 'pending') {
                return;
            }
            $this->releaseProposal($p, 'declined', $side === 1 ? $p->entry_one_id : $p->entry_two_id);
        }, 3);
    }

    /** Estado honesto para a UI: nunca sugere jogadores online que nao existem. */
    public function status(User $user): array
    {
        $profile = $user->ensurePlayerProfile();
        $entry = MatchmakingEntry::query()->with('club')->where('player_profile_id', $profile->id)->latest('id')->first();
        if (! $entry || in_array($entry->status, ['cancelled'], true)) {
            return ['state' => 'idle'];
        }
        if ($entry->status === 'expired') {
            return ['state' => 'expired', 'format' => $entry->format];
        }
        $proposal = $this->openProposalFor($entry) ?? MatchmakingProposal::query()->where('status', 'accepted')
            ->where(fn ($q) => $q->where('entry_one_id', $entry->id)->orWhere('entry_two_id', $entry->id))->latest('id')->first();
        if ($entry->status === 'matched') {
            // Mostra a partida criada enquanto ela estiver em aberto; depois a fila volta ao normal.
            $open = $proposal?->ranked_match_id && \App\Models\RankedMatch::query()->whereKey($proposal->ranked_match_id)->whereIn('status', ['pending', 'reported', 'disputed'])->exists();

            return $open ? ['state' => 'matched', 'match_url' => '/ranked/matches/'.$proposal->ranked_match_id] : ['state' => 'idle'];
        }
        $waited = (int) floor($entry->joined_at->diffInSeconds(now()) / 60);
        $base = ['format' => $entry->format, 'club' => $entry->club?->name, 'waited_minutes' => $waited, 'window' => $this->window($entry->joined_at, now()), 'expires_at' => $entry->expires_at->toIso8601String(),
            'queue_size' => MatchmakingEntry::query()->where(['season_id' => $entry->season_id, 'format' => $entry->format, 'club_id' => $entry->club_id])->whereIn('status', ['searching', 'proposed'])->count()];
        if ($entry->status === 'proposed' && $proposal && $proposal->status === 'pending') {
            $proposal->loadMissing(['entryOne.profile', 'entryTwo.profile']);
            $mine = $proposal->entry_one_id === $entry->id ? 1 : 2;
            $other = $mine === 1 ? $proposal->entryTwo : $proposal->entryOne;

            return $base + ['state' => 'proposal', 'proposal_id' => $proposal->id, 'opponent' => $other->profile->nickname,
                'opponent_rating' => $other->rating_at_join, 'gap' => $proposal->rating_gap,
                'wide_gap' => $proposal->rating_gap > (int) config('ranked.matchmaking.wide_gap_warning'),
                'session_note' => $other->session_note, 'i_accepted' => (bool) ($mine === 1 ? $proposal->accepted_one_at : $proposal->accepted_two_at),
                'proposal_expires_at' => $proposal->expires_at->toIso8601String()];
        }

        return $base + ['state' => 'searching'];
    }

    /** Diferenca maxima permitida pelo tempo de espera (busca progressiva). */
    public function window(CarbonInterface $joinedAt, CarbonInterface $now): int
    {
        $waited = $joinedAt->diffInSeconds($now) / 60;
        $window = 100;
        foreach (config('ranked.matchmaking.windows') as $minutes => $gap) {
            if ($waited >= $minutes) {
                $window = $gap;
            }
        }

        return $window;
    }

    public function expire(): void
    {
        foreach (MatchmakingProposal::query()->where('status', 'pending')->where('expires_at', '<=', now())->get() as $p) {
            $this->releaseProposal($p, 'expired', null);
        }
        MatchmakingEntry::query()->where('status', 'searching')->where('expires_at', '<=', now())->update(['status' => 'expired']);
    }

    /** Casa a fila: entrada mais antiga primeiro, menor diferenca de rating, desempate pela fila mais antiga. */
    public function runMatching(): int
    {
        $proposals = DB::transaction(function () {
            $this->expire();
            $created = [];
            $entries = MatchmakingEntry::query()->with(['profile', 'season.formats'])->where('status', 'searching')
                ->where('expires_at', '>', now())->orderBy('joined_at')->orderBy('id')->lockForUpdate()->get();
            $taken = [];
            foreach ($entries as $entry) {
                if (isset($taken[$entry->id])) {
                    continue;
                }
                $window = $this->window($entry->joined_at, now());
                $best = null;
                foreach ($entries as $candidate) {
                    if ($candidate->id === $entry->id || isset($taken[$candidate->id])) {
                        continue;
                    }
                    if ($candidate->season_id !== $entry->season_id || $candidate->format !== $entry->format || $candidate->club_id !== $entry->club_id) {
                        continue;
                    }
                    $gap = abs($candidate->rating_at_join - $entry->rating_at_join);
                    if ($gap > max($window, $this->window($candidate->joined_at, now()))) {
                        continue;
                    }
                    if ($this->matches->eligibilityError($entry->season, $entry->format, $entry->profile, $candidate->profile) !== null) {
                        continue;
                    }
                    if (! $best || $gap < $best[1]) {
                        $best = [$candidate, $gap];
                    } // ordem por fila ja garante desempate estavel
                }
                if (! $best) {
                    continue;
                }
                [$other, $gap] = $best;
                $taken[$entry->id] = $taken[$other->id] = true;
                MatchmakingEntry::query()->whereKey([$entry->id, $other->id])->update(['status' => 'proposed']);
                $created[] = MatchmakingProposal::create(['entry_one_id' => $entry->id, 'entry_two_id' => $other->id, 'status' => 'pending', 'rating_gap' => $gap,
                    'expires_at' => now()->addSeconds((int) config('ranked.matchmaking.proposal_seconds'))]);
            }

            return $created;
        }, 3);
        foreach ($proposals as $p) {
            $this->notifier->proposal($p);
        }

        return count($proposals);
    }

    private function releaseProposal(MatchmakingProposal $p, string $status, ?int $leaverEntryId): void
    {
        $p->update(['status' => $status]);
        foreach ([[$p->entry_one_id, $p->accepted_one_at], [$p->entry_two_id, $p->accepted_two_at]] as [$entryId, $accepted]) {
            $entry = MatchmakingEntry::find($entryId);
            if (! $entry || $entry->status !== 'proposed') {
                continue;
            }
            // Quem recusou/sumiu sai da fila; quem aceitou volta a buscar mantendo a posicao.
            $keep = $entryId !== $leaverEntryId && ($status === 'declined' || $accepted) && $entry->expires_at->isFuture();
            $entry->update(['status' => $keep ? 'searching' : ($status === 'declined' ? 'cancelled' : 'expired')]);
        }
    }

    private function openProposalFor(MatchmakingEntry $entry): ?MatchmakingProposal
    {
        return MatchmakingProposal::query()->where('status', 'pending')
            ->where(fn ($q) => $q->where('entry_one_id', $entry->id)->orWhere('entry_two_id', $entry->id))->latest('id')->first();
    }

    private function side(MatchmakingProposal $p, User $user): int
    {
        if ($p->entryOne->profile->account_user_id === $user->id) {
            return 1;
        }
        if ($p->entryTwo->profile->account_user_id === $user->id) {
            return 2;
        }
        abort(403);
    }

    private function expiry(CarbonInterface $from): CarbonInterface
    {
        return $from->copy()->addMinutes((int) config('ranked.matchmaking.presence_minutes'));
    }

    private function fail(bool $condition, string $message): void
    {
        if ($condition) {
            throw ValidationException::withMessages(['ranked' => $message]);
        }
    }
}
