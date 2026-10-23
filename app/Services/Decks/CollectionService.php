<?php

namespace App\Services\Decks;

use App\Models\CollectionItem;
use App\Models\Deck;
use App\Models\PlayerProfile;
use App\Models\User;
use App\Services\DeckBuilderService;
use App\Services\FriendshipService;
use App\Services\ScryfallClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CollectionService
{
    public const MAX_IMPORT_LINES = 400;

    public function __construct(private readonly ScryfallClient $scryfall, private readonly DeckBuilderService $parser) {}

    public function add(User $user, array $card, array $data): CollectionItem
    {
        return DB::transaction(function () use ($user, $card, $data) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $foil = (bool) ($data['foil'] ?? false);
            $identity = ['user_id' => $user->id, 'scryfall_id' => strtolower($card['scryfall_id']), 'foil' => $foil, 'condition' => $data['condition']];
            $item = CollectionItem::firstOrNew($identity);
            $quantity = ($item->quantity ?? 0) + $data['quantity'];
            if ($quantity > 9999) {
                throw ValidationException::withMessages(['quantity' => 'Limite de 9.999 cópias por impressão, acabamento e condição.']);
            }
            $price = $foil ? ($card['price_usd_foil'] ?? $card['price_usd'] ?? null) : ($card['price_usd'] ?? null);
            $item->fill(['name' => $card['name'], 'set_code' => $card['set_code'] ?? null, 'image_url' => $card['image_url'] ?? null,
                'type_line' => $card['type_line'] ?? null, 'rarity' => $card['rarity'] ?? null, 'price_usd' => $price ?? $item->price_usd, 'quantity' => $quantity])->save();

            return $item;
        });
    }

    /**
     * Importa uma lista ("4 Lightning Bolt", "1x Sol Ring (CMM) 400", formato Arena/MTGO).
     *
     * @return array{added:int, lines:int, missing: list<string>}
     */
    public function import(User $user, string $list, bool $foil, string $condition): array
    {
        $parsed = array_slice($this->parser->parse($list, blankLineStartsSideboard: false), 0, self::MAX_IMPORT_LINES);
        if ($parsed === []) {
            return ['added' => 0, 'lines' => 0, 'missing' => []];
        }
        $lookup = $this->scryfall->collection($parsed);
        $added = 0;
        $missing = [];
        foreach ($parsed as $line) {
            $card = ScryfallClient::pick($lookup['found'], $line['name'], $line['set'] ?? null);
            if (! $card) {
                $missing[] = $line['name'];

                continue;
            }
            $this->add($user, $card, ['quantity' => $line['quantity'], 'foil' => $foil, 'condition' => $condition]);
            $added += $line['quantity'];
        }

        return ['added' => $added, 'lines' => count($parsed), 'missing' => array_values(array_unique($missing))];
    }

    /** @return array{cards:int, unique:int, foils:int, value:float, trade:int, priced:int} */
    public function stats(User $user): array
    {
        $row = CollectionItem::where('user_id', $user->id)->selectRaw('COALESCE(SUM(quantity),0) cards, COUNT(DISTINCT lower(name)) uniq, COALESCE(SUM(CASE WHEN foil THEN quantity ELSE 0 END),0) foils, COALESCE(SUM(quantity * price_usd),0) value, COALESCE(SUM(for_trade),0) trade, COALESCE(SUM(CASE WHEN price_usd IS NOT NULL THEN quantity ELSE 0 END),0) priced')->first();

        return ['cards' => (int) $row->cards, 'unique' => (int) $row->uniq, 'foils' => (int) $row->foils, 'value' => round((float) $row->value, 2), 'trade' => (int) $row->trade, 'priced' => (int) $row->priced];
    }

    public function missing(User $user, Deck $deck, bool $exact): array
    {
        $key = fn ($card) => $exact ? strtolower($card->scryfall_id) : mb_strtolower($card->name);
        $owned = CollectionItem::where('user_id', $user->id)->get()->groupBy($key)->map(fn ($cards) => (int) $cards->sum('quantity'));

        return $deck->cards()->get()->groupBy($key)->map(function ($cards, $key) use ($owned) {
            $required = (int) $cards->sum('quantity');
            $available = (int) $owned->get($key, 0);

            return ['name' => $cards->first()->name, 'printing' => $cards->first()->set_code, 'required' => $required, 'owned' => $available, 'missing' => max(0, $required - $available)];
        })->sortBy('name')->values()->all();
    }

    /**
     * Quanto de cada deck do jogador a coleção já cobre (por nome; terrenos básicos contam normalmente).
     *
     * @return Collection<int, array{deck: Deck, required:int, owned:int, percent:int, missing:int}>
     */
    public function deckCoverage(User $user): Collection
    {
        $owned = $this->ownedByName($user);
        $decks = $user->ensurePlayerProfile()->decks()->with('cards')->orderByDesc('is_primary')->orderBy('name')->get();

        return $decks->map(function (Deck $deck) use ($owned) {
            $need = $deck->cards->groupBy(fn ($c) => mb_strtolower($c->name))->map(fn ($c) => (int) $c->sum('quantity'));
            $required = (int) $need->sum();
            $have = (int) $need->map(fn ($qty, $name) => min($qty, $owned->get($name, 0)))->sum();

            return ['deck' => $deck, 'required' => $required, 'owned' => $have, 'missing' => $required - $have, 'percent' => $required ? (int) floor($have / $required * 100) : 0];
        });
    }

    /**
     * Lista de desejos: o que falta para montar todos os decks (por nome, pega o maior número entre os decks).
     *
     * @return Collection<string, array{name:string, missing:int, decks: list<string>}>
     */
    public function wishlist(User $user): Collection
    {
        $owned = $this->ownedByName($user);
        $wish = collect();
        foreach ($user->ensurePlayerProfile()->decks()->with('cards')->get() as $deck) {
            foreach ($deck->cards->groupBy(fn ($c) => mb_strtolower($c->name)) as $key => $cards) {
                if (preg_match('/^(plains|island|swamp|mountain|forest|wastes)$/', $key)) {
                    continue;
                } // básicos não entram no desejo
                $missing = (int) $cards->sum('quantity') - (int) $owned->get($key, 0);
                if ($missing <= 0) {
                    continue;
                }
                $current = $wish->get($key, ['name' => $cards->first()->name, 'missing' => 0, 'decks' => []]);
                $current['missing'] = max($current['missing'], $missing);
                $current['decks'][] = $deck->name;
                $wish->put($key, $current);
            }
        }

        return $wish->sortBy('name');
    }

    /**
     * Cartas da minha lista de desejos que amigos marcaram para troca.
     *
     * @return Collection<int, array{name:string, missing:int, offers: Collection}>
     */
    public function tradeMatches(User $user): Collection
    {
        $wish = $this->wishlist($user);
        if ($wish->isEmpty()) {
            return collect();
        }
        $friendAccounts = PlayerProfile::query()->whereIn('id', app(FriendshipService::class)->friendIds($user->id))->whereNotNull('account_user_id')->get()->keyBy('account_user_id');
        if ($friendAccounts->isEmpty()) {
            return collect();
        }

        $offers = CollectionItem::query()->whereIn('user_id', $friendAccounts->keys())->where('for_trade', '>', 0)
            ->whereIn(DB::raw('lower(name)'), $wish->keys()->all())->orderBy('name')->get();

        return $offers->groupBy(fn ($i) => mb_strtolower($i->name))->map(fn ($items, $key) => [
            'name' => $wish[$key]['name'], 'missing' => $wish[$key]['missing'],
            'offers' => $items->map(fn ($i) => ['profile' => $friendAccounts[$i->user_id], 'quantity' => $i->for_trade, 'set_code' => $i->set_code, 'foil' => $i->foil, 'condition' => $i->condition]),
        ])->values();
    }

    /** Fichário de trocas de alguém (só itens marcados). */
    public function tradeBinder(?int $userId): Collection
    {
        return $userId ? CollectionItem::where('user_id', $userId)->where('for_trade', '>', 0)->orderBy('name')->get() : collect();
    }

    private function ownedByName(User $user): Collection
    {
        return CollectionItem::where('user_id', $user->id)->get()->groupBy(fn ($i) => mb_strtolower($i->name))->map(fn ($i) => (int) $i->sum('quantity'));
    }
}
