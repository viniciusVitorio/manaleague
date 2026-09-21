<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Tournament;
use App\Services\StandingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TournamentController extends Controller
{
    public function index(Request $request): View
    {
        $tournaments = $request->user()->tournaments()->withCount('players')->latest()->get();

        return view('tournaments.index', compact('tournaments'));
    }

    public function create(): View
    {
        return view('tournaments.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'format' => ['required', Rule::in(['Pauper', 'Commander', 'Standard', 'Modern', 'Draft', 'Outro'])],
            'tournament_date' => ['required', 'date'],
        ]);

        $validated['public_token'] = Str::random(40);
        $validated['public_slug'] = Str::slug($validated['name']).'-'.Str::lower(Str::random(8));
        $validated['invite_token'] = Str::random(40);
        $tournament = $request->user()->tournaments()->create($validated);

        return redirect()->route('tournaments.show', $tournament)->with('status', 'Torneio criado. Agora chame a galera!');
    }

    public function show(Tournament $tournament, StandingsService $standings): View
    {
        $this->authorize('view', $tournament);
        $tournament->load(['players', 'matches' => fn ($query) => $query->orderBy('round')->orderBy('id'), 'matches.playerOne', 'matches.playerTwo']);
        $ranking = $standings->for($tournament);
        $rounds = $tournament->matches->groupBy('round');
        $final = $tournament->matches->firstWhere('stage', GameMatch::STAGE_FINAL);
        $thirdPlace = $tournament->matches->firstWhere('stage', GameMatch::STAGE_THIRD_PLACE);

        return view('tournaments.show', compact('tournament', 'ranking', 'rounds', 'final', 'thirdPlace'));
    }

    public function destroy(Tournament $tournament): RedirectResponse
    {
        $this->authorize('delete', $tournament);
        DB::transaction(fn () => $tournament->delete());

        return redirect()->route('tournaments.index')->with('status', 'Torneio excluido.');
    }
}
