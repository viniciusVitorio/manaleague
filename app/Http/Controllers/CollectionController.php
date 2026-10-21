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
    public function index(Request $request, CollectionService $service)
    {
        $user = $request->user();
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:80'], 'show' => ['nullable', Rule::in(['all', 'trade', 'foil'])], 'sort' => ['nullable', Rule::in(['name', 'value', 'recent'])]]);
        $query = CollectionItem::where('user_id', $user->id);
        if ($q = trim($filters['q'] ?? '')) {
            $query->where(fn ($w) => $w->where('name', 'like', '%'.str_replace(['%', '_'], '', $q).'%')->orWhere('set_code', strtoupper($q)));
        }
        match ($filters['show'] ?? 'all') {
            'trade' => $query->where('for_trade', '>', 0), 'foil' => $query->where('foil', true), default => null
        };
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
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:9999'], 'for_trade' => ['nullable', 'integer', 'min:0']]);
        $data['for_trade'] = min((int) ($data['for_trade'] ?? $item->for_trade), (int) $data['quantity']);
        $item->update($data);

        return back()->with('status', $item->for_trade ? "Salvo. {$item->for_trade} para troca." : 'Quantidade atualizada.');
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

        return view('decks.missing', compact('deck', 'rows', 'exact'));
    }
}
