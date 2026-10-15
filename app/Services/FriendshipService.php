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
        if ($profile->account_user_id === $user->id) return 'self';
        if (! $profile->account_user_id) return 'no_account';

        $current = $this->status($user, $profile);
        if ($current === self::ACCEPTED) return 'already_friends';
        if ($current === self::PENDING) return 'already_sent';

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
        if (! $mine->friendedBy()->whereKey($requester->id)->wherePivot('status', self::PENDING)->exists()) return false;

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
