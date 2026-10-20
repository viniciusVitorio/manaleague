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
        $unread = $notifications->visible($user)->whereNull('read_at')->count();

        $activities = app(ActivityService::class)->visible($user, 1, 5);

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
            'actionCount' => $toConfirm->count() + $toReport->count() + $challenges->filter(fn ($c) => $this->challengeNeedsMe($c, $user))->count() + $awaitingReview->sum(),
        ];
    }

    public function challengeNeedsMe(Challenge $challenge, User $user): bool
    {
        if ($challenge->status === 'pending') return $challenge->opponent->account_user_id === $user->id;
        $match = $challenge->casualMatch;
        if (! $match) return false;
        return $match->status === 'pending' || ($match->status === 'reported' && $match->reported_by !== $user->id);
    }
}
