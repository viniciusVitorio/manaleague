<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
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
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'is_admin' => 'boolean', 'show_deck_ownership' => 'boolean'];
    }

    /** is_admin nao e fillable: so via `php artisan ml:admin`. */
    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function tournaments(): HasMany
    {
        return $this->hasMany(Tournament::class);
    }

    public function playerProfiles(): HasMany
    {
        return $this->hasMany(PlayerProfile::class);
    }

    /** Emoji do perfil ou inicial do nome, para o circulo de avatar. */
    public function avatarMark(): string
    {
        return $this->playerProfile?->mark() ?? mb_strtoupper(mb_substr($this->name ?: '?', 0, 1));
    }

    /** URL da foto do perfil (null sem foto). */
    public function avatarUrl(): ?string
    {
        return $this->playerProfile?->avatarUrl();
    }

    /** Apelido do perfil (com emoji) ou nome da conta. */
    public function displayName(): string
    {
        return $this->playerProfile?->displayName() ?? $this->name;
    }

    public function playerProfile(): HasOne
    {
        return $this->hasOne(PlayerProfile::class, 'account_user_id');
    }

    public function clubs(): BelongsToMany
    {
        return $this->belongsToMany(Club::class)->withPivot('role')->withTimestamps();
    }

    public function friendProfiles(): BelongsToMany
    {
        return $this->belongsToMany(PlayerProfile::class)->withPivot('status')->withTimestamps();
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function ensurePlayerProfile(): PlayerProfile
    {
        $profile = $this->playerProfile()->first();
        if ($profile) {
            return $profile;
        }

        $profile = $this->playerProfiles()->whereNull('account_user_id')->where('nickname', $this->name)->first();
        if ($profile) {
            $profile->update(['account_user_id' => $this->id]);

            return $profile;
        }

        return $this->playerProfile()->create(['user_id' => $this->id, 'nickname' => $this->name]);
    }
}
