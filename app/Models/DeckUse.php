<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class DeckUse extends Model {
    public $timestamps = false;
    protected $guarded = ['id'];
    protected function casts(): array { return ['created_at' => 'datetime']; }
    protected static function booted(): void {
        static::updating(fn () => throw new \LogicException('Uso de deck é imutável.'));
        static::deleting(fn () => throw new \LogicException('Uso de deck é imutável.'));
    }
    public function version(): BelongsTo { return $this->belongsTo(DeckVersion::class, 'deck_version_id'); }
}
