<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionItem extends Model
{
    public const CONDITIONS = ['NM', 'LP', 'MP', 'HP', 'DMG'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['foil' => 'boolean', 'quantity' => 'integer', 'for_trade' => 'integer', 'price_usd' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
