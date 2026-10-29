<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tournament extends Model
{
    use HasFactory;

    public const STATUS_SETUP = 'setup';

    public const STATUS_LEAGUE = 'league';

    public const STATUS_FINALS = 'finals';

    public const STATUS_FINISHED = 'finished';

    public const SYSTEMS = ['round_robin' => 'Todos contra todos', 'swiss' => 'Suíço', 'single_elimination' => 'Eliminação simples', 'double_elimination' => 'Dupla eliminação', 'commander_pods' => 'Pods Commander'];

    protected $attributes = ['system' => 'round_robin'];

    protected $fillable = ['system', 'ranked', 'round_limit', 'name', 'format', 'tournament_date', 'max_players', 'status', 'public_token', 'public_slug', 'invite_token'];

    protected function casts(): array
    {
        return ['tournament_date' => 'date', 'round_limit' => 'integer', 'ranked' => 'boolean', 'round_ends_at' => 'datetime', 'round_minutes' => 'integer'];
    }

    /** Cronômetro da rodada atual em andamento (definido pelo organizador). */
    public function roundTimerRunning(): bool
    {
        return $this->round_ends_at !== null && in_array($this->status, [self::STATUS_LEAGUE, self::STATUS_FINALS], true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class)->orderBy('name');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(GameMatch::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
