<?php

namespace App\Services;

use App\Models\Deck;
use App\Models\DeckCard;
use Illuminate\Support\Facades\DB;

class DeckBuilderService
{
    public const MAX_COPIES = 99;
    public const MAX_IMPORT_LINES = 250;

    /** Cartas que podem ter qualquer numero de copias alem dos terrenos basicos. */
    private const UNLIMITED = ['Relentless Rats', 'Shadowborn Apostle', 'Rat Colony', 'Persistent Petitioners', 'Dragon\'s Approach', 'Seven Dwarves', 'Slime Against Humanity', 'Hare Apparent', 'Templar Knight', 'Cid, Timeless Artificer', 'Nazgûl'];

    public function __construct(private readonly ScryfallClient $scryfall) {}

    /** Adiciona (ou soma) uma carta usando dados oficiais do Scryfall. */
    public function add(Deck $deck, array $card, int $quantity, string $board): DeckCard
    {
        return DB::transaction(function () use ($deck, $card, $quantity, $board): DeckCard {
            $existing = $deck->cards()->where('scryfall_id', $card['scryfall_id'])->where('board', $board)->lockForUpdate()->first();

            if ($existing) {
                $existing->update(['quantity' => min(self::MAX_COPIES, $existing->quantity + $quantity)]);
                $result = $existing;
            } else {
                $result = $deck->cards()->create([
                    ...collect($card)->only(['scryfall_id', 'name', 'mana_cost', 'cmc', 'type_line', 'color_identity', 'rarity', 'set_code', 'image_url', 'art_url'])->all(),
                    'board' => $board,
                    'quantity' => min(self::MAX_COPIES, $quantity),
                ]);
            }

            $this->syncColors($deck);

            return $result;
        });
    }

    /**
     * Importa uma decklist no formato usado por Arena/Moxfield/MTGO:
     *   4 Lightning Bolt
     *   1x Sol Ring (CMM) 395
     *   Sideboard / Commander como cabecalhos de secao
     *
     * @return array{added:int, missing:list<string>}
     */
    public function import(Deck $deck, string $list, bool $replace): array
    {
        $parsed = $this->parse($list, blankLineStartsSideboard: ! $deck->isCommander());
        if ($parsed === []) return ['added' => 0, 'missing' => []];

        $lookup = $this->scryfall->collection(array_column($parsed, 'name'));
        $missing = [];
        $added = 0;

        DB::transaction(function () use ($deck, $parsed, $lookup, $replace, &$missing, &$added): void {
            if ($replace) $deck->cards()->delete();

            foreach ($parsed as $line) {
                $card = $lookup['found'][mb_strtolower($line['name'])] ?? null;
                if (! $card) {
                    $missing[] = $line['name'];
                    continue;
                }

                $this->add($deck, $card, $line['quantity'], $line['board']);
                $added += $line['quantity'];
            }

            $this->syncColors($deck);
        });

        return ['added' => $added, 'missing' => array_values(array_unique($missing))];
    }

    /** @return list<array{quantity:int, name:string, board:string}> */
    public function parse(string $list, bool $blankLineStartsSideboard = true): array
    {
        $board = DeckCard::BOARD_MAIN;
        $lines = [];
        // Formato MTGO: uma linha em branco separa main e sideboard (so quando a lista nao tem cabecalhos).
        $hasHeaders = (bool) preg_match('/^\s*(sideboard|commander|comandante|deck)\s*:?\s*$/im', $list);
        $blankLineStartsSideboard = $blankLineStartsSideboard && ! $hasHeaders;

        foreach (preg_split('/\R/', $list) as $raw) {
            $line = trim($raw);
            if ($line === '' || str_starts_with($line, '//') || str_starts_with($line, '#')) {
                if ($line === '' && $blankLineStartsSideboard && $lines !== [] && $board === DeckCard::BOARD_MAIN) $board = DeckCard::BOARD_SIDE;
                continue;
            }

            $header = mb_strtolower(rtrim($line, ':'));
            if (in_array($header, ['deck', 'main', 'mainboard', 'maindeck'], true)) { $board = DeckCard::BOARD_MAIN; continue; }
            if (in_array($header, ['sideboard', 'side', 'sb'], true)) { $board = DeckCard::BOARD_SIDE; continue; }
            if (in_array($header, ['commander', 'comandante'], true)) { $board = DeckCard::BOARD_COMMANDER; continue; }
            if (in_array($header, ['companion', 'maybeboard', 'considering'], true)) { $board = 'skip'; continue; }

            if (! preg_match('/^(?:(SB:)\s*)?(\d{1,3})\s*x?\s+(.+)$/i', $line, $match)) {
                $match = [null, '', '1', $line];
            }

            if ($board === 'skip') continue;

            // Remove "(SET) 123", "*F*" e marcadores de foil.
            $name = trim(preg_replace('/\s+\([A-Z0-9]{2,6}\)(\s+[\w-]+)?(\s+\*[A-Z]+\*)?$/i', '', $match[3]));
            $name = trim(preg_replace('/\s+\*[A-Z]+\*$/i', '', $name));
            if ($name === '' || mb_strlen($name) > 150) continue;

            $lines[] = [
                'quantity' => max(1, min(self::MAX_COPIES, (int) $match[2])),
                'name' => $name,
                'board' => $match[1] ? DeckCard::BOARD_SIDE : $board,
            ];

            if (count($lines) >= self::MAX_IMPORT_LINES) break;
        }

        return $lines;
    }

    public function syncColors(Deck $deck): void
    {
        $colors = $deck->computedColors();
        if ($colors === null) return;

        $deck->update(['colors' => $colors]);
        if ($deck->is_primary) {
            $deck->profile->update(['preferred_deck_name' => $deck->name, 'preferred_deck_colors' => $colors]);
        }
    }

    /** Estatisticas exibidas na pagina do deck. */
    public function summary(Deck $deck): array
    {
        $cards = $deck->cards()->get();
        $playable = $cards->whereIn('board', [DeckCard::BOARD_MAIN, DeckCard::BOARD_COMMANDER]);
        $nonLand = $playable->reject(fn (DeckCard $card) => $card->isLand());

        $curve = collect(range(0, 7))->mapWithKeys(fn (int $cmc) => [$cmc === 7 ? '7+' : (string) $cmc => 0])->all();
        foreach ($nonLand as $card) {
            $bucket = $card->cmc >= 7 ? '7+' : (string) (int) $card->cmc;
            $curve[$bucket] += $card->quantity;
        }

        $groups = $cards->where('board', DeckCard::BOARD_MAIN)->groupBy(fn (DeckCard $card) => $card->typeGroup())
            ->sortBy(fn ($group, $label) => array_search($label, array_values(DeckCard::TYPE_GROUPS), true) === false ? 99 : array_search($label, array_values(DeckCard::TYPE_GROUPS), true));

        $nonLandCount = $nonLand->sum('quantity');

        return [
            'main' => $playable->sum('quantity'),
            'side' => $cards->where('board', DeckCard::BOARD_SIDE)->sum('quantity'),
            'lands' => $playable->filter(fn (DeckCard $card) => $card->isLand())->sum('quantity'),
            'avg_cmc' => $nonLandCount ? round($nonLand->sum(fn (DeckCard $card) => $card->cmc * $card->quantity) / $nonLandCount, 2) : 0,
            'curve' => $curve,
            'curve_max' => max(1, max($curve)),
            'commanders' => $cards->where('board', DeckCard::BOARD_COMMANDER)->values(),
            'groups' => $groups,
            'sideboard' => $cards->where('board', DeckCard::BOARD_SIDE)->values(),
            'cover' => $cards->firstWhere('board', DeckCard::BOARD_COMMANDER)?->art_url
                ?? $cards->where('board', DeckCard::BOARD_MAIN)->reject(fn (DeckCard $card) => $card->isLand())->sortByDesc('cmc')->first()?->art_url,
            'warnings' => $this->warnings($deck, $cards),
        ];
    }

    /** Avisos de construcao por formato (nao bloqueiam: o deck pode estar em montagem). */
    private function warnings(Deck $deck, $cards): array
    {
        $warnings = [];
        $main = $cards->whereIn('board', [DeckCard::BOARD_MAIN, DeckCard::BOARD_COMMANDER]);
        $total = $main->sum('quantity');

        if ($deck->isCommander()) {
            if ($cards->where('board', DeckCard::BOARD_COMMANDER)->isEmpty()) $warnings[] = 'Defina o comandante do deck.';
            if ($total !== 100) $warnings[] = "Decks Commander têm exatamente 100 cartas (atual: {$total}).";
            $copies = $main->reject(fn (DeckCard $card) => $card->isBasicLand() || in_array($card->name, self::UNLIMITED, true))
                ->groupBy('name')->filter(fn ($group) => $group->sum('quantity') > 1)->keys();
            if ($copies->isNotEmpty()) $warnings[] = 'Commander é singleton; cartas repetidas: '.$copies->take(5)->implode(', ').'.';
        } elseif ($deck->format && $deck->format !== 'Draft' && $deck->format !== 'Outro') {
            if ($total < 60) $warnings[] = "Decks construídos precisam de pelo menos 60 cartas (atual: {$total}).";
            $side = $cards->where('board', DeckCard::BOARD_SIDE)->sum('quantity');
            if ($side > 15) $warnings[] = "O sideboard aceita no máximo 15 cartas (atual: {$side}).";
            $over = $cards->reject(fn (DeckCard $card) => $card->isBasicLand() || in_array($card->name, self::UNLIMITED, true))
                ->groupBy('name')->filter(fn ($group) => $group->sum('quantity') > 4)->keys();
            if ($over->isNotEmpty()) $warnings[] = 'Máximo de 4 cópias por carta: '.$over->take(5)->implode(', ').'.';
        }

        return $warnings;
    }
}
