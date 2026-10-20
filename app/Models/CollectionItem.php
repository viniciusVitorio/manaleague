<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CollectionItem extends Model {
    public const CONDITIONS = ['NM', 'LP', 'MP', 'HP', 'DMG'];
    protected $guarded = ['id'];
    protected function casts(): array { return ['foil' => 'boolean', 'quantity' => 'integer']; }
}
