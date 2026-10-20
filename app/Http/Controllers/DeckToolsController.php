<?php
namespace App\Http\Controllers;
use App\Models\{Deck, DeckVersion};
use App\Services\Decks\{DeckAccessService, DeckVersionService, DeckComparisonService, DeckUsageService};
use Illuminate\Http\Request;
class DeckToolsController extends Controller {
    public function history(Request $request) {
        $versions = DeckVersion::where('player_profile_id', $request->user()->ensurePlayerProfile()->id)->latest('id')->paginate(20);
        return view('decks.history', compact('versions'));
    }
    public function version(Request $request, DeckVersion $version, DeckUsageService $usage) {
        abort_unless($version->player_profile_id === $request->user()->ensurePlayerProfile()->id, 404);
        $version->load('uses'); $results = $usage->results($version);
        return view('decks.version', compact('version','results'));
    }
    public function snapshot(Request $request, Deck $deck, DeckVersionService $service) {
        abort_unless($deck->player_profile_id === $request->user()->ensurePlayerProfile()->id, 404);
        $data = $request->validate(['label' => 'nullable|string|max:100']);
        $version = $service->snapshot($deck, $data['label'] ?? 'Versão salva');
        return redirect()->route('decks.versions.show', $version)->with('status', 'Versão preservada. Estados idênticos reutilizam a última versão.');
    }
    public function compare(Request $request, DeckAccessService $access, DeckComparisonService $comparison) {
        $data = $request->validate(['left' => 'nullable|integer|min:1', 'right' => 'nullable|integer|min:1']);
        $decks = $access->visible($request->user())->orderBy('name')->get();
        $left = isset($data['left']) ? $access->visible($request->user())->findOrFail($data['left']) : null;
        $right = isset($data['right']) ? $access->visible($request->user())->findOrFail($data['right']) : null;
        $rows = $left && $right ? $comparison->compare($left, $right) : null;
        return view('decks.compare', compact('decks','left','right','rows'));
    }
    public function simulator(Request $request, Deck $deck, DeckAccessService $access) {
        $access->authorize($request->user(), $deck);
        $cards = $deck->cards()->where('board','main')->get()->map(fn ($c) => ['name' => $c->name, 'quantity' => $c->quantity, 'land' => $c->isLand(), 'image' => $c->image_url]);
        return view('decks.simulator', compact('deck','cards'));
    }
}
