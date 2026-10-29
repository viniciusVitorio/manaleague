<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Services\Community\CommunityEvents;
use App\Services\PairingService;
use Illuminate\Http\RedirectResponse;
use LogicException;

class TournamentFlowController extends Controller
{
    public function start(Tournament $tournament, PairingService $pairings): RedirectResponse
    {
        $this->authorize('update', $tournament);

        try {
            $pairings->generateLeague($tournament);
            app(CommunityEvents::class)->tournamentProgress($tournament);
        } catch (LogicException $exception) {
            return back()->withErrors(['tournament' => $exception->getMessage()]);
        }

        return back()->with('status', 'Rodadas geradas. Que comecem os jogos!');
    }

    public function advance(\Illuminate\Http\Request $request, Tournament $tournament, \App\Services\CompetitionService $competition): RedirectResponse
    {
        $this->authorize('update', $tournament);
        $data=$request->validate(['round'=>['required','integer','min:1']]);
        try { $competition->advance($tournament,(int)$data['round']); $this->clearTimer($tournament); app(CommunityEvents::class)->tournamentProgress($tournament); }
        catch (LogicException $exception) { return back()->withErrors(['tournament'=>$exception->getMessage()]); }
        return back()->with('status','Rodada concluída. Confira os próximos confrontos ou a classificação final.');
    }

    public function finals(Tournament $tournament, PairingService $pairings): RedirectResponse
    {
        $this->authorize('update', $tournament);

        try {
            $pairings->generateFinals($tournament);
            $this->clearTimer($tournament);
            app(CommunityEvents::class)->tournamentProgress($tournament);
        } catch (LogicException $exception) {
            return back()->withErrors(['tournament' => $exception->getMessage()]);
        }

        return back()->with('status', 'Final e disputa de terceiro lugar definidas!');
    }

    /** Rodada nova começa sem cronômetro: o organizador inicia quando as mesas sentarem. */
    private function clearTimer(Tournament $tournament): void
    {
        $tournament->forceFill(['round_ends_at' => null])->save();
    }
}
