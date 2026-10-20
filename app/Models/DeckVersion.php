<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class DeckVersion extends Model {
    public $timestamps = false;
    protected $guarded = ['id'];
    protected function casts(): array { return ['payload' => 'array', 'created_at' => 'datetime', 'number' => 'integer']; }
    protected static function booted(): void {
        static::updating(fn () => throw new \LogicException('Versões são imutáveis.'));
        static::deleting(fn () => throw new \LogicException('Versões são imutáveis.'));
    }
    public function uses(): HasMany { return $this->hasMany(DeckUse::class); }
}
