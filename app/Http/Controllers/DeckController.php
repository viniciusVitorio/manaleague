<?php

namespace App\Http\Controllers;

use App\Models\Deck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DeckController extends Controller
{
    public function edit(Request $request, Deck $deck): View
    {
        $this->ensureOwner($request, $deck);
        return view("decks.edit", compact("deck"));
    }

    public function store(Request $request): RedirectResponse
    {
        $profile = $request->user()->ensurePlayerProfile();
        $validated = $this->validateDeck($request);
        DB::transaction(function () use ($profile, $validated): void {
            if ($validated['is_primary'] ?? false) $profile->decks()->update(['is_primary' => false]);
            $deck = $profile->decks()->create($validated);
            if ($deck->is_primary) $this->syncPrimary($deck);
        });
        return back()->with('status', 'Deck adicionado.');
    }

    public function update(Request $request, Deck $deck): RedirectResponse
    {
        $this->ensureOwner($request, $deck);
        $validated = $this->validateDeck($request);
        DB::transaction(function () use ($deck, $validated): void {
            if ($validated['is_primary'] ?? false) $deck->profile->decks()->whereKeyNot($deck->id)->update(['is_primary' => false]);
            $deck->update($validated);
            if ($deck->is_primary) $this->syncPrimary($deck);
        });
        return back()->with('status', 'Deck atualizado.');
    }

    public function primary(Request $request, Deck $deck): RedirectResponse
    {
        $this->ensureOwner($request, $deck);
        DB::transaction(function () use ($deck): void {
            $deck->profile->decks()->update(['is_primary' => false]);
            $deck->update(['is_primary' => true]);
            $this->syncPrimary($deck);
        });
        return back()->with('status', 'Deck principal atualizado.');
    }

    public function destroy(Request $request, Deck $deck): RedirectResponse
    {
        $this->ensureOwner($request, $deck);
        if ($deck->is_primary) return back()->withErrors(['deck' => 'Escolha outro deck principal antes de excluir este.']);
        $deck->delete();
        return back()->with('status', 'Deck removido.');
    }

    private function validateDeck(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'colors' => ['nullable', 'string', 'max:10', 'regex:/^[WUBRGC,]*$/'],
            'is_primary' => ['nullable', 'boolean'],
        ]);
    }

    private function ensureOwner(Request $request, Deck $deck): void
    {
        abort_unless($deck->player_profile_id === $request->user()->ensurePlayerProfile()->id, 403);
    }

    private function syncPrimary(Deck $deck): void
    {
        $deck->profile->update(['preferred_deck_name' => $deck->name, 'preferred_deck_colors' => $deck->colors]);
    }
}
