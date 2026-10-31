<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Services\Community\CommunityEvents;
use App\Services\Decks\BanListService;
use App\Services\PairingService;
use Illuminate\Http\RedirectResponse;
use LogicException;

class TournamentFlowController extends Controller
{
    public function start(Tournament $tournament, PairingService $pairings): RedirectResponse
    {
        $this->authorize('update', $tournament);

        // Quem tem conta joga com um deck salvo (menos no Draft).
        if ($tournament->status === Tournament::STATUS_SETUP && ($names = $tournament->playersWithoutDeck()->pluck('name'))->isNotEmpty()) {
            return back()->withErrors(['tournament' => 'Não dá para começar: '.$names->join(', ', ' e ').($names->count() === 1 ? ' ainda não escolheu' : ' ainda não escolheram').' um deck. Peça para escolherem pelo link de inscrição ou remova da lista.']);
        }

        // Regras do formato: ninguém começa com carta banida (o deck pode ter mudado depois da inscrição).
        if ($tournament->status === Tournament::STATUS_SETUP && $tournament->blocksBannedCards()) {
            $players = $tournament->players()->get(['id', 'name', 'deck_id']);
            $banned = app(BanListService::class)->bannedByDeck($players->pluck('deck_id'), $tournament->format);
            $names = $players->filter(fn ($player) => isset($banned[$player->deck_id]))->pluck('name');
            if ($names->isNotEmpty()) {
                return back()->withErrors(['tournament' => 'Não dá para começar: '.$names->join(', ', ' e ').($names->count() === 1 ? ' está' : ' estão').' com cartas banidas no '.$tournament->format.'. Peça para trocarem o deck ou remova da lista.']);
            }
        }

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
