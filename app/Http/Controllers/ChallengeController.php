<?php

namespace App\Http\Controllers;

use App\Models\Challenge;
use App\Models\PlayerProfile;
use App\Models\RankedMatch;
use App\Services\ChallengeService;
use Illuminate\Http\Request;

class ChallengeController extends Controller
{
    public function __construct(private ChallengeService $service) {}

    private function query(Request $request)
    {
        return app(\App\Services\ChallengeRowsService::class)->query($request->user());
    }

    /**
     * Desafios: o que está em andamento (o que precisa de você primeiro, com as ações no próprio cartão) e as rivalidades.
     * O histórico das séries fica em Minhas partidas; criar desafio fica numa tela própria.
     */
    public function index(Request $request)
    {
        // Links antigos (?opponent= / ?ranked=) abriam o formulário aqui: agora ele mora em /challenges/new.
        if ($request->hasAny(['opponent', 'ranked'])) {
            return redirect()->route('challenges.create', $request->only(['opponent', 'ranked']));
        }
        $this->service->expire();
        $this->service->autoConfirmOverdue();
        // Mais recente primeiro; criados no mesmo segundo desempatam pelo id (no PostgreSQL o empate vinha em ordem qualquer).
        // Mais recente primeiro; criados no mesmo segundo desempatam pelo id (no PostgreSQL o empate vinha em ordem qualquer).
        $open = $this->query($request)->whereIn('status', ['pending', 'accepted'])->latest()->latest('id')->get();
        $rows = $this->rows($request, $open);
        $open = $open->map(fn (Challenge $c) => $rows[$c->id])->sortByDesc('needs_me')->values();
        $rivals = $this->rivals($request);
        $hasFriends = $this->friends($request)->isNotEmpty();

        return view('challenges.index', compact('open', 'rivals', 'hasFriends'));
    }

    public function create(Request $request)
    {
        $friends = $this->friends($request);

        return view('challenges.create', compact('friends'));
    }

    private function friends(Request $request)
    {
        return $request->user()->friendProfiles()->wherePivot('status', 'accepted')->whereNotNull('account_user_id')->orderBy('nickname')->get();
    }

    private function rows(Request $request, $challenges): array
    {
        return app(\App\Services\ChallengeRowsService::class)->rows($request->user(), $challenges);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['opponent_id' => 'required|integer|exists:player_profiles,id', 'format' => 'required|string|max:60', 'best_of' => 'required|integer|in:1,3,5', 'expires_in' => 'required|integer|min:1|max:168', 'ranked' => 'sometimes|boolean']);
        $challenge = $this->service->create($request->user(), PlayerProfile::findOrFail($data['opponent_id']), $data['format'], (int) $data['best_of'], now()->addHours((int) $data['expires_in']), $request->boolean('ranked'));

        return redirect()->to($challenge->url())->with('status', 'Convite enviado.');
    }

    /**
     * Não há mais página do desafio: links antigos (notificações, favoritos) vão para onde ele mora hoje, o cartão em
     * Desafios (em andamento) ou o resumo da partida em Minhas partidas (encerrado).
     */
    public function show(Request $request, Challenge $challenge)
    {
        abort_unless($challenge->involves($request->user()), 403);
        $this->service->expire();
        $this->service->autoConfirmOverdue();

        return redirect()->to($challenge->fresh()->url());
    }

    public function transition(Request $request, Challenge $challenge)
    {
        $data = $request->validate(['action' => 'required|in:accept,decline,cancel']);
        $this->service->transition($challenge, $request->user(), $data['action']);

        return $this->back($challenge, 'Convite atualizado.');
    }

    public function report(Request $request, Challenge $challenge)
    {
        $data = $request->validate(['challenger_games' => 'required|integer|min:0|max:3', 'opponent_games' => 'required|integer|min:0|max:3', 'game_order' => ['nullable', 'string', 'max:20', 'regex:/^[12](,[12])*$/']]);
        $order = empty($data['game_order']) ? [] : array_map('intval', explode(',', $data['game_order']));
        $this->service->report($challenge, $request->user(), (int) $data['challenger_games'], (int) $data['opponent_games'], $order);

        return $this->back($challenge, 'Placar enviado para revisão do adversário.');
    }

    public function review(Request $request, Challenge $challenge)
    {
        $data = $request->validate(['action' => 'required|in:confirm,dispute', 'revision' => 'required|integer|min:1', 'reason' => 'nullable|string|max:1000']);
        $this->service->review($challenge, $request->user(), $data['action'], (int) $data['revision'], $data['reason'] ?? null);

        return $this->back($challenge, 'Revisão registrada.');
    }

    /** As ações ficam nos cartões (Desafios ou Minhas partidas): volta para a tela de onde veio. */
    private function back(Challenge $challenge, string $status)
    {
        return redirect()->back(fallback: $challenge->fresh()->url())->with('status', $status);
    }

    /** As rivalidades agora são uma seção da própria tela de Desafios. */
    public function rivalries()
    {
        return redirect()->to(route('challenges.index').'#rivalidades');
    }

    /**
     * Retrospecto por amigo: cada desafio confirmado e uma serie (casual ou ranqueada).
     * Vitoria = mais games na serie; empate conta separado.
     */
    private function rivals(Request $request)
    {
        $id = $request->user()->ensurePlayerProfile()->id;
        $series = collect();
        foreach ($this->query($request)->where('is_ranked', false)->whereHas('casualMatch', fn ($q) => $q->where('status', 'confirmed'))->get() as $c) {
            $mine = $c->challenger_id === $id;
            $series->push(['rival' => $mine ? $c->opponent : $c->challenger, 'me' => $mine ? $c->casualMatch->challenger_games : $c->casualMatch->opponent_games,
                'them' => $mine ? $c->casualMatch->opponent_games : $c->casualMatch->challenger_games, 'ranked' => false]);
        }
        $ranked = RankedMatch::query()->with(['playerOne', 'playerTwo'])->where('source_type', 'challenge')->where('status', 'confirmed')
            ->where(fn ($q) => $q->where('player_one_profile_id', $id)->orWhere('player_two_profile_id', $id))->get();
        foreach ($ranked as $m) {
            $one = $m->player_one_profile_id === $id;
            $series->push(['rival' => $one ? $m->playerTwo : $m->playerOne, 'me' => $one ? $m->score_one : $m->score_two, 'them' => $one ? $m->score_two : $m->score_one, 'ranked' => true]);
        }
        return $series->filter(fn ($s) => $s['rival'])->groupBy(fn ($s) => $s['rival']->id)->map(fn ($g) => [
            'profile' => $g->first()['rival'], 'played' => $g->count(),
            'wins' => $g->filter(fn ($s) => $s['me'] > $s['them'])->count(),
            'losses' => $g->filter(fn ($s) => $s['me'] < $s['them'])->count(),
            'draws' => $g->filter(fn ($s) => $s['me'] === $s['them'])->count(),
            'ranked' => $g->where('ranked', true)->count(),
        ])->sortByDesc('played')->values();
    }
}
