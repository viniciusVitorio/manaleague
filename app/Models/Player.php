<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'deck_name', 'deck_colors'];

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function matchesAsPlayerOne(): HasMany
    {
        return $this->hasMany(GameMatch::class, 'player_one_id');
    }

    public function matchesAsPlayerTwo(): HasMany
    {
        return $this->hasMany(GameMatch::class, 'player_two_id');
    }
}
