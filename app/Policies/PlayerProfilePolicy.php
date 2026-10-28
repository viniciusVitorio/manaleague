<?php

namespace App\Policies;

use App\Models\PlayerProfile;
use App\Models\User;
use App\Services\FriendshipService;

/**
 * Perfil como numa rede social: quem tem o link abre o perfil, a menos que a pessoa
 * tenha escolhido "Só com código" (discoverable = false). Nesse caso, só quem já tem
 * vínculo vê: amizade ou pedido, ou organizador de um torneio em que ela jogou.
 * Decks privados, lista de amigos e fichário continuam restritos dentro da página.
 */
class PlayerProfilePolicy
{
    public function __construct(private readonly FriendshipService $friendships) {}

    public function view(User $user, PlayerProfile $profile): bool
    {
        return $profile->account_user_id === $user->id
            || $profile->discoverable
            || $this->hasLink($user, $profile);
    }

    /** Pedir amizade pelo perfil: perfis abertos ou quem já tem vínculo (perfis "Só com código" pedem o código). */
    public function befriend(User $user, PlayerProfile $profile): bool
    {
        return $profile->account_user_id !== null
            && $profile->account_user_id !== $user->id
            && ($profile->discoverable
                || $this->friendships->hasIncomingRequest($user, $profile)
                || $this->organizedTournamentWith($user, $profile));
    }

    private function hasLink(User $user, PlayerProfile $profile): bool
    {
        return $this->friendships->status($user, $profile) !== null
            || $this->friendships->hasIncomingRequest($user, $profile)
            || $this->organizedTournamentWith($user, $profile);
    }

    private function organizedTournamentWith(User $user, PlayerProfile $profile): bool
    {
        return $profile->entries()->whereHas('tournament', fn ($query) => $query->where('user_id', $user->id))->exists();
    }
}
