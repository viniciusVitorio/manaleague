<?php

namespace App\Http\Controllers;

use App\Models\Deck;
use App\Services\DeckBuilderService;
use App\Services\FriendshipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DeckController extends Controller
{





    public function __construct(private readonly DeckBuilderService $builder) {}

    /** Página "Decks": todos os decks do jogador, com estatísticas, filtro por formato e criação de deck. */
    public function index(Request $request): View
    {
        $user = $request->user();
        $profile = $user->ensurePlayerProfile();
        $filters = $request->validate(['format' => ['nullable', 'string', 'max:20']]);

        $all = $profile->decks()->with('cards')->orderByDesc('is_primary')->orderBy('name')->get();
        $records = app(\App\Services\PlayerStatsService::class)->for($profile)['deck_records'];
        $hasCollection = \App\Models\CollectionItem::where('user_id', $user->id)->exists();
        $coverage = $hasCollection ? app(\App\Services\Decks\CollectionService::class)->deckCoverage($user)->keyBy(fn ($c) => $c['deck']->id) : collect();

        $cards = fn (Deck $deck) => (int) $deck->cards->sum('quantity');
        $formats = $all->groupBy(fn (Deck $deck) => $deck->format ?: 'Livre')->map->count()->sortDesc();
        $played = collect($records)->only($all->pluck('id')->all());

        $stats = [
            'decks' => $all->count(),
            'visible' => $all->where('is_visible_to_friends', true)->count(),
            'top_format' => $formats->keys()->first(),
            'top_format_count' => (int) $formats->first(),
            'wins' => (int) $played->sum('wins'),
            'losses' => (int) $played->sum('losses'),
            'tournaments' => (int) $played->sum('tournaments'),
            'complete' => $coverage->where('percent', 100)->count(),
        ];

        $format = $filters['format'] ?? null;
        $decks = $all->when($format, fn ($decks) => $decks->filter(fn (Deck $deck) => ($deck->format ?: 'Livre') === $format))
            ->map(fn (Deck $deck) => [
                'deck' => $deck, 'cards' => $cards($deck), 'cover' => $this->builder->coverOf($deck), 'single_set' => $deck->singleSet(),
                'record' => $records[$deck->id] ?? null, 'coverage' => $coverage->get($deck->id),
            ]);

        return view('decks.index', compact('decks', 'stats', 'formats', 'format', 'hasCollection'));
    }

    public function show(Request $request, Deck $deck): View
    {
        $isOwner = $this->isOwner($request, $deck);
        $isFriend = $this->isFriendOfOwner($request, $deck);
        abort_unless($isOwner || $isFriend, 403);
        abort_unless($isOwner || $deck->is_visible_to_friends, 404);


        $deck->load('profile');
        $summary = $this->builder->summary($deck);

        // Dono: quantas cópias de cada carta ele tem na coleção, contra o total pedido no deck (main + side + comandante).
        // Só para quem usa a coleção (tem algum item) e não desligou a opção.
        $owned = collect();
        $needed = collect();
        $hasCollection = $isOwner && \App\Models\CollectionItem::where('user_id', $request->user()->id)->exists();
        $showOwnership = $hasCollection && $request->user()->show_deck_ownership !== false;
        if ($showOwnership) {
            $owned = app(\App\Services\Decks\CollectionService::class)->ownedByName($request->user());
            $needed = $deck->cards()->get()->groupBy(fn ($c) => mb_strtolower($c->name))->map(fn ($c) => (int) $c->sum('quantity'));
        }

        $exportText = $this->builder->exportText($deck);
        // Cartas banidas no formato do deck (tag "Banido" na lista).
        $bannedNames = app(\App\Services\Decks\BanListService::class)->bannedInDeck($deck->id, $deck->format);

        return view('decks.show', compact('deck', 'summary', 'isOwner', 'owned', 'needed', 'hasCollection', 'showOwnership', 'exportText', 'bannedNames'));
    }

    /** Baixa a lista em .txt (formato Arena/Moxfield). Mesma regra de acesso da página do deck. */
    public function export(Request $request, Deck $deck): Response
    {
        $this->authorize('view', $deck);
        $filename = (Str::slug($deck->name) ?: 'deck').'.txt';

        return response($this->builder->exportText($deck), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
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
            // Deck que ficou privado sai da vitrine do perfil (lá só entram decks que os amigos podem ver).
            if (! $deck->is_visible_to_friends && $deck->showcase_position) $deck->forceFill(['showcase_position' => null])->save();
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
        return redirect()->route('decks.index')->with('status', 'Deck removido.');
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
