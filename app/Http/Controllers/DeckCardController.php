<?php

namespace App\Http\Controllers;

use App\Models\Deck;
use App\Models\DeckCard;
use App\Services\DeckBuilderService;
use App\Services\ScryfallClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeckCardController extends Controller
{
    public function __construct(
        private readonly DeckBuilderService $builder,
        private readonly ScryfallClient $scryfall,
    ) {}

    /** Autocomplete usado pelo construtor de deck (JSON, mesma origem). */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return response()->json(['data' => $this->scryfall->search($validated['q'])]);
    }

    public function store(Request $request, Deck $deck): RedirectResponse
    {
        $this->ensureOwner($request, $deck);
        $validated = $request->validate([
            'scryfall_id' => ['required', 'uuid'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:'.DeckBuilderService::MAX_COPIES],
            'board' => ['nullable', Rule::in(DeckCard::BOARDS)],
            'foil' => ['nullable', 'boolean'],
        ]);

        $card = $this->scryfall->card($validated['scryfall_id']);
        if (! $card) {
            return back()->withErrors(['card' => 'Não foi possível consultar esta carta no Scryfall. Tente novamente em instantes.']);
        }

        $board = $validated['board'] ?? DeckCard::BOARD_MAIN;
        if ($board === DeckCard::BOARD_COMMANDER && ! $this->canCommand($card)) {
            return back()->withErrors(['card' => "{$card['name']} não pode ser comandante (precisa ser criatura lendária ou ter o texto de comandante)."]);
        }

        $foil = (bool) ($validated['foil'] ?? false);
        $this->builder->add($deck, $card, (int) ($validated['quantity'] ?? 1), $board, $foil);

        return back()->with('status', "{$card['name']}".($foil ? ' (foil)' : '').' adicionada.');
    }

    public function update(Request $request, Deck $deck, DeckCard $card): RedirectResponse|JsonResponse
    {
        $this->ensureOwner($request, $deck);
        abort_unless($card->deck_id === $deck->id, 404);

        $validated = $request->validate([
            'quantity' => ['nullable', 'integer', 'min:0', 'max:'.DeckBuilderService::MAX_COPIES],
            'delta' => ['nullable', 'integer', 'in:-1,1'],
            'board' => ['nullable', Rule::in([DeckCard::BOARD_MAIN, DeckCard::BOARD_SIDE])],
            'move' => ['nullable', 'integer', 'min:1', 'max:'.DeckBuilderService::MAX_COPIES],
        ]);

        // Mover N cópias entre deck e sideboard (botões e arrastar). Sem "move", segue o fluxo antigo abaixo.
        if (isset($validated['move'], $validated['board'])) {
            $moved = $this->builder->move($deck, $card, $validated['board'], $validated['move']);
            $where = $validated['board'] === DeckCard::BOARD_SIDE ? 'o sideboard' : 'o deck';
            $message = $moved === 0 ? null : "{$moved}× {$card->name} movida para {$where}.";

            return $request->expectsJson() ? response()->json(['moved' => $moved]) : back()->with('status', $message);
        }

        $quantity = $validated['quantity'] ?? ($card->quantity + (int) ($validated['delta'] ?? 0));
        if ($quantity <= 0) {
            $card->delete();
            $this->builder->syncColors($deck);
            return back()->with('status', "{$card->name} removida.");
        }

        if (isset($validated['board']) && $validated['board'] !== $card->board) {
            $existing = $deck->cards()->where('scryfall_id', $card->scryfall_id)->where('board', $validated['board'])->where('foil', $card->foil)->first();
            if ($existing) {
                $existing->update(['quantity' => min(DeckBuilderService::MAX_COPIES, $existing->quantity + $quantity)]);
                $card->delete();
                return back()->with('status', "{$card->name} movida.");
            }
            $card->board = $validated['board'];
        }

        $card->quantity = min(DeckBuilderService::MAX_COPIES, $quantity);
        $card->save();

        return back();
    }

    public function destroy(Request $request, Deck $deck, DeckCard $card): RedirectResponse
    {
        $this->ensureOwner($request, $deck);
        abort_unless($card->deck_id === $deck->id, 404);
        $card->delete();
        $this->builder->syncColors($deck);

        return back()->with('status', "{$card->name} removida.");
    }

    public function import(Request $request, Deck $deck): RedirectResponse
    {
        $this->ensureOwner($request, $deck);
        $validated = $request->validate([
            'list' => ['required', 'string', 'max:20000'],
            'replace' => ['nullable', 'boolean'],
        ]);

        $result = $this->builder->import($deck, $validated['list'], (bool) ($validated['replace'] ?? false));

        $redirect = back()->with('status', "{$result['added']} cartas importadas.");
        if ($result['missing'] !== []) {
            $redirect->withErrors(['import' => 'Não encontradas no Scryfall: '.implode(', ', array_slice($result['missing'], 0, 15)).(count($result['missing']) > 15 ? '…' : '')]);
        }

        return $redirect;
    }

    private function canCommand(array $card): bool
    {
        $type = (string) $card['type_line'];

        return (str_contains($type, 'Legendary') && (str_contains($type, 'Creature') || str_contains($type, 'Planeswalker')))
            || str_contains($type, 'Background');
    }

    private function ensureOwner(Request $request, Deck $deck): void
    {
        abort_unless($deck->player_profile_id === $request->user()->ensurePlayerProfile()->id, 403);
    }
}
