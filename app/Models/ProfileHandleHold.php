<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Endereço de perfil que o dono deixou: fica reservado e redireciona para o novo até expires_at. */
class ProfileHandleHold extends Model
{
    protected $fillable = ['player_profile_id', 'handle', 'expires_at'];

    protected function casts(): array { return ['expires_at' => 'datetime']; }

    public function profile(): BelongsTo { return $this->belongsTo(PlayerProfile::class, 'player_profile_id'); }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
