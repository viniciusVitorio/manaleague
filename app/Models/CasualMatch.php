<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class CasualMatch extends Model {
    protected $guarded = ['id'];
    protected function casts(): array { return ['game_order' => 'array', 'reported_at' => 'datetime', 'confirmed_at' => 'datetime', 'auto_confirmed' => 'boolean', 'revision' => 'integer', 'challenger_games' => 'integer', 'opponent_games' => 'integer']; }
    public function challenge(): BelongsTo { return $this->belongsTo(Challenge::class); }
    /** Quando o placar enviado é confirmado sozinho, se ninguém confirmar ou contestar antes. */
    public function autoConfirmAt(): ?\Carbon\CarbonInterface { return $this->status === 'reported' ? $this->reported_at?->copy()->addDays(Challenge::CONFIRM_DAYS) : null; }
}
