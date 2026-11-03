<?php

namespace App\Services\Decks;

use App\Models\DeckCard;
use App\Services\ScryfallClient;
use Illuminate\Support\Facades\DB;

/**
 * Cartas que não podem entrar num deck de Pauper.
 *
 * A fonte é o campo legalities.pauper do Scryfall, gravado em deck_cards.pauper_legality quando a carta entra no deck.
 * Ele vale para a carta inteira (todas as impressões): uma carta que é rara nesta edição mas saiu como comum em outra
 * vem "legal". Por isso a raridade da edição salva nunca é usada aqui.
 *
 * - "not_legal": nunca saiu como comum numa edição válida, marcada como "Inválida"; como a banida, tira o deck de
 *   torneios com as regras do formato (BanListService::blockedByDeck);
 * - "banned": fica com a tag "Banido" de BanListService (lista do formato, atualizada todo dia);
 * - nula: ainda não consultada (cartas antigas), sem marca até cards:sync-legality preencher.
 */
class PauperLegalityService
{
    public const FORMAT = 'Pauper';

    public const NOT_LEGAL = 'not_legal';

    /**
     * Nomes das cartas fora do Pauper, em ordem alfabética. Vazio se o deck não é de Pauper.
     *
     * @param  iterable<DeckCard>  $cards
     * @return list<string>
     */
    public static function invalidNames(iterable $cards, ?string $format): array
    {
        if ($format !== self::FORMAT) {
            return [];
        }

        return collect($cards)
            ->filter(fn (DeckCard $card) => $card->pauper_legality === self::NOT_LEGAL)
            ->pluck('name')->unique()->sort()->values()->all();
    }

    /**
     * Cartas inválidas de vários decks numa consulta (lista de inscritos, escolha de deck). Vazio fora do Pauper.
     *
     * @param  iterable<int|null>  $deckIds
     * @return array<int, list<string>> deck_id => nomes em ordem alfabética (só decks com alguma inválida)
     */
    public function invalidByDeck(iterable $deckIds, ?string $format): array
    {
        $ids = collect($deckIds)->filter()->unique()->values();
        if ($format !== self::FORMAT || $ids->isEmpty()) {
            return [];
        }

        return DB::table('deck_cards')
            ->whereIn('deck_id', $ids)->where('pauper_legality', self::NOT_LEGAL)
            ->distinct()->orderBy('name')
            ->get(['deck_id', 'name'])
            ->groupBy('deck_id')
            ->map(fn ($rows) => $rows->pluck('name')->unique()->values()->all())
            ->all();
    }

    /**
     * Consulta de novo no Scryfall a legalidade de todas as cartas salvas em decks, em lotes de 75.
     * Corrige cartas antigas (sem o dado) e cartas que mudaram (ganharam impressão comum).
     * Carta que o Scryfall não devolveu fica como estava.
     *
     * @return array{checked: int, updated: int}
     */
    public function sync(ScryfallClient $scryfall): array
    {
        $ids = DeckCard::query()->distinct()->pluck('scryfall_id')->map(fn ($id) => (string) $id)->unique()->values()->all();
        $updated = 0;

        foreach ($scryfall->byIds($ids) as $id => $card) {
            if ($card['pauper_legality'] === null) {
                continue;
            }

            $updated += DB::table('deck_cards')
                ->where('scryfall_id', $id)
                ->where(fn ($query) => $query->whereNull('pauper_legality')->orWhere('pauper_legality', '!=', $card['pauper_legality']))
                ->update(['pauper_legality' => $card['pauper_legality']]);
        }

        return ['checked' => count($ids), 'updated' => $updated];
    }
}
