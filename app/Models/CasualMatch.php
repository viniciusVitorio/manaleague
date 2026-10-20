<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class CasualMatch extends Model {
    protected $guarded = ['id'];
    protected function casts(): array { return ['game_order' => 'array', 'confirmed_at' => 'datetime', 'revision' => 'integer', 'challenger_games' => 'integer', 'opponent_games' => 'integer']; }
    public function challenge(): BelongsTo { return $this->belongsTo(Challenge::class); }
}
