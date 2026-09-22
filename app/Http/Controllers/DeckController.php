<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DeckController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $profile = $request->user()->ensurePlayerProfile();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'colors' => ['nullable', 'string', 'max:10', 'regex:/^[WUBRGC,]*$/'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($profile, $validated): void {
            if ($validated['is_primary'] ?? false) $profile->decks()->update(['is_primary' => false]);
            $deck = $profile->decks()->create($validated);
            if ($deck->is_primary) $profile->update(['preferred_deck_name' => $deck->name, 'preferred_deck_colors' => $deck->colors]);
        });

        return back()->with('status', 'Deck adicionado.');
    }

    public function destroy(Request $request, int $deck): RedirectResponse
    {
        $profile = $request->user()->ensurePlayerProfile();
        $ownedDeck = $profile->decks()->findOrFail($deck);
        $wasPrimary = $ownedDeck->is_primary;
        $ownedDeck->delete();
        if ($wasPrimary) $profile->update(['preferred_deck_name' => null, 'preferred_deck_colors' => null]);
        return back()->with('status', 'Deck removido.');
    }
}
