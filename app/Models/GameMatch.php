<?php

namespace App\Models;

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
        'player_one_games', 'player_two_games', 'status', 'played_at',
    ];

    protected function casts(): array
    {
        return ['played_at' => 'datetime', 'pod_player_ids' => 'array', 'pod_scores' => 'array'];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
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
