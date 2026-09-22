<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    use HasFactory;

    protected $fillable = ['player_profile_id', 'name', 'deck_name', 'deck_colors'];

    public function profile(): BelongsTo { return $this->belongsTo(PlayerProfile::class, 'player_profile_id'); }
    public function tournament(): BelongsTo { return $this->belongsTo(Tournament::class); }
    public function matchesAsPlayerOne(): HasMany { return $this->hasMany(GameMatch::class, 'player_one_id'); }
    public function matchesAsPlayerTwo(): HasMany { return $this->hasMany(GameMatch::class, 'player_two_id'); }
}
