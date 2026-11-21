<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameMatch extends Model
{
    use HasFactory;

    public const STAGE_LEAGUE = 'league';

    public const STAGE_FINAL = 'final';

    public const STAGE_THIRD_PLACE = 'third_place';

    public const STATUS_PENDING = 'pending';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_BYE = 'bye';

    protected $table = 'matches';

    protected $fillable = [
        'round', 'stage', 'player_one_id', 'player_two_id', 'bracket', 'pod_player_ids', 'pod_scores',
        'player_one_games', 'player_two_games', 'status', 'played_at', 'pod_number', 'pod_tiebreak_scores', 'pod_resolution_note', 'pod_confirmed_at', 'pod_confirmed_by',
    ];

    protected function casts(): array
    {
        return ['played_at' => 'datetime', 'pod_player_ids' => 'array', 'pod_scores' => 'array', 'pod_tiebreak_scores' => 'array', 'pod_confirmed_at' => 'datetime'];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function podPhaseLabel(): string
    {
        return match ($this->stage) {
            'final' => 'Grande final',
            'consolation' => 'Consolação · 5º ao 8º',
            default => $this->round === $this->tournament->round_limit - 1 ? 'Semifinais' : 'Fase '.$this->round,
        };
    }

    /**
     * Partidas pendentes só da rodada que está valendo (a primeira do torneio com partida sem resultado).
     * No todos contra todos as rodadas seguintes já existem desde o início, mas ainda não começaram.
     */
    public function scopeInCurrentRound(Builder $query): Builder
    {
        return $query->whereRaw(
            'matches.round <= (select min(current_round.round) from matches current_round where current_round.tournament_id = matches.tournament_id and current_round.status = ?)',
            [self::STATUS_PENDING],
        );
    }

    public function playerOne(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_one_id');
    }

    public function playerTwo(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_two_id');
    }

    public function submission(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(MatchResultSubmission::class, 'match_id');
    }
}
