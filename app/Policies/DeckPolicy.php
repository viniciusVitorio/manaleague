<?php

namespace App\Policies;

use App\Models\Deck;
use App\Models\User;
use App\Services\FriendshipService;

/** Mesma regra da página do deck (DeckController::show): o dono sempre; amigos aceitos só nos decks visíveis para amigos. */
class DeckPolicy
{
    public function view(User $user, Deck $deck): bool
    {
        if ($user->playerProfile?->id === $deck->player_profile_id) {
            return true;
        }

        return $deck->is_visible_to_friends
            && $user->friendProfiles()->whereKey($deck->player_profile_id)->wherePivot('status', FriendshipService::ACCEPTED)->exists();
    }
}
