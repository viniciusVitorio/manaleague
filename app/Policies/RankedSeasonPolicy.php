<?php

namespace App\Policies;

use App\Models\RankedSeason;
use App\Models\User;

class RankedSeasonPolicy
{
    public function manage(User $user, ?RankedSeason $season = null): bool
    {
        return $user->isAdmin();
    }
}
