<?php

namespace App\Policies;

use App\Models\Tournament;
use App\Models\User;

class TournamentPolicy
{
    public function view(User $user, Tournament $tournament): bool
    {
        return $tournament->user_id === $user->id;
    }

    public function update(User $user, Tournament $tournament): bool
    {
        return $this->view($user, $tournament);
    }

    public function delete(User $user, Tournament $tournament): bool
    {
        return $this->view($user, $tournament);
    }
}
