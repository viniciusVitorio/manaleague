<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Mesa do contador de vida compartilhada (ver LifeTableService). */
class LifeTable extends Model
{
    public const KIND_TOURNAMENT = 'tournament';

    public const KIND_RANKED = 'ranked';

    public const KIND_CHALLENGE = 'challenge';

    protected $fillable = ['kind', 'subject_id', 'code', 'owner_id', 'seats', 'settings', 'seat_users', 'seq', 'last_activity_at'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'seat_users' => 'array',
            'seats' => 'integer',
            'seq' => 'integer',
            'subject_id' => 'integer',
            'last_activity_at' => 'datetime',
        ];
    }

    public function ops(): HasMany
    {
        return $this->hasMany(LifeOp::class);
    }
}
