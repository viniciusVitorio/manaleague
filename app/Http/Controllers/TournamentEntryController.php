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

/** O proprio jogador troca o deck da sua inscricao enquanto o torneio nao comecou. */
class TournamentEntryController extends Controller
{
    public function update(Request $request, Tournament $tournament): RedirectResponse
    {
        $profile = $request->user()->ensurePlayerProfile();
        $entry = $tournament->players()->where('player_profile_id', $profile->id)->firstOrFail();
        abort_unless($tournament->status === Tournament::STATUS_SETUP, 409, 'O torneio já começou; o deck não pode mais ser trocado.');

        $deck = self::validatedDeck($request, $profile);
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
     * Valida deck_id/deck_name/deck_colors e devolve os campos do Player.
     * deck_id so e aceito se o deck pertence ao proprio perfil; o nome digitado tem prioridade
     * (o jogador pode apelidar o deck), as cores vem do deck salvo quando nao informadas.
     */
    public static function validatedDeck(Request $request, PlayerProfile $profile): array
    {
        $validated = $request->validate([
            'deck_id' => ['nullable', 'integer', Rule::exists('decks', 'id')->where('player_profile_id', $profile->id)],
            'deck_name' => ['nullable', 'string', 'max:100'],
            'deck_colors' => ['nullable', 'string', 'max:10', 'regex:/^[WUBRGC,]*$/'],
        ]);

        $deck = ($validated['deck_id'] ?? null) ? Deck::query()->find($validated['deck_id']) : null;

        return [
            'deck_id' => $deck?->id,
            'deck_name' => ($validated['deck_name'] ?? null) ?: $deck?->name,
            'deck_colors' => ($validated['deck_colors'] ?? null) ?: $deck?->colors,
        ];
    }
}
