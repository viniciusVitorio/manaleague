<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RankedSeasonFormat extends Model
{
    protected $fillable = ['season_id', 'format', 'enabled', 'k_factor', 'starting_rating', 'allow_draws'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'allow_draws' => 'boolean', 'k_factor' => 'integer', 'starting_rating' => 'integer'];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(RankedSeason::class, 'season_id');
    }
}
