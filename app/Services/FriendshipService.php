<?php

namespace App\Services;

use App\Models\PlayerProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Regras de amizade. A relacao e guardada em player_profile_user (user_id -> player_profile_id, status)
 * e, depois de aceita, existe nos dois sentidos.
 */
class FriendshipService
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    /** Limite de pedidos pendentes enviados (anti-spam). */
    public const MAX_PENDING_OUTGOING = 30;

    public function status(User $user, PlayerProfile $profile): ?string
    {
        return $user->friendProfiles()->whereKey($profile->id)->first()?->pivot?->status;
    }

    public function hasIncomingRequest(User $user, PlayerProfile $from): bool
    {
        $mine = $user->playerProfile()->first();

        return $mine && $from->account_user_id
            && $mine->friendedBy()->whereKey($from->account_user_id)->wherePivot('status', self::PENDING)->exists();
    }

    /**
     * Envia pedido; se a outra pessoa ja tinha pedido, vira amizade direto.
     *
     * @return 'self'|'no_account'|'already_friends'|'already_sent'|'limit'|'accepted'|'sent'
     */
    public function request(User $user, PlayerProfile $profile): string
    {
        if ($profile->account_user_id === $user->id) {
            return 'self';
        }
        if (! $profile->account_user_id) {
            return 'no_account';
        }

        $current = $this->status($user, $profile);
        if ($current === self::ACCEPTED) {
            return 'already_friends';
        }
        if ($current === self::PENDING) {
            return 'already_sent';
        }

        if ($this->hasIncomingRequest($user, $profile)) {
            $this->accept($user, $profile->account);

            return 'accepted';
        }

        if ($user->friendProfiles()->wherePivot('status', self::PENDING)->count() >= self::MAX_PENDING_OUTGOING) {
            return 'limit';
        }

        $user->friendProfiles()->attach($profile->id, ['status' => self::PENDING]);

        return 'sent';
    }

    /** $user aceita o pedido enviado por $requester. */
    public function accept(User $user, User $requester): bool
    {
        $mine = $user->ensurePlayerProfile();
        if (! $mine->friendedBy()->whereKey($requester->id)->wherePivot('status', self::PENDING)->exists()) {
            return false;
        }

        DB::transaction(function () use ($user, $mine, $requester): void {
            $mine->friendedBy()->updateExistingPivot($requester->id, ['status' => self::ACCEPTED]);
            $user->friendProfiles()->syncWithoutDetaching([$requester->ensurePlayerProfile()->id => ['status' => self::ACCEPTED]]);
        });

        return true;
    }

    /** Desfaz amizade, cancela pedido enviado ou recusa pedido recebido — sempre nos dois sentidos. */
    public function remove(User $user, PlayerProfile $profile): bool
    {
        $mine = $user->ensurePlayerProfile();

        return DB::transaction(function () use ($user, $mine, $profile): bool {
            $removed = $user->friendProfiles()->detach($profile->id);
            if ($profile->account_user_id) {
                $removed += $mine->friendedBy()->detach($profile->account_user_id);
            }

            return $removed > 0;
        });
    }

    public function friends(User $user): Collection
    {
        return $user->friendProfiles()->wherePivot('status', self::ACCEPTED)->with('account')->orderBy('nickname')->get();
    }

    /** IDs de perfil dos amigos aceitos de uma conta. */
    public function friendIds(?int $userId): Collection
    {
        if (! $userId) {
            return collect();
        }

        return DB::table('player_profile_user')->where('user_id', $userId)->where('status', self::ACCEPTED)->pluck('player_profile_id');
    }

    /**
     * Amigos de $profile vistos por $viewer. Perfis "so por codigo" (discoverable=false) so aparecem
     * se ja forem amigos do viewer ou o proprio viewer. Cada item traz 'state' e 'mutual'.
     */
    public function friendsOf(User $viewer, PlayerProfile $profile): Collection
    {
        $mine = $this->friendIds($viewer->id);
        $myProfileId = $viewer->ensurePlayerProfile()->id;
        $pending = $viewer->friendProfiles()->wherePivot('status', self::PENDING)->pluck('player_profiles.id');
        $incoming = $this->incoming($viewer)->pluck('id');

        return PlayerProfile::query()->whereIn('id', $this->friendIds($profile->account_user_id))->whereNotNull('account_user_id')
            ->where(fn ($q) => $q->where('discoverable', true)->orWhereIn('id', $mine)->orWhere('id', $myProfileId))
            ->orderBy('nickname')->get()
            ->map(function (PlayerProfile $p) use ($mine, $myProfileId, $pending, $incoming) {
                $p->setAttribute('friend_state', match (true) {
                    $p->id === $myProfileId => 'me',
                    $mine->contains($p->id) => 'friend',
                    $pending->contains($p->id) => 'sent',
                    $incoming->contains($p->id) => 'incoming',
                    default => 'none',
                });

                return $p;
            });
    }

    /**
     * Amigos de amigos que eu ainda nao conheco, ordenados por amigos em comum.
     *
     * @return Collection<int, array{profile: PlayerProfile, mutual: Collection}>
     */
    public function suggestions(User $user, int $limit = 6): Collection
    {
        $me = $user->ensurePlayerProfile();
        $mine = $this->friendIds($user->id);
        if ($mine->isEmpty()) {
            return collect();
        }
        $exclude = $mine->merge($user->friendProfiles()->pluck('player_profiles.id'))->merge($this->incoming($user)->pluck('id'))->push($me->id);
        $friendAccounts = PlayerProfile::query()->whereIn('id', $mine)->whereNotNull('account_user_id')->get()->keyBy('account_user_id');

        $rows = DB::table('player_profile_user')->whereIn('user_id', $friendAccounts->keys())->where('status', self::ACCEPTED)
            ->whereNotIn('player_profile_id', $exclude)->get(['user_id', 'player_profile_id']);
        $candidates = PlayerProfile::query()->whereIn('id', $rows->pluck('player_profile_id')->unique())
            ->whereNotNull('account_user_id')->where('discoverable', true)->get()->keyBy('id');

        return $rows->groupBy('player_profile_id')->filter(fn ($g, $id) => $candidates->has($id))
            ->map(fn ($g, $id) => ['profile' => $candidates[$id], 'mutual' => $g->map(fn ($r) => $friendAccounts[$r->user_id])->values()])
            ->sortBy([fn ($a, $b) => $b['mutual']->count() <=> $a['mutual']->count(), fn ($a, $b) => strcmp($a['profile']->nickname, $b['profile']->nickname)])
            ->take($limit)->values();
    }

    /** $viewer e $profile tem pelo menos um amigo aceito em comum. */
    public function isFriendOfFriend(User $viewer, PlayerProfile $profile): bool
    {
        if (! $profile->account_user_id) {
            return false;
        }
        $viewerFriendAccounts = PlayerProfile::query()->whereIn('id', $this->friendIds($viewer->id))->whereNotNull('account_user_id')->pluck('account_user_id');

        return $viewerFriendAccounts->isNotEmpty() && DB::table('player_profile_user')->whereIn('user_id', $viewerFriendAccounts)
            ->where('player_profile_id', $profile->id)->where('status', self::ACCEPTED)->exists();
    }

    public function incoming(User $user): Collection
    {
        return $user->ensurePlayerProfile()->friendedBy()->wherePivot('status', self::PENDING)
            ->with('playerProfile')->orderByPivot('created_at', 'desc')->get()
            ->map(fn (User $requester) => $requester->playerProfile)->filter()->values();
    }

    public function outgoing(User $user): Collection
    {
        return $user->friendProfiles()->wherePivot('status', self::PENDING)->orderBy('nickname')->get();
    }

    /** Busca por codigo exato (qualquer perfil) ou por apelido (apenas perfis descobriveis). */
    public function search(User $user, string $term): Collection
    {
        $term = trim($term);
        $code = PlayerProfile::normalizeFriendCode($term);

        $byCode = strlen($code) === 8
            ? PlayerProfile::query()->where('friend_code', $code)->whereNotNull('account_user_id')->get()
            : collect();

        $byName = mb_strlen($term) >= 3
            ? PlayerProfile::query()
                ->whereNotNull('account_user_id')
                ->where('discoverable', true)
                ->where('nickname', 'like', '%'.str_replace(['%', '_', '\\'], '', $term).'%')
                ->orderBy('nickname')->limit(20)->get()
            : collect();

        return $byCode->concat($byName)->unique('id')
            ->reject(fn (PlayerProfile $profile) => $profile->account_user_id === $user->id)
            ->values();
    }
}
