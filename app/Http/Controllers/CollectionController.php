<?php

namespace App\Http\Controllers;

use App\Models\CollectionItem;
use App\Models\Deck;
use App\Services\Decks\CollectionService;
use App\Services\Decks\DeckAccessService;
use App\Services\ScryfallClient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CollectionController extends Controller
{
    public const COLORS = ['W' => 'Branco', 'U' => 'Azul', 'B' => 'Preto', 'R' => 'Vermelho', 'G' => 'Verde', 'M' => 'Multicolor', 'C' => 'Incolor'];

    /** Valor = trecho procurado na linha de tipo (em inglês, como vem do Scryfall). */
    public const TYPES = ['Creature' => 'Criatura', 'Instant' => 'Mágica instantânea', 'Sorcery' => 'Feitiço', 'Artifact' => 'Artefato',
        'Enchantment' => 'Encantamento', 'Planeswalker' => 'Planeswalker', 'Land' => 'Terreno', 'Battle' => 'Batalha'];

    public const RARITIES = ['common' => 'Comum', 'uncommon' => 'Incomum', 'rare' => 'Rara', 'mythic' => 'Mítica'];

    public function index(Request $request, CollectionService $service)
    {
        $user = $request->user();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'], 'show' => ['nullable', Rule::in(['all', 'trade', 'foil'])], 'sort' => ['nullable', Rule::in(['name', 'value', 'recent'])],
            'color' => ['nullable', Rule::in(array_keys(self::COLORS))], 'type' => ['nullable', Rule::in(array_keys(self::TYPES))],
            'rarity' => ['nullable', Rule::in(array_keys(self::RARITIES))], 'min' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'max' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'view' => ['nullable', Rule::in(['grid', 'list'])],
        ]);
        $query = CollectionItem::where('user_id', $user->id);
        if ($q = trim($filters['q'] ?? '')) {
            $query->where(fn ($w) => $w->where('name', 'like', '%'.str_replace(['%', '_'], '', $q).'%')->orWhere('set_code', strtoupper($q)));
        }
        match ($filters['show'] ?? 'all') {
            'trade' => $query->where('for_trade', '>', 0), 'foil' => $query->where('foil', true), default => null
        };
        match ($color = $filters['color'] ?? null) {
            null => null,
            'C' => $query->where('color_identity', 'C'),
            'M' => $query->whereNotNull('color_identity')->where('color_identity', '!=', 'C')->whereRaw('length(color_identity) > 1'),
            default => $query->where('color_identity', 'like', '%'.$color.'%'),
        };
        if ($type = $filters['type'] ?? null) {
            $query->where('type_line', 'like', '%'.$type.'%');
        }
        if ($rarity = $filters['rarity'] ?? null) {
            $query->where('rarity', $rarity);
        }
        if (isset($filters['min'])) {
            $query->where('price_usd', '>=', $filters['min']);
        }
        if (isset($filters['max'])) {
            $query->where('price_usd', '<=', $filters['max']);
        }
        match ($filters['sort'] ?? 'name') {
            'value' => $query->orderByRaw('price_usd IS NULL')->orderByDesc('price_usd'),
            'recent' => $query->latest('updated_at'),
            default => $query->orderBy('name'),
        };
        $items = $query->orderBy('id')->paginate(48)->withQueryString();

        return view('decks.collection', [
            'items' => $items, 'filters' => $filters, 'stats' => $service->stats($user),
            'coverage' => $service->deckCoverage($user), 'wishlist' => $service->wishlist($user), 'matches' => $service->tradeMatches($user),
        ]);
    }

    public function store(Request $request, ScryfallClient $scryfall, CollectionService $service)
    {
        $data = $request->validate(['scryfall_id' => ['required', 'uuid'], 'quantity' => ['required', 'integer', 'min:1', 'max:9999'], 'foil' => ['nullable', 'boolean'], 'condition' => ['required', Rule::in(CollectionItem::CONDITIONS)]]);
        $card = $scryfall->card($data['scryfall_id']);
        if (! $card) {
            return back()->withInput()->withErrors(['scryfall_id' => 'Carta não encontrada ou Scryfall indisponível. Nada foi adicionado.']);
        }
        $service->add($request->user(), $card, $data);

        return back()->with('status', "{$data['quantity']}× {$card['name']} adicionada à coleção.");
    }

    public function import(Request $request, CollectionService $service)
    {
        $data = $request->validate(['list' => ['required', 'string', 'max:20000'], 'foil' => ['nullable', 'boolean'], 'condition' => ['required', Rule::in(CollectionItem::CONDITIONS)]]);
        $result = $service->import($request->user(), $data['list'], (bool) ($data['foil'] ?? false), $data['condition']);
        if ($result['lines'] === 0) {
            return back()->withInput()->withErrors(['list' => 'Nenhuma linha reconhecida. Use uma carta por linha, ex.: 4 Lightning Bolt.']);
        }
        $back = back()->with('status', "{$result['added']} cartas importadas.");

        return $result['missing'] ? $back->withErrors(['list' => 'Não encontradas: '.implode(', ', array_slice($result['missing'], 0, 15)).(count($result['missing']) > 15 ? '…' : '')]) : $back;
    }

    public function update(Request $request, CollectionItem $item)
    {
        abort_unless($item->user_id === $request->user()->id, 404);
        // Atalho +1/−1: chegar a zero remove o item.
        if ($request->has('delta')) {
            $delta = (int) $request->validate(['delta' => ['required', 'integer', Rule::in([-1, 1])]])['delta'];
            $quantity = $item->quantity + $delta;
            if ($quantity <= 0) {
                $item->delete();

                return $this->backTo($request, null)->with('status', "{$item->name} removida da coleção.");
            }
            if ($quantity > 9999) {
                return $this->backTo($request, $item)->withErrors(['quantity' => 'Limite de 9.999 cópias.']);
            }
            $item->update(['quantity' => $quantity, 'for_trade' => min($item->for_trade, $quantity)]);

            return $this->backTo($request, $item)->with('status', "{$quantity}× {$item->name}.");
        }
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:9999'], 'for_trade' => ['nullable', 'integer', 'min:0']]);
        $data['for_trade'] = min((int) ($data['for_trade'] ?? $item->for_trade), (int) $data['quantity']);
        $item->update($data);

        return $this->backTo($request, $item)->with('status', $item->for_trade ? "Salvo. {$item->for_trade} para troca." : 'Quantidade atualizada.');
    }

    /** Volta para a mesma página da lista, rolando até o item editado. */
    private function backTo(Request $request, ?CollectionItem $item)
    {
        $previous = url()->previous();
        $sameSite = str_starts_with($previous, route('collection.index'));

        return redirect()->to(($sameSite ? $previous : route('collection.index')).($item ? '#item-'.$item->id : ''));
    }

    /** Liga/desliga o "você tem X/Y" nas cartas dos próprios decks. */
    public function toggleDeckOwnership(Request $request)
    {
        $data = $request->validate(['show' => ['required', 'boolean']]);
        $request->user()->forceFill(['show_deck_ownership' => (bool) $data['show']])->save();

        return back()->with('status', $data['show'] ? 'Mostrando quantas cópias você tem na coleção.' : 'Indicador da coleção oculto nos decks.');
    }

    public function addMissing(Request $request, Deck $deck, CollectionService $service)
    {
        abort_unless($deck->player_profile_id === $request->user()->ensurePlayerProfile()->id, 404);
        $data = $request->validate(['foil' => ['nullable', 'boolean'], 'condition' => ['required', Rule::in(CollectionItem::CONDITIONS)]]);
        $added = $service->addMissing($request->user(), $deck, (bool) ($data['foil'] ?? false), $data['condition']);

        return redirect()->route('collection.missing', $deck)->with('status', $added ? "{$added} cartas adicionadas à coleção. O deck está completo." : 'Nada faltando: o deck já está completo.');
    }

    public function destroy(Request $request, CollectionItem $item)
    {
        abort_unless($item->user_id === $request->user()->id, 404);
        $item->delete();

        return back()->with('status', 'Item removido da coleção.');
    }

    /** Exporta a coleção como lista de texto (importável de volta aqui ou em outros sites). */
    public function export(Request $request)
    {
        $lines = CollectionItem::where('user_id', $request->user()->id)->orderBy('name')->get()
            ->map(fn ($i) => $i->quantity.' '.$i->name.($i->set_code ? ' ('.$i->set_code.')' : '').($i->foil ? ' *F*' : ''));

        return response($lines->join("\n")."\n", 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Content-Disposition' => 'attachment; filename="colecao-manaleague.txt"']);
    }

    public function missing(Request $request, Deck $deck, DeckAccessService $access, CollectionService $service)
    {
        $access->authorize($request->user(), $deck);
        $request->validate(['exact' => ['nullable', 'boolean']]);
        $exact = $request->boolean('exact');
        $rows = $service->missing($request->user(), $deck, $exact);
        $isOwner = $deck->player_profile_id === $request->user()->ensurePlayerProfile()->id;
        // Lista pronta para colar em loja/LigaMagic: "4 Lightning Bolt" (com a edição no modo impressão exata).
        $shoppingList = collect($rows)->where('missing', '>', 0)
            ->map(fn ($r) => $r['missing'].' '.$r['name'].($exact && $r['printing'] ? ' ('.$r['printing'].')' : ''))->join("\n");

        return view('decks.missing', compact('deck', 'rows', 'exact', 'isOwner', 'shoppingList'));
    }
}
