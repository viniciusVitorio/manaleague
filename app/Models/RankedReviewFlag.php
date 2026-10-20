<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RankedReviewFlag extends Model
{
    protected $fillable = ['season_id', 'format', 'profile_a_id', 'profile_b_id', 'reason', 'details', 'status', 'reviewed_by'];

    public function season(): BelongsTo
    {
        return $this->belongsTo(RankedSeason::class, 'season_id');
    }

    public function profileA(): BelongsTo
    {
        return $this->belongsTo(PlayerProfile::class, 'profile_a_id');
    }

    public function profileB(): BelongsTo
    {
        return $this->belongsTo(PlayerProfile::class, 'profile_b_id');
    }
}
