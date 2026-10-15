<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlayerProfile extends Model
{
    /** Sem 0/O/1/I/L para o codigo ser facil de ditar na mesa. */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    protected $fillable = ['user_id', 'account_user_id', 'nickname', 'avatar', 'preferred_deck_name', 'preferred_deck_colors', 'is_friend', 'discoverable'];

    protected function casts(): array { return ['is_friend' => 'boolean', 'discoverable' => 'boolean']; }

    protected static function booted(): void
    {
        static::creating(function (PlayerProfile $profile): void {
            $profile->friend_code ??= static::generateFriendCode();
        });
    }

    public static function generateFriendCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (static::query()->where('friend_code', $code)->exists());

        return $code;
    }

    /** Codigo exibido como XXXX-XXXX. */
    public function formattedFriendCode(): string
    {
        return substr((string) $this->friend_code, 0, 4).'-'.substr((string) $this->friend_code, 4);
    }

    public static function normalizeFriendCode(string $input): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input));
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function account(): BelongsTo { return $this->belongsTo(User::class, 'account_user_id'); }
    public function entries(): HasMany { return $this->hasMany(Player::class); }
    public function decks(): HasMany { return $this->hasMany(Deck::class); }
    public function achievements(): HasMany { return $this->hasMany(ProfileAchievement::class); }
    public function friendedBy(): BelongsToMany { return $this->belongsToMany(User::class)->withPivot('status')->withTimestamps(); }
}
