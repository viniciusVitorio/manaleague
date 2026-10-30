<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Carta banida num formato (ex.: Pauper). A lista vem do Scryfall via BanListService::sync(). */
class CardBan extends Model
{
    protected $fillable = ['format', 'card_name'];
}
