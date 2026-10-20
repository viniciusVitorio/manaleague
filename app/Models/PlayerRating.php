<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerRating extends Model
{
    protected $fillable = ['season_id', 'format', 'player_profile_id', 'rating', 'played', 'wins', 'losses', 'draws', 'peak_rating', 'provisional_games_remaining', 'version'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'peak_rating' => 'integer', 'played' => 'integer', 'wins' => 'integer', 'losses' => 'integer', 'draws' => 'integer', 'provisional_games_remaining' => 'integer', 'version' => 'integer'];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(RankedSeason::class, 'season_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PlayerProfile::class, 'player_profile_id');
    }

    public function isProvisional(): bool
    {
        return $this->provisional_games_remaining > 0;
    }
}
