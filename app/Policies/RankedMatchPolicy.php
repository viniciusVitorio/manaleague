<?php

namespace App\Policies;

use App\Models\RankedMatch;
use App\Models\User;

class RankedMatchPolicy
{
    public function view(User $user, RankedMatch $match): bool
    {
        return $match->involvesUser($user) || $this->moderate($user, $match);
    }

    /** Admin ou dono/organizador do clube da temporada; nunca um dos participantes. */
    public function resolve(User $user, RankedMatch $match): bool
    {
        return ! $match->involvesUser($user) && $this->moderate($user, $match);
    }

    /** Invalidar resultado confirmado gera replay do pool: somente admin. */
    public function invalidate(User $user, RankedMatch $match): bool
    {
        return $user->isAdmin() && ! $match->involvesUser($user);
    }

    private function moderate(User $user, RankedMatch $match): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        $season = $match->season;

        return $season?->scope === 'club' && in_array($season->club?->roleFor($user), ['owner', 'organizer'], true);
    }
}
