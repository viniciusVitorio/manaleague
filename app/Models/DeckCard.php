<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DeckCard extends Model
{
    public const BOARD_MAIN = 'main';
    public const BOARD_SIDE = 'side';
    public const BOARD_COMMANDER = 'commander';
    public const BOARDS = [self::BOARD_COMMANDER, self::BOARD_MAIN, self::BOARD_SIDE];

    /** Dois no máximo: comandante e parceiro (ou Background). */
    public const MAX_COMMANDERS = 2;

    /** Grupos exibidos na lista do deck, na ordem de prioridade do type_line. */
    public const TYPE_GROUPS = [
        'Creature' => 'Criaturas', 'Planeswalker' => 'Planeswalkers', 'Battle' => 'Batalhas',
        'Instant' => 'Mágicas instantâneas', 'Sorcery' => 'Feitiços', 'Artifact' => 'Artefatos',
        'Enchantment' => 'Encantamentos', 'Land' => 'Terrenos',
    ];

    protected $attributes = ['foil' => false];

    protected $fillable = [
        'scryfall_id', 'board', 'foil', 'quantity', 'name', 'mana_cost', 'cmc', 'type_line',
        'color_identity', 'rarity', 'pauper_legality', 'set_code', 'image_url', 'art_url',
    ];

    protected function casts(): array { return ['quantity' => 'integer', 'cmc' => 'float', 'foil' => 'boolean']; }

    protected static function booted(): void
    {
        // Qualquer carta adicionada, alterada, movida ou removida atualiza a data da lista do deck.
        // "updated" também dispara no increment/decrement (mover parte das cópias para o sideboard).
        foreach (['created', 'updated', 'deleted'] as $event) {
            static::$event(fn (DeckCard $card) => Deck::touchList($card->deck_id));
        }
    }

    public function deck(): BelongsTo { return $this->belongsTo(Deck::class); }

    public function typeGroup(): string
    {
        foreach (self::TYPE_GROUPS as $type => $label) {
            if (str_contains((string) $this->type_line, $type)) return $label;
        }

        return 'Outros';
    }

    public function isLand(): bool { return str_contains((string) $this->type_line, 'Land'); }

    public function isBasicLand(): bool { return str_contains((string) $this->type_line, 'Basic Land'); }

    /** Tipos que podem comandar: criatura ou planeswalker lendário, ou Background. */
    public static function typeCanCommand(?string $type): bool
    {
        $type = (string) $type;

        return (str_contains($type, 'Legendary') && (str_contains($type, 'Creature') || str_contains($type, 'Planeswalker')))
            || str_contains($type, 'Background');
    }

    public function canCommand(): bool { return self::typeCanCommand($this->type_line); }

    /** Nome curto para botões: "Atraxa, Praetors' Voice" vira "Atraxa". */
    public function shortName(): string { return Str::before($this->faceNames()[0] ?? $this->name, ','); }

    /** Converte "{2}{U}{U}" em simbolos para exibir com CSS. */
    public function manaSymbols(): array
    {
        preg_match_all('/\{([^}]+)\}/', (string) $this->mana_cost, $matches);

        return $matches[1];
    }

    /**
     * Custos por face: "{5}{R}{R} // {4}{R}" vira [['5','R','R'], ['4','R']].
     * Cartas de uma face so retornam um grupo.
     */
    public function manaGroups(): array
    {
        $groups = [];
        foreach (explode('//', (string) $this->mana_cost) as $part) {
            preg_match_all('/\{([^}]+)\}/', $part, $matches);
            if ($matches[1] !== []) {
                $groups[] = $matches[1];
            }
        }

        return $groups;
    }

    /** Nome das faces: "Smaug, the Great Calamity // Spew Flame" vira ['Smaug, the Great Calamity', 'Spew Flame']. */
    public function faceNames(): array
    {
        return array_values(array_filter(array_map('trim', explode('//', (string) $this->name)), 'strlen'));
    }
}
