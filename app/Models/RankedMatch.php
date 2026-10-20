<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RankedMatch extends Model
{
    public const STATUSES = ['pending' => 'Aguardando resultado', 'reported' => 'Aguardando confirmação', 'disputed' => 'Em disputa', 'confirmed' => 'Confirmada', 'invalidated' => 'Invalidada', 'cancelled' => 'Cancelada'];

    protected $fillable = ['season_id', 'format', 'player_one_profile_id', 'player_two_profile_id', 'source_type', 'source_id', 'status', 'best_of', 'is_ranked', 'k_factor', 'rules_version', 'score_one', 'score_two', 'played_at', 'confirmed_at', 'invalidated_at', 'invalidated_by', 'invalidation_reason'];

    protected function casts(): array
    {
        return ['is_ranked' => 'boolean', 'played_at' => 'datetime', 'confirmed_at' => 'datetime', 'invalidated_at' => 'datetime', 'best_of' => 'integer', 'k_factor' => 'integer', 'score_one' => 'integer', 'score_two' => 'integer'];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(RankedSeason::class, 'season_id');
    }

    public function playerOne(): BelongsTo
    {
        return $this->belongsTo(PlayerProfile::class, 'player_one_profile_id');
    }

    public function playerTwo(): BelongsTo
    {
        return $this->belongsTo(PlayerProfile::class, 'player_two_profile_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(RankedResultSubmission::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(RatingTransaction::class);
    }

    public function involvesUser(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return in_array($user->id, [$this->playerOne?->account_user_id, $this->playerTwo?->account_user_id], true);
    }

    public function profileFor(User $user): ?PlayerProfile
    {
        foreach ([$this->playerOne, $this->playerTwo] as $p) {
            if ($p?->account_user_id === $user->id) {
                return $p;
            }
        }

        return null;
    }

    public function opponentOf(PlayerProfile $profile): PlayerProfile
    {
        return $this->player_one_profile_id === $profile->id ? $this->playerTwo : $this->playerOne;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function winsNeeded(): int
    {
        return intdiv($this->best_of, 2) + 1;
    }
}
