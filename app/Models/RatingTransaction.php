<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Lancamento imutavel do ledger de rating: nunca editar nem apagar. */
class RatingTransaction extends Model
{
    protected $fillable = ['ranked_match_id', 'season_id', 'format', 'player_profile_id', 'rating_before', 'rating_after', 'delta', 'expected_score', 'actual_score', 'k_factor', 'event_type', 'correction_seq', 'floor_applied', 'rules_version', 'note', 'effective_at'];

    protected function casts(): array
    {
        return ['effective_at' => 'datetime', 'floor_applied' => 'boolean', 'rating_before' => 'integer', 'rating_after' => 'integer', 'delta' => 'integer', 'expected_score' => 'float', 'actual_score' => 'float'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Lançamentos de rating são imutáveis.'));
        static::deleting(fn () => throw new LogicException('Lançamentos de rating são imutáveis.'));
    }

    public function rankedMatch(): BelongsTo
    {
        return $this->belongsTo(RankedMatch::class);
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
