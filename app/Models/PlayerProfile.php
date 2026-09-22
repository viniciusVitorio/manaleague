<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlayerProfile extends Model
{
    protected $fillable = ['user_id', 'account_user_id', 'nickname', 'avatar', 'preferred_deck_name', 'preferred_deck_colors', 'is_friend'];

    protected function casts(): array { return ['is_friend' => 'boolean']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function account(): BelongsTo { return $this->belongsTo(User::class, 'account_user_id'); }
    public function entries(): HasMany { return $this->hasMany(Player::class); }
    public function decks(): HasMany { return $this->hasMany(Deck::class); }
    public function friendedBy(): BelongsToMany { return $this->belongsToMany(User::class)->withTimestamps(); }
}
