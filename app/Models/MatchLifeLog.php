<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Histórico de vida de uma partida jogada com o contador (ver LifeLogService). */
class MatchLifeLog extends Model
{
    public const TYPES = ['tournament', 'challenge', 'ranked'];

    protected $fillable = ['match_type', 'match_id', 'recorded_by', 'starting_life', 'seats', 'games'];

    protected function casts(): array
    {
        return ['seats' => 'array', 'games' => 'array', 'starting_life' => 'integer', 'match_id' => 'integer'];
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public static function of(string $type, int $id): ?self
    {
        return static::query()->where(['match_type' => $type, 'match_id' => $id])->first();
    }
}
