<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deck extends Model
{
    public const FORMATS = ['Pauper', 'Commander', 'Standard', 'Modern', 'Pioneer', 'Legacy', 'Draft', 'Outro'];

    protected $fillable = ['name', 'colors', 'format', 'is_primary', 'is_visible_to_friends'];


    // cover_card_id fica fora do $fillable de proposito: so muda pela rota propria (DeckController::cover),
    // que valida se a carta pertence a este deck.
    protected function casts(): array { return ['is_primary' => 'boolean', 'is_visible_to_friends' => 'boolean', 'cover_card_id' => 'integer', 'showcase_position' => 'integer']; }

    /** Quantos decks cabem na vitrine do perfil. showcase_position (1..N) só muda por ShowcaseController. */
    public const SHOWCASE_SLOTS = 3;

    public function profile(): BelongsTo { return $this->belongsTo(PlayerProfile::class, 'player_profile_id'); }
    public function cards(): HasMany { return $this->hasMany(DeckCard::class)->orderBy('cmc')->orderBy('name'); }

    public function isCommander(): bool { return $this->format === 'Commander'; }

    /** Mínimo de cartas para um deck contar como "coleção única" (deixa de fora decks de Limited de 40). */
    public const SINGLE_SET_MIN_CARDS = 60;

    /**
     * Coleção única: devolve o código do set (ex.: "LTR") quando todas as cartas que não são terreno
     * do deck principal (incluindo o comandante; o sideboard não conta) são da mesma coleção.
     * O deck precisa ter pelo menos 60 cartas no total, com os terrenos contando para esse mínimo.
     * Aceita modelos DeckCard ou arrays (cartas salvas na foto do deck de um torneio).
     */
    public static function singleSetOf(iterable $cards): ?string
    {
        $sets = [];
        $total = 0;
        foreach ($cards as $card) {
            if (! in_array(data_get($card, 'board'), [DeckCard::BOARD_MAIN, DeckCard::BOARD_COMMANDER], true)) continue;
            $total += (int) data_get($card, 'quantity');
            // Terrenos (básicos ou não) ficam de fora. Em cartas de duas faces vale a face da frente.
            if (str_contains(explode(' // ', (string) data_get($card, 'type_line'))[0], 'Land')) continue;
            $sets[strtoupper((string) data_get($card, 'set_code'))] = true;
        }

        $set = count($sets) === 1 ? (string) array_key_first($sets) : '';

        return $total >= self::SINGLE_SET_MIN_CARDS && $set !== '' ? $set : null;
    }

    public function singleSet(): ?string
    {
        return self::singleSetOf($this->relationLoaded('cards') ? $this->cards : $this->cards()->get(['board', 'set_code', 'quantity', 'type_line']));
    }

    /** Cor do deck derivada da identidade de cor das cartas, na ordem WUBRG. */
    public function computedColors(): ?string
    {
        $identity = $this->cards()->pluck('color_identity')->filter()->implode('');
        $colors = collect(str_split('WUBRG'))->filter(fn (string $color) => str_contains($identity, $color))->implode('');

        if ($colors !== '') return $colors;

        return $this->cards()->exists() ? 'C' : null;
    }
}
