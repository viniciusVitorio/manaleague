<?php

namespace App\Http\Controllers;

use App\Models\Deck;
use App\Services\DeckBuilderService;
use App\Services\FriendshipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DeckController extends Controller
{





    public function __construct(private readonly DeckBuilderService $builder) {}

    public function show(Request $request, Deck $deck): View
    {
        $isOwner = $this->isOwner($request, $deck);
        $isFriend = $this->isFriendOfOwner($request, $deck);
        abort_unless($isOwner || $isFriend, 403);
        abort_unless($isOwner || $deck->is_visible_to_friends, 404);


        $deck->load('profile');
        $summary = $this->builder->summary($deck);

        return view('decks.show', compact('deck', 'summary', 'isOwner'));
    }





    public function edit(Request $request, Deck $deck): View
    {
        $this->ensureOwner($request, $deck);
        return view('decks.edit', compact('deck'));
    }

    public function store(Request $request): RedirectResponse
    {
        $profile = $request->user()->ensurePlayerProfile();
        $validated = $this->validateDeck($request);



        $validated['is_visible_to_friends'] = $request->boolean('is_visible_to_friends');

        $deck = DB::transaction(function () use ($profile, $validated): Deck {
            // O primeiro deck vira principal automaticamente.
            $validated['is_primary'] = ($validated['is_primary'] ?? false) || ! $profile->decks()->exists();
            if ($validated['is_primary']) $profile->decks()->update(['is_primary' => false]);
            $deck = $profile->decks()->create($validated);
            if ($deck->is_primary) $this->syncPrimary($deck);
            return $deck;
        });
        return redirect()->route('decks.show', $deck)->with('status', 'Deck criado. Agora adicione as cartas!');
    }

    public function update(Request $request, Deck $deck): RedirectResponse
    {
        $this->ensureOwner($request, $deck);
        $validated = $this->validateDeck($request);
        DB::transaction(function () use ($deck, $validated): void {
            if ($validated['is_primary'] ?? false) $deck->profile->decks()->whereKeyNot($deck->id)->update(['is_primary' => false]);
            $deck->update($validated);
            // Com cartas cadastradas, as cores vem da identidade de cor delas.
            $this->builder->syncColors($deck);
            if ($deck->is_primary) $this->syncPrimary($deck->fresh());
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

    /** Escolhe a carta do banner. card_id vazio volta para a escolha automatica. */
    public function cover(Request $request, Deck $deck): RedirectResponse
    {
        $this->ensureOwner($request, $deck);
        $validated = $request->validate([
            'card_id' => ['nullable', 'integer', Rule::exists('deck_cards', 'id')->where('deck_id', $deck->id)->whereNotNull('art_url')],
        ]);
        $deck->forceFill(['cover_card_id' => $validated['card_id'] ?? null])->save();

        return back()->with('status', ($validated['card_id'] ?? null) ? 'Capa do deck atualizada.' : 'Capa automática restaurada.');
    }

    public function destroy(Request $request, Deck $deck): RedirectResponse
    {
        $this->ensureOwner($request, $deck);
        if ($deck->is_primary) return back()->withErrors(['deck' => 'Escolha outro deck principal antes de excluir este.']);
        $deck->delete();
        return redirect()->route('profiles.edit')->with('status', 'Deck removido.');
    }

    private function validateDeck(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'colors' => ['nullable', 'string', 'max:10', 'regex:/^[WUBRGC,]*$/'],
            'format' => ['nullable', Rule::in(Deck::FORMATS)],
            'is_primary' => ['nullable', 'boolean'],
            'is_visible_to_friends' => ['sometimes', 'boolean'],
        ]);
    }

    private function isOwner(Request $request, Deck $deck): bool
    {
        return $deck->player_profile_id === $request->user()->ensurePlayerProfile()->id;
    }

    private function isFriendOfOwner(Request $request, Deck $deck): bool
    {
        return $request->user()->friendProfiles()->whereKey($deck->player_profile_id)
            ->wherePivot('status', FriendshipService::ACCEPTED)->exists();
    }

    private function ensureOwner(Request $request, Deck $deck): void
    {
        abort_unless($this->isOwner($request, $deck), 403);
    }

    private function syncPrimary(Deck $deck): void
    {
        $deck->profile->update(['preferred_deck_name' => $deck->name, 'preferred_deck_colors' => $deck->colors]);
    }
}
