<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchmakingEntry extends Model
{
    protected $fillable = ['player_profile_id', 'season_id', 'format', 'club_id', 'status', 'rating_at_join', 'session_note', 'joined_at', 'last_confirmed_at', 'expires_at'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'last_confirmed_at' => 'datetime', 'expires_at' => 'datetime', 'rating_at_join' => 'integer'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PlayerProfile::class, 'player_profile_id');
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(RankedSeason::class, 'season_id');
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
