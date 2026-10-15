<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deck extends Model
{
<<<<<<< HEAD
    public const FORMATS = ['Pauper', 'Commander', 'Standard', 'Modern', 'Pioneer', 'Legacy', 'Draft', 'Outro'];

    protected $fillable = ['name', 'colors', 'format', 'is_primary'];
=======
<<<<<<< Updated upstream
    protected $fillable = ['name', 'colors', 'is_primary'];
=======
    public const FORMATS = ['Pauper', 'Commander', 'Standard', 'Modern', 'Pioneer', 'Legacy', 'Draft', 'Outro'];
>>>>>>> f90ce10 (feat - deck configs)

    protected $fillable = ['name', 'colors', 'format', 'is_primary', 'is_visible_to_friends'];
>>>>>>> Stashed changes

    protected function casts(): array { return ['is_primary' => 'boolean', 'is_visible_to_friends' => 'boolean']; }

    public function profile(): BelongsTo { return $this->belongsTo(PlayerProfile::class, 'player_profile_id'); }
    public function cards(): HasMany { return $this->hasMany(DeckCard::class)->orderBy('cmc')->orderBy('name'); }

    public function isCommander(): bool { return $this->format === 'Commander'; }

    /** Cor do deck derivada da identidade de cor das cartas, na ordem WUBRG. */
    public function computedColors(): ?string
    {
        $identity = $this->cards()->pluck('color_identity')->filter()->implode('');
        $colors = collect(str_split('WUBRG'))->filter(fn (string $color) => str_contains($identity, $color))->implode('');

        if ($colors !== '') return $colors;

        return $this->cards()->exists() ? 'C' : null;
    }
}
