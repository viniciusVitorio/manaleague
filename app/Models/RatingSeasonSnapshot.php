<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RatingSeasonSnapshot extends Model
{
    protected $fillable = ['season_id', 'format', 'player_profile_id', 'final_rating', 'peak_rating', 'rank', 'tier', 'stats'];

    protected function casts(): array
    {
        return ['stats' => 'array'];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(RankedSeason::class, 'season_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PlayerProfile::class, 'player_profile_id');
    }
}
