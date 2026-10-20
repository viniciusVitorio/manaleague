<?php

namespace App\Policies;

use App\Models\Tournament;
use App\Models\User;

class TournamentPolicy
{
    public function view(User $user, Tournament $tournament): bool
    {
        if ($tournament->club_id) {
            return \App\Models\Club::find($tournament->club_id)?->roleFor($user) !== null;
        }
        return $tournament->user_id === $user->id;
    }

    public function update(User $user, Tournament $tournament): bool
    {
        if ($tournament->club_id) {
            return in_array(\App\Models\Club::find($tournament->club_id)?->roleFor($user), ['owner', 'organizer'], true);
        }
        return $this->view($user, $tournament);
    }

    public function delete(User $user, Tournament $tournament): bool
    {
        if ($tournament->club_id) {
            return in_array(\App\Models\Club::find($tournament->club_id)?->roleFor($user), ['owner', 'organizer'], true);
        }
        return $this->view($user, $tournament);
    }
}
