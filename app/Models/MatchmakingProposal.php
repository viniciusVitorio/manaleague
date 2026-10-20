<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchmakingProposal extends Model
{
    protected $fillable = ['entry_one_id', 'entry_two_id', 'status', 'rating_gap', 'expires_at', 'accepted_one_at', 'accepted_two_at', 'ranked_match_id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_one_at' => 'datetime', 'accepted_two_at' => 'datetime', 'rating_gap' => 'integer'];
    }

    public function entryOne(): BelongsTo
    {
        return $this->belongsTo(MatchmakingEntry::class, 'entry_one_id');
    }

    public function entryTwo(): BelongsTo
    {
        return $this->belongsTo(MatchmakingEntry::class, 'entry_two_id');
    }

    public function rankedMatch(): BelongsTo
    {
        return $this->belongsTo(RankedMatch::class);
    }
}
