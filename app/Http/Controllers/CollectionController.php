<?php
namespace App\Http\Controllers;
use App\Models\{CollectionItem, Deck};
use App\Services\Decks\{CollectionService, DeckAccessService};
use App\Services\ScryfallClient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class CollectionController extends Controller {
    public function index(Request $request) {
        $items = CollectionItem::where('user_id', $request->user()->id)->orderBy('name')->orderBy('id')->paginate(40);
        $decks = $request->user()->ensurePlayerProfile()->decks()->orderBy('name')->get();
        return view('decks.collection', compact('items', 'decks'));
    }
    public function store(Request $request, ScryfallClient $scryfall, CollectionService $service) {
        $data = $request->validate(['scryfall_id' => ['required', 'uuid'], 'quantity' => ['required', 'integer', 'min:1', 'max:9999'], 'foil' => ['nullable', 'boolean'], 'condition' => ['required', Rule::in(CollectionItem::CONDITIONS)]]);
        $card = $scryfall->card($data['scryfall_id']);
        if (!$card) return back()->withInput()->withErrors(['scryfall_id' => 'Impressão não encontrada ou Scryfall indisponível. Nenhuma carta adicionada.']);
        $service->add($request->user(), $card, $data);
        return back()->with('status', 'Carta adicionada à coleção.');
    }
    public function update(Request $request, CollectionItem $item) {
        abort_unless($item->user_id === $request->user()->id, 404);
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:9999']]);
        $item->update($data);
        return back()->with('status', 'Quantidade atualizada.');
    }
    public function destroy(Request $request, CollectionItem $item) {
        abort_unless($item->user_id === $request->user()->id, 404);
        $item->delete();
        return back()->with('status', 'Item removido da coleção.');
    }
    public function missing(Request $request, Deck $deck, DeckAccessService $access, CollectionService $service) {
        $access->authorize($request->user(), $deck);
        $request->validate(['exact' => ['nullable', 'boolean']]);
        $exact = $request->boolean('exact');
        $rows = $service->missing($request->user(), $deck, $exact);
        return view('decks.missing', compact('deck', 'rows', 'exact'));
    }
}
