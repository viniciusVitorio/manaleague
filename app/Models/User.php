<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }

    public function tournaments(): HasMany { return $this->hasMany(Tournament::class); }
    public function playerProfiles(): HasMany { return $this->hasMany(PlayerProfile::class); }
    public function playerProfile(): HasOne { return $this->hasOne(PlayerProfile::class, 'account_user_id'); }
    public function friendProfiles(): BelongsToMany { return $this->belongsToMany(PlayerProfile::class)->withTimestamps(); }

    public function ensurePlayerProfile(): PlayerProfile
    {
        $profile = $this->playerProfile()->first();
        if ($profile) return $profile;

        $profile = $this->playerProfiles()->whereNull('account_user_id')->where('nickname', $this->name)->first();
        if ($profile) {
            $profile->update(['account_user_id' => $this->id]);
            return $profile;
        }

        return $this->playerProfile()->create(['user_id' => $this->id, 'nickname' => $this->name]);
    }
}
