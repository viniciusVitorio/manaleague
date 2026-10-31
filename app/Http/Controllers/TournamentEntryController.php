<?php

namespace App\Http\Controllers;

use App\Models\Deck;
use App\Models\PlayerProfile;
use App\Models\Tournament;
use App\Services\Decks\BanListService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** O proprio jogador troca o deck da sua inscricao enquanto o torneio nao comecou (sempre por outro deck salvo). */
class TournamentEntryController extends Controller
{
    public function update(Request $request, Tournament $tournament): RedirectResponse
    {
        $profile = $request->user()->ensurePlayerProfile();
        $entry = $tournament->players()->where('player_profile_id', $profile->id)->firstOrFail();
        abort_unless($tournament->status === Tournament::STATUS_SETUP, 409, 'O torneio já começou; o deck não pode mais ser trocado.');

        $deck = self::validatedDeck($request, $profile, $tournament);
        if ($banned = app(BanListService::class)->blockingCards($tournament, $deck['deck_id'])) {
            return back()->withErrors(['deck_id' => BanListService::messageForOwner($tournament, $banned)])->withInput();
        }

        $result = DB::transaction(function () use ($tournament, $entry, $deck): string {
            // Lock + releitura do status: a troca nao pode passar depois que o organizador iniciou o torneio.
            $locked = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);
            if ($locked->status !== Tournament::STATUS_SETUP) return 'closed';

            $entry->update($deck);

            return 'updated';
        });

        abort_if($result === 'closed', 409, 'O torneio já começou; o deck não pode mais ser trocado.');

        return back()->with('status', 'Deck da inscrição atualizado.');
    }

    /**
     * Valida o deck da inscrição e devolve os campos do Player.
     * Só vale deck salvo no próprio perfil e do formato do torneio (no Draft o deck é opcional).
     * Nome e cores vêm do deck: não existe deck digitado à mão.
     */
    public static function validatedDeck(Request $request, PlayerProfile $profile, Tournament $tournament): array
    {
        $validated = $request->validate([
            'deck_id' => [$tournament->requiresDeck() ? 'required' : 'nullable', 'integer', Rule::exists('decks', 'id')->where('player_profile_id', $profile->id)],
        ], [
            'deck_id.required' => 'Escolha um dos seus decks para o torneio.',
            'deck_id.exists' => 'Escolha um dos seus decks para o torneio.',
        ]);

        $deck = ($validated['deck_id'] ?? null) ? Deck::query()->find($validated['deck_id']) : null;
        if ($deck && ! $tournament->acceptsDeck($deck)) {
            throw ValidationException::withMessages(['deck_id' => 'Esse deck é de '.$deck->format.'. Escolha um deck de '.$tournament->format.'.']);
        }

        return ['deck_id' => $deck?->id, 'deck_name' => $deck?->name, 'deck_colors' => $deck?->colors];
    }
}
