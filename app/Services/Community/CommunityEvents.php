<?php

namespace App\Services\Community;

use App\Models\Challenge;
use App\Models\GameMatch;
use App\Models\MatchResultSubmission;
use App\Models\Player;
use App\Models\PlayerProfile;
use App\Models\Tournament;
use App\Models\User;
use App\Services\TournamentChampionService;
use Illuminate\Support\Collection;

/**
 * Glue between domain modules and the community layer (notifications + feed).
 * Every call is idempotent: event keys are stable, so retries and repeated
 * calls never duplicate notifications or activities.
 */
class CommunityEvents
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly ActivityService $activities,
    ) {}

    /** Call after start/advance/finals/result: announces the current round or the final outcome. */
    public function tournamentProgress(Tournament $tournament): void
    {
        $t = Tournament::query()->with('user')->find($tournament->id);
        if (! $t || $t->status === Tournament::STATUS_SETUP) {
            return;
        }

        if ($t->status === 'finished') {
            $this->tournamentFinished($t);

            return;
        }

        $round = (int) $t->matches()->max('round');
        if ($round < 1) {
            return;
        }

        if ($t->user) {
            $this->activities->record($t->user, 'tournament.started', "Torneio {$t->name} começou", $t, "tournament:{$t->id}:started");
        }

        $matches = $t->matches()->where('round', $round)->where('status', 'pending')->get();
        foreach ($matches as $match) {
            foreach ($this->accountsFor($match) as $user) {
                $label = $t->system === 'round_robin' && $t->status === 'league' ? 'Seus confrontos estão prontos' : "Rodada {$round} pronta";
                $this->notifications->send($user, 'tournament.round', "{$label} · {$t->name}", '/me/matches', "tournament:{$t->id}:round:{$round}:{$t->status}:user:{$user->id}", ['tournament_id' => $t->id]);
            }
        }
    }

    private function tournamentFinished(Tournament $t): void
    {
        $champion = $this->championName($t);
        $title = $champion ? "{$champion} venceu {$t->name}" : "Torneio {$t->name} encerrado";
        if ($t->user) {
            $this->activities->record($t->user, 'tournament.finished', $title, $t, "tournament:{$t->id}:finished", $champion ? ['champion' => $champion] : []);
        }
        foreach ($this->accountsForTournament($t) as $user) {
            $this->notifications->send($user, 'tournament.finished', $title, '/me/matches', "tournament:{$t->id}:finished:user:{$user->id}", ['tournament_id' => $t->id]);
        }
    }

    public function resultSubmitted(MatchResultSubmission $submission): void
    {
        $match = $submission->gameMatch()->with(['tournament', 'playerOne.profile', 'playerTwo.profile'])->first();
        if (! $match) {
            return;
        }
        foreach ($this->accountsFor($match) as $user) {
            if ($user->id === $submission->submitted_by) {
                continue;
            }
            $this->notifications->send($user, 'match.submitted', "Confirme o placar {$submission->player_one_games}×{$submission->player_two_games} · {$match->tournament->name}", '/me/matches', "submission:{$submission->id}:submitted:user:{$user->id}", ['tournament_id' => $match->tournament_id]);
        }
    }

    public function resultReviewed(MatchResultSubmission $submission, User $actor, string $action): void
    {
        $match = $submission->gameMatch()->with(['tournament.user', 'playerOne.profile', 'playerTwo.profile'])->first();
        if (! $match) {
            return;
        }
        $t = $match->tournament;

        if ($action === 'dispute') {
            if ($t->user) {
                $this->notifications->send($t->user, 'match.disputed', "Resultado contestado · {$t->name}", "/tournaments/{$t->id}/submissions", "submission:{$submission->id}:disputed", ['tournament_id' => $t->id]);
            }
            $submitter = User::find($submission->submitted_by);
            if ($submitter) {
                $this->notifications->send($submitter, 'match.disputed', "Seu placar foi contestado · {$t->name}", '/me/matches', "submission:{$submission->id}:disputed:user:{$submitter->id}", ['tournament_id' => $t->id]);
            }

            return;
        }

        $score = "{$match->playerOne?->name} {$submission->player_one_games}×{$submission->player_two_games} {$match->playerTwo?->name}";
        foreach ($this->accountsFor($match) as $user) {
            if ($user->id === $actor->id) {
                continue;
            }
            $verb = $action === 'resolve' ? 'Organizador definiu' : 'Placar confirmado';
            $this->notifications->send($user, 'match.confirmed', "{$verb}: {$score}", '/me/matches', "submission:{$submission->id}:{$action}:user:{$user->id}", ['tournament_id' => $t->id]);
        }
        $this->activities->record($actor, 'match.confirmed', $score, $match, "match:{$match->id}:result:submission:{$submission->id}");
        $this->tournamentProgress($t);
    }

    public function challengeCreated(Challenge $challenge): void
    {
        $challenge->loadMissing(['challenger', 'opponent']);
        $to = $this->account($challenge->opponent);
        if ($to) {
            $this->notifications->send($to, 'challenge.received', "{$challenge->challenger->nickname} te desafiou ({$challenge->format}, MD{$challenge->best_of})", "/challenges/{$challenge->id}", "challenge:{$challenge->id}:created", ['challenge_id' => $challenge->id]);
        }
    }

    public function challengeTransition(Challenge $challenge, string $action): void
    {
        $challenge->loadMissing(['challenger', 'opponent']);
        [$to, $who] = $action === 'cancel'
            ? [$this->account($challenge->opponent), $challenge->challenger->nickname]
            : [$this->account($challenge->challenger), $challenge->opponent->nickname];
        $text = match ($action) {
            'accept' => 'aceitou seu desafio', 'decline' => 'recusou seu desafio', 'cancel' => 'cancelou o desafio', default => null
        };
        if ($to && $text) {
            $this->notifications->send($to, 'challenge.update', "{$who} {$text}", "/challenges/{$challenge->id}", "challenge:{$challenge->id}:{$action}", ['challenge_id' => $challenge->id]);
        }
    }

    public function challengeReported(Challenge $challenge, User $actor, int $revision): void
    {
        $challenge->loadMissing(['challenger', 'opponent']);
        foreach ([$challenge->challenger, $challenge->opponent] as $profile) {
            $user = $this->account($profile);
            if ($user && $user->id !== $actor->id) {
                $this->notifications->send($user, 'challenge.update', 'Confirme o placar do desafio', "/challenges/{$challenge->id}", "challenge:{$challenge->id}:reported:{$revision}:user:{$user->id}", ['challenge_id' => $challenge->id]);
            }
        }
    }

    public function challengeReviewed(Challenge $challenge, User $actor, string $action, int $revision): void
    {
        $challenge->loadMissing(['challenger', 'opponent', 'casualMatch']);
        foreach ([$challenge->challenger, $challenge->opponent] as $profile) {
            $user = $this->account($profile);
            if ($user && $user->id !== $actor->id) {
                $title = $action === 'dispute' ? 'Placar do desafio contestado' : 'Placar do desafio confirmado';
                $this->notifications->send($user, 'challenge.update', $title, "/challenges/{$challenge->id}", "challenge:{$challenge->id}:{$action}:{$revision}:user:{$user->id}", ['challenge_id' => $challenge->id]);
            }
        }
        if ($action === 'confirm' && $challenge->casualMatch) {
            $m = $challenge->casualMatch;
            $title = "{$challenge->challenger->nickname} {$m->challenger_games}×{$m->opponent_games} {$challenge->opponent->nickname}";
            $this->activities->record($actor, 'challenge.completed', $title, $challenge, "challenge:{$challenge->id}:completed");
        }
    }

    private function championName(Tournament $t): ?string
    {
        return app(TournamentChampionService::class)->for($t)?->name;
    }

    /** @return Collection<int,User> */
    private function accountsFor(GameMatch $match): Collection
    {
        $ids = $match->pod_player_ids ?: array_filter([$match->player_one_id, $match->player_two_id]);

        return $this->usersForPlayers(Player::query()->whereIn('id', $ids)->with('profile')->get());
    }

    /** @return Collection<int,User> */
    private function accountsForTournament(Tournament $t): Collection
    {
        return $this->usersForPlayers($t->players()->with('profile')->get());
    }

    private function usersForPlayers(Collection $players): Collection
    {
        $ids = $players->map(fn (Player $p) => $p->profile?->account_user_id)->filter()->unique();

        return $ids->isEmpty() ? collect() : User::query()->whereIn('id', $ids)->get();
    }

    private function account(?PlayerProfile $profile): ?User
    {
        return $profile?->account_user_id ? User::find($profile->account_user_id) : null;
    }
}
