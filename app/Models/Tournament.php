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

    protected $fillable = ['name', 'format', 'tournament_date', 'max_players', 'status', 'public_token', 'public_slug', 'invite_token'];

    protected function casts(): array
    {
        return ['tournament_date' => 'date'];
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
}
