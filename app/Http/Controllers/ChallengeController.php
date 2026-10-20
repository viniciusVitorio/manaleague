<?php
namespace App\Http\Controllers;

use App\Models\{Challenge, PlayerProfile};
use App\Services\ChallengeService;
use Illuminate\Http\Request;

class ChallengeController extends Controller
{
    public function __construct(private ChallengeService $service) {}
    private function query(Request $request) {
        $id = $request->user()->ensurePlayerProfile()->id;
        return Challenge::query()->where(fn ($q) => $q->where('challenger_id', $id)->orWhere('opponent_id', $id))->with(['challenger', 'opponent', 'casualMatch']);
    }
    public function index(Request $request) {
        $this->service->expire();
        $challenges = $this->query($request)->latest()->paginate(20);
        $friends = $request->user()->friendProfiles()->wherePivot('status', 'accepted')->whereNotNull('account_user_id')->orderBy('nickname')->get();
        return view('challenges.index', compact('challenges', 'friends'));
    }
    public function store(Request $request) {
        $data = $request->validate(['opponent_id' => 'required|integer|exists:player_profiles,id', 'format' => 'required|string|max:60', 'best_of' => 'required|integer|in:1,3,5', 'expires_in' => 'required|integer|min:1|max:168', 'ranked' => 'sometimes|boolean']);
        $challenge = $this->service->create($request->user(), PlayerProfile::findOrFail($data['opponent_id']), $data['format'], (int) $data['best_of'], now()->addHours((int) $data['expires_in']), $request->boolean('ranked'));
        return redirect()->route('challenges.show', $challenge)->with('status', 'Convite enviado.');
    }
    public function show(Request $request, Challenge $challenge) {
        abort_unless($challenge->involves($request->user()), 403);
        $this->service->expire();
        $ranked = $challenge->is_ranked ? \App\Models\RankedMatch::where(['source_type' => 'challenge', 'source_id' => $challenge->id])->first() : null;
        return view('challenges.show', ['challenge' => $challenge->fresh(['challenger', 'opponent', 'casualMatch']), 'rankedMatch' => $ranked]);
    }
    public function transition(Request $request, Challenge $challenge) {
        $data = $request->validate(['action' => 'required|in:accept,decline,cancel']);
        $this->service->transition($challenge, $request->user(), $data['action']);
        return redirect()->route('challenges.show', $challenge)->with('status', 'Convite atualizado.');
    }
    public function report(Request $request, Challenge $challenge) {
        $data = $request->validate(['challenger_games' => 'required|integer|min:0|max:3', 'opponent_games' => 'required|integer|min:0|max:3', 'game_order' => ['nullable', 'string', 'max:20', 'regex:/^[12](,[12])*$/']]);
        $order = empty($data['game_order']) ? [] : array_map('intval', explode(',', $data['game_order']));
        $this->service->report($challenge, $request->user(), (int) $data['challenger_games'], (int) $data['opponent_games'], $order);
        return redirect()->route('challenges.show', $challenge)->with('status', 'Placar enviado para revisão do adversário.');
    }
    public function review(Request $request, Challenge $challenge) {
        $data = $request->validate(['action' => 'required|in:confirm,dispute', 'revision' => 'required|integer|min:1', 'reason' => 'nullable|string|max:1000']);
        $this->service->review($challenge, $request->user(), $data['action'], (int) $data['revision'], $data['reason'] ?? null);
        return redirect()->route('challenges.show', $challenge)->with('status', 'Revisão registrada.');
    }
    public function rivalries(Request $request) {
        $id = $request->user()->ensurePlayerProfile()->id;
        $rivals = $this->query($request)->whereHas('casualMatch', fn ($q) => $q->where('status', 'confirmed'))->get()
            ->groupBy(fn ($c) => $c->challenger_id === $id ? $c->opponent_id : $c->challenger_id)
            ->map(function ($series) use ($id) {
                $first = $series->first();
                $wins = $series->filter(fn ($c) => ($c->casualMatch->challenger_games > $c->casualMatch->opponent_games) === ($c->challenger_id === $id))->count();
                return ['profile' => $first->challenger_id === $id ? $first->opponent : $first->challenger, 'played' => $series->count(), 'wins' => $wins, 'losses' => $series->count() - $wins];
            })->sortByDesc('played');
        return view('challenges.rivalries', compact('rivals'));
    }
}
