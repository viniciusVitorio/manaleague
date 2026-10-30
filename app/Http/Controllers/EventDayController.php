<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\Tournament;
use App\Services\Decks\BanListService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Dia de evento: cronômetro da rodada (organizador) e check-in dos inscritos (jogador ou organizador). */
class EventDayController extends Controller
{
    public const DEFAULT_ROUND_MINUTES = 50;

    public function startTimer(Request $request, Tournament $tournament): RedirectResponse
    {
        $this->authorize('update', $tournament);
        abort_unless(in_array($tournament->status, [Tournament::STATUS_LEAGUE, Tournament::STATUS_FINALS], true), 409, 'O cronômetro só funciona com o torneio em andamento.');
        $minutes = (int) $request->validate(['minutes' => ['required', 'integer', 'min:5', 'max:180']])['minutes'];

        $tournament->forceFill(['round_minutes' => $minutes, 'round_ends_at' => now()->addMinutes($minutes)])->save();

        return back()->with('status', "Cronômetro iniciado: {$minutes} minutos.");
    }

    public function stopTimer(Tournament $tournament): RedirectResponse
    {
        $this->authorize('update', $tournament);
        $tournament->forceFill(['round_ends_at' => null])->save();

        return back()->with('status', 'Cronômetro parado.');
    }

    /** O próprio jogador confirma (ou desfaz) a presença, até o torneio começar. */
    public function checkIn(Request $request, Tournament $tournament): RedirectResponse
    {
        abort_unless($tournament->status === Tournament::STATUS_SETUP, 409, 'O check-in fecha quando o torneio começa.');
        $entry = $tournament->players()->whereHas('profile', fn ($q) => $q->where('account_user_id', $request->user()->id))->firstOrFail();
        if ($entry->checked_in_at === null && ($banned = app(BanListService::class)->blockingCards($tournament, $entry->deck_id))) {
            return back()->withErrors(['check_in' => BanListService::messageForOwner($tournament, $banned).' Depois é só fazer o check-in.']);
        }

        return $this->toggle($entry, 'Presença confirmada. Boa sorte!', 'Check-in desfeito.');
    }

    /** O organizador marca a presença de qualquer inscrito (inclusive quem não tem conta). */
    public function togglePlayer(Tournament $tournament, Player $player): RedirectResponse
    {
        $this->authorize('update', $tournament);
        abort_unless($tournament->status === Tournament::STATUS_SETUP, 409, 'O check-in fecha quando o torneio começa.');
        abort_unless($player->tournament_id === $tournament->id, 404);
        if ($player->checked_in_at === null && app(BanListService::class)->blockingCards($tournament, $player->deck_id)) {
            return back()->withErrors(['check_in' => BanListService::messageForOrganizer($tournament, $player->name).' A presença só pode ser marcada depois que a pessoa trocar as cartas ou o deck.']);
        }

        return $this->toggle($player, $player->name.' marcado como presente.', 'Presença de '.$player->name.' desmarcada.');
    }

    /** Tira da mesa quem não fez check-in. Só com ao menos uma presença confirmada, para não esvaziar o torneio por engano. */
    public function removeAbsent(Tournament $tournament): RedirectResponse
    {
        $this->authorize('update', $tournament);
        abort_unless($tournament->status === Tournament::STATUS_SETUP, 409, 'O torneio já começou.');
        if (! $tournament->players()->whereNotNull('checked_in_at')->exists()) {
            return back()->withErrors(['check_in' => 'Ninguém fez check-in ainda. Marque as presenças antes de remover os ausentes.']);
        }

        $removed = $tournament->players()->whereNull('checked_in_at')->delete();

        return back()->with('status', $removed === 1 ? '1 ausente removido.' : "{$removed} ausentes removidos.");
    }

    private function toggle(Player $player, string $on, string $off): RedirectResponse
    {
        $checkedIn = $player->checked_in_at === null;
        $player->forceFill(['checked_in_at' => $checkedIn ? now() : null])->save();

        return back()->with('status', $checkedIn ? $on : $off);
    }
}
