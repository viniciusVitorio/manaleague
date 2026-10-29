<?php

namespace App\Services\Community;

use App\Models\Challenge;
use App\Models\Club;
use App\Models\CommunityNotification;
use App\Models\GameMatch;
use App\Models\MatchResultSubmission;
use App\Models\Player;
use App\Models\Tournament;
use App\Models\User;

/** Builds the personal dashboard: what needs the user's attention right now, then context. */
class DashboardService
{
    public function for(User $user): array
    {
        $profileId = $user->playerProfile?->id;
        $mine = fn ($q) => $q->whereHas('profile', fn ($p) => $p->where('account_user_id', $user->id));
        $myPlayerIds = Player::query()->where($mine)->pluck('id');

        // Submissions waiting for MY confirmation (opponent sent the score).
        $toConfirm = MatchResultSubmission::query()->where('status', 'pending')->where('submitted_by', '!=', $user->id)
            ->whereHas('gameMatch', fn ($q) => $q->where(fn ($q) => $q->whereIn('player_one_id', $myPlayerIds)->orWhereIn('player_two_id', $myPlayerIds)))
            ->with(['gameMatch.tournament', 'gameMatch.playerOne', 'gameMatch.playerTwo'])->latest('id')->limit(10)->get();

        // Open duel matches in running tournaments with no submission yet: the user can report the score.
        $toReport = GameMatch::query()->where('status', 'pending')->whereNotNull('player_two_id')->whereNull('pod_player_ids')
            ->where(fn ($q) => $q->whereIn('player_one_id', $myPlayerIds)->orWhereIn('player_two_id', $myPlayerIds))
            ->whereHas('tournament', fn ($q) => $q->whereIn('status', ['league', 'finals']))
            ->whereDoesntHave('submission')
            ->with(['tournament', 'playerOne', 'playerTwo'])->orderBy('round')->limit(10)->get();

        $challenges = $profileId ? Challenge::query()
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('opponent_id', $profileId)->where('status', 'pending')->where('expires_at', '>', now()))
                ->orWhere(fn ($q) => $q->where('status', 'accepted')->where(fn ($q) => $q->where('challenger_id', $profileId)->orWhere('opponent_id', $profileId))))
            ->with(['challenger', 'opponent', 'casualMatch'])->latest('id')->limit(10)->get() : collect();

        $playing = Tournament::query()->whereIn('status', ['setup', 'league', 'finals'])
            ->whereHas('players', $mine)->latest('id')->limit(10)->get();

        $clubs = Club::whereHas('members', fn ($q) => $q->where('users.id', $user->id))->orderBy('name')->get();

        $organizing = Tournament::query()->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('club_id')->where('user_id', $user->id))->orWhereIn('club_id', $clubs->pluck('id')))
            ->withCount(['matches as pending_matches_count' => fn ($q) => $q->where('status', 'pending')])
            ->latest()->limit(10)->get()
            ->filter(fn (Tournament $t) => $user->can('view', $t))->values();
        $awaitingReview = MatchResultSubmission::query()->whereIn('status', ['pending', 'disputed'])
            ->whereHas('gameMatch', fn ($q) => $q->whereIn('tournament_id', $organizing->pluck('id')))
            ->with('gameMatch:id,tournament_id')->get()->groupBy(fn ($s) => $s->gameMatch->tournament_id)->map->count();

        $notifications = app(NotificationService::class);
        $unreadList = CommunityNotification::where('user_id', $user->id)->whereNull('read_at')->latest('id')->limit(30)->get()
            ->filter(fn ($n) => $notifications->canRead($user, $n))->values();
        $unread = $notifications->unreadCount($user);

        $activities = app(ActivityService::class)->visible($user, 1, 5);

        // Ranked: placares para confirmar, partidas marcadas e propostas da fila.
        $rankedOpen = $profileId ? \App\Models\RankedMatch::query()->with(['playerOne', 'playerTwo', 'submissions'])
            ->whereIn('status', ['pending', 'reported', 'disputed'])
            ->where(fn ($q) => $q->where('player_one_profile_id', $profileId)->orWhere('player_two_profile_id', $profileId))->latest('id')->limit(10)->get() : collect();
        $rankedNeedsMe = $rankedOpen->filter(fn ($m) => $m->status === 'pending'
            || ($m->status === 'reported' && $m->submissions->where('status', 'pending')->sortByDesc('id')->first()?->submitted_by !== $user->id))->values();
        $rankedRatings = $profileId ? \App\Models\PlayerRating::query()->with('season')->where('player_profile_id', $profileId)
            ->whereHas('season', fn ($q) => $q->whereIn('status', ['active', 'closing']))->where('played', '>', 0)->orderByDesc('rating')->limit(3)->get() : collect();

        // Todas as partidas de torneio em aberto em que estou sentado (inclui assentos de mesas Commander).
        $myIds = $myPlayerIds->map(fn ($id) => (int) $id)->all();
        $openTournamentMatches = $myIds === [] ? collect() : GameMatch::query()->where('status', 'pending')
            ->whereHas('tournament', fn ($q) => $q->whereIn('status', ['league', 'finals']))
            ->where(function ($q) use ($myIds) {
                $q->whereIn('player_one_id', $myIds)->orWhereIn('player_two_id', $myIds);
                foreach ($myIds as $id) foreach (["[$id]", "[$id,%", "%,$id,%", "%,$id]"] as $pattern) $q->orWhere('pod_player_ids', 'like', $pattern);
            })
            ->with(['tournament.players.deck', 'playerOne.deck', 'playerTwo.deck', 'submission'])->orderBy('round')->orderBy('id')->get();
        $openCasual = $challenges->filter(fn ($c) => $c->status === 'accepted' && $c->casualMatch && $c->casualMatch->status !== 'confirmed');

        $stats = $user->playerProfile ? app(\App\Services\PlayerStatsService::class)->for($user->playerProfile) : ['wins' => 0, 'losses' => 0, 'draws' => 0, 'played' => 0, 'winrate' => 0];
        $recentNotifications = CommunityNotification::where('user_id', $user->id)->latest('id')->limit(20)->get()
            ->filter(fn ($n) => $notifications->canRead($user, $n))->take(5)->values();

        return [
            'toConfirm' => $toConfirm,
            'toReport' => $toReport,
            'challenges' => $challenges,
            'playing' => $playing,
            'organizing' => $organizing,
            'awaitingReview' => $awaitingReview,
            'clubs' => $clubs,
            'unread' => $unread,
            'latestUnread' => $unreadList->take(5),
            'activities' => $activities,
            'profileId' => $profileId,
            'rankedNeedsMe' => $rankedNeedsMe,
            'rankedRatings' => $rankedRatings,
            'nextMatch' => $this->nextMatch($user, $openTournamentMatches, $rankedOpen, $myIds),
            'pendingMatches' => $openTournamentMatches->count() + $rankedOpen->count() + $openCasual->count(),
            'stats' => $stats,
            'recentNotifications' => $recentNotifications,
            'actionCount' => $rankedNeedsMe->count() + $toConfirm->count() + $toReport->count() + $challenges->filter(fn ($c) => $this->challengeNeedsMe($c, $user))->count() + $awaitingReview->sum(),
        ];
    }

    /**
     * A partida mais urgente: torneio (menor rodada) e, se nao houver, ranqueada em aberto.
     * Sempre dados reais; null quando nao ha nada pendente.
     */
    private function nextMatch(User $user, $tournamentMatches, $rankedOpen, array $myIds): ?array
    {
        $m = $tournamentMatches->first();
        if ($m) {
            $players = $m->tournament->players->keyBy('id');
            $mineId = collect($m->pod_player_ids ?: [$m->player_one_id, $m->player_two_id])->first(fn ($id) => in_array((int) $id, $myIds, true));
            $me = $players->get($mineId);
            $opponents = collect($m->pod_player_ids ?: [$m->player_one_id, $m->player_two_id])->reject(fn ($id) => (int) $id === (int) $mineId)
                ->map(fn ($id) => $players->get($id))->filter();
            $submission = $m->submission;
            [$status, $label] = match (true) {
                $submission?->status === 'disputed' => ['disputed', 'Em disputa'],
                $submission?->status === 'pending' && $submission->submitted_by === $user->id => ['pending', 'Aguardando confirmação'],
                $submission?->status === 'pending' => ['pending', 'Confirme o placar'],
                default => ['pending', 'Aguardando resultado'],
            };

            return [
                'kind' => 'tournament',
                'context' => $m->tournament->name,
                'detail' => trim(($m->pod_player_ids ? 'Mesa Commander' : 'Rodada '.$m->round).' · '.$m->tournament->format),
                'me' => $me?->name ?? $user->name,
                'myDeck' => $me ? ($me->deck?->name ?: $me->deck_name) : null,
                'opponent' => $opponents->pluck('name')->join(', ') ?: 'A definir',
                'opponentDeck' => $opponents->count() === 1 ? ($opponents->first()->deck?->name ?: $opponents->first()->deck_name) : null,
                'opponentLabel' => $opponents->count() > 1 ? 'Adversários' : 'Adversário',
                'status' => $status,
                'statusLabel' => $label,
                'url' => route('matches.mine'),
                'action' => $label === 'Confirme o placar' ? 'Confirmar placar' : ($submission ? 'Ver partida' : 'Enviar resultado'),
                'secondaryUrl' => route('arena.public', $m->tournament->public_slug),
                'secondary' => 'Arena ao vivo',
            ];
        }
        $r = $rankedOpen->first();
        if ($r && config('ranked.enabled')) {
            $mine = $r->playerOne->account_user_id === $user->id;
            $pending = $r->submissions->where('status', 'pending')->sortByDesc('id')->first();

            return [
                'kind' => 'ranked',
                'context' => 'Partida ranqueada',
                'detail' => $r->format.' · MD'.$r->best_of,
                'me' => ($mine ? $r->playerOne : $r->playerTwo)->nickname,
                'myDeck' => null,
                'opponent' => ($mine ? $r->playerTwo : $r->playerOne)->nickname,
                'opponentDeck' => null,
                'opponentLabel' => 'Adversário',
                'status' => $r->status === 'disputed' ? 'disputed' : 'pending',
                'statusLabel' => $r->statusLabel(),
                'url' => route('ranked.matches.show', $r),
                'action' => $r->status === 'reported' && $pending?->submitted_by !== $user->id ? 'Confirmar placar' : 'Abrir partida',
                'secondaryUrl' => null,
                'secondary' => null,
            ];
        }

        return null;
    }

    public function challengeNeedsMe(Challenge $challenge, User $user): bool
    {
        if ($challenge->status === 'pending') return $challenge->opponent->account_user_id === $user->id;
        $match = $challenge->casualMatch;
        if (! $match) return false;
        return $match->status === 'pending' || ($match->status === 'reported' && $match->reported_by !== $user->id);
    }
}
