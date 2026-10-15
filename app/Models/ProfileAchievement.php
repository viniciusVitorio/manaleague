<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileAchievement extends Model
{
    public $timestamps = false;

    protected $table = 'player_profile_achievements';

    protected $fillable = ['achievement', 'unlocked_at', 'seen_at'];

    protected function casts(): array { return ['unlocked_at' => 'datetime', 'seen_at' => 'datetime']; }

    public function profile(): BelongsTo { return $this->belongsTo(PlayerProfile::class, 'player_profile_id'); }
}
