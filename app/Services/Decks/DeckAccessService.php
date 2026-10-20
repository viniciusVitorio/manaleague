<?php
namespace App\Services\Decks;
use App\Models\{Deck, User};
use Illuminate\Database\Eloquent\Builder;
class DeckAccessService {
    public function visible(User $user): Builder {
        $owner = $user->ensurePlayerProfile()->id;
        $friends = $user->friendProfiles()->wherePivot('status', 'accepted')->pluck('player_profiles.id');
        return Deck::query()->where(fn ($q) => $q->where('player_profile_id', $owner)
            ->orWhere(fn ($q) => $q->whereIn('player_profile_id', $friends)->where('is_visible_to_friends', true)));
    }
    public function authorize(User $user, Deck $deck): void { abort_unless($this->visible($user)->whereKey($deck->id)->exists(), 404); }
}
