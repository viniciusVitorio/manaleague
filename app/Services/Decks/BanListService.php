<?php

namespace App\Services\Decks;

use App\Models\CardBan;
use App\Models\Tournament;
use App\Services\ScryfallClient;
use Illuminate\Support\Facades\DB;

/**
 * Cartas banidas por formato: marca "Banido" nas cartas do deck e barra decks com banidas
 * na inscrição e no check-in de torneios com as regras padrão do formato.
 * Draft e Outro não têm lista oficial: nada é marcado nem barrado.
 */
class BanListService
{
    /** Formato do ManaLeague => formato no Scryfall. */
    public const FORMATS = [
        'Pauper' => 'pauper', 'Commander' => 'commander', 'Standard' => 'standard',
        'Modern' => 'modern', 'Pioneer' => 'pioneer', 'Legacy' => 'legacy',
    ];

    public static function covers(?string $format): bool
    {
        return $format !== null && isset(self::FORMATS[$format]);
    }

    /**
     * Baixa a lista de cada formato e troca a lista salva. Formato que falhar fica como estava.
     *
     * @return array<string, int|null> quantidade de banidas por formato (null = falhou)
     */
    public function sync(ScryfallClient $scryfall): array
    {
        $result = [];
        foreach (self::FORMATS as $format => $scryfallFormat) {
            $names = $scryfall->bannedIn($scryfallFormat);
            $result[$format] = $names === null ? null : count($names);
            if ($names === null) continue;

            DB::transaction(function () use ($format, $names): void {
                CardBan::query()->where('format', $format)->delete();
                $now = now();
                foreach (array_chunk($names, 200) as $chunk) {
                    CardBan::query()->insert(array_map(fn (string $name) => ['format' => $format, 'card_name' => mb_substr($name, 0, 200), 'created_at' => $now, 'updated_at' => $now], $chunk));
                }
            });
        }

        return $result;
    }

    /** Nomes das cartas do deck banidas no formato, em ordem alfabética. */
    public function bannedInDeck(?int $deckId, ?string $format): array
    {
        return $deckId ? ($this->bannedByDeck([$deckId], $format)[$deckId] ?? []) : [];
    }

    /**
     * Uma consulta para vários decks (lista de inscritos).
     *
     * @param  iterable<int|null>  $deckIds
     * @return array<int, list<string>> deck_id => nomes banidos (só decks com alguma banida)
     */
    public function bannedByDeck(iterable $deckIds, ?string $format): array
    {
        $ids = collect($deckIds)->filter()->unique()->values();
        if ($ids->isEmpty() || ! self::covers($format)) return [];

        return DB::table('deck_cards')
            ->join('card_bans', fn ($join) => $join->on('card_bans.card_name', '=', 'deck_cards.name')->where('card_bans.format', $format))
            ->whereIn('deck_cards.deck_id', $ids)
            ->distinct()->orderBy('deck_cards.name')
            ->get(['deck_cards.deck_id', 'deck_cards.name'])
            ->groupBy('deck_id')
            ->map(fn ($rows) => $rows->pluck('name')->unique()->values()->all())
            ->all();
    }

    /** Banidas do deck que impedem a inscrição/check-in neste torneio (vazio quando o torneio aceita). */
    public function blockingCards(Tournament $tournament, ?int $deckId): array
    {
        return $tournament->blocksBannedCards() ? $this->bannedInDeck($deckId, $tournament->format) : [];
    }

    /** Mensagem para o próprio jogador, com as cartas (ele é o dono do deck). */
    public static function messageForOwner(Tournament $tournament, array $names): string
    {
        return 'Seu deck tem cartas banidas no '.$tournament->format.': '.self::listNames($names).'. Troque essas cartas ou escolha outro deck.';
    }

    /** Mensagem para o organizador: sem listar as cartas, que o deck pode ser privado. */
    public static function messageForOrganizer(Tournament $tournament, string $playerName): string
    {
        return 'O deck de '.$playerName.' tem cartas banidas no '.$tournament->format.'.';
    }

    private static function listNames(array $names): string
    {
        $shown = array_slice($names, 0, 5);
        $rest = count($names) - count($shown);
        $last = $rest > 0 ? ' e mais '.$rest : (count($shown) > 1 ? ' e '.array_pop($shown) : '');

        return implode(', ', $shown).$last;
    }
}
