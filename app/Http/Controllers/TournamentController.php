<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Tournament;
use App\Services\StandingsService;
use App\Services\TournamentRecapService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TournamentController extends Controller
{
    private const FORMATS = ['Pauper', 'Commander', 'Standard', 'Modern', 'Draft', 'Outro'];

    public function index(Request $request): View
    {
        $tournaments = $request->user()->tournaments()->withCount('players')->latest()->get();
        return view('tournaments.index', compact('tournaments'));
    }

    public function create(): View { return view('tournaments.create'); }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateTournament($request);
        $validated['public_token'] = Str::random(40);
        $validated['public_slug'] = Str::slug($validated['name']).'-'.Str::lower(Str::random(8));
        $validated['invite_token'] = Str::random(40);
        $tournament = $request->user()->tournaments()->create($validated);
        return redirect()->route('tournaments.show', $tournament)->with('status', 'Torneio criado. Agora chame a galera!');
    }

    public function show(Tournament $tournament, StandingsService $standings, TournamentRecapService $recaps): View
    {
        $this->authorize('view', $tournament);
        $tournament->load(['players.profile', 'matches' => fn ($query) => $query->orderBy('round')->orderBy('id'), 'matches.playerOne.profile', 'matches.playerTwo.profile']);
        $ranking = $standings->for($tournament);
        $rounds = $tournament->matches->groupBy('round');
        $final = $tournament->matches->firstWhere('stage', GameMatch::STAGE_FINAL);
        $thirdPlace = $tournament->matches->firstWhere('stage', GameMatch::STAGE_THIRD_PLACE);
        $enrolledProfileIds = $tournament->players->pluck('player_profile_id')->filter();
        $friends = $tournament->user->friendProfiles()->whereNotIn('player_profiles.id', $enrolledProfileIds)->orderBy('nickname')->get();
        $recap = $recaps->for($tournament);
        return view('tournaments.show', compact('tournament', 'ranking', 'rounds', 'final', 'thirdPlace', 'friends', 'recap'));
    }

    public function edit(Tournament $tournament): View
    {
        $this->authorize('update', $tournament);
        abort_unless($tournament->status === Tournament::STATUS_SETUP, 409, 'Só é possível editar antes do início.');
        return view('tournaments.edit', compact('tournament'));
    }

    public function update(Request $request, Tournament $tournament): RedirectResponse
    {
        $this->authorize('update', $tournament);
        abort_unless($tournament->status === Tournament::STATUS_SETUP, 409);
        $validated = $this->validateTournament($request);
        if ($validated['max_players'] && $validated['max_players'] < $tournament->players()->count()) {
            return back()->withErrors(['max_players' => 'O limite não pode ser menor que o total de inscritos.'])->withInput();
        }
        $tournament->update($validated);
        return redirect()->route('tournaments.show', $tournament)->with('status', 'Torneio atualizado.');
    }

    public function regenerateInvite(Tournament $tournament): RedirectResponse
    {
        $this->authorize('update', $tournament);
        abort_unless($tournament->status === Tournament::STATUS_SETUP, 409);
        $tournament->update(['invite_token' => Str::random(40)]);
        return back()->with('status', 'Novo link criado. O convite anterior não funciona mais.');
    }

    public function destroy(Tournament $tournament): RedirectResponse
    {
        $this->authorize('delete', $tournament);
        DB::transaction(fn () => $tournament->delete());
        return redirect()->route('tournaments.index')->with('status', 'Torneio excluido.');
    }

    private function validateTournament(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'format' => ['required', Rule::in(self::FORMATS)],
            'tournament_date' => ['required', 'date'],
            'max_players' => ['nullable', 'integer', 'min:4', 'max:128'],
        ]);
    }
}
