<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Club extends Model
{
    protected $fillable = ['name', 'description', 'privacy', 'location'];

    // invite_token fora do fillable: so muda pelo ClubService (gera/regenera).
    protected $hidden = ['invite_token'];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function posts(): HasMany
    {
        return $this->hasMany(ClubPost::class);
    }

    public function tournaments(): HasMany
    {
        return $this->hasMany(Tournament::class);
    }

    public function roleFor(User $user): ?string
    {
        return $this->members()->whereKey($user->id)->first()?->pivot->role;
    }
}
