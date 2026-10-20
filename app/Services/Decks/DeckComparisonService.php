<?php
namespace App\Services\Decks;
use App\Models\Deck;
class DeckComparisonService {
    public function compare(Deck $left, Deck $right): array {
        $a = $left->cards->groupBy(fn ($c) => $c->board.'|'.$c->name);
        $b = $right->cards->groupBy(fn ($c) => $c->board.'|'.$c->name);
        return $a->keys()->merge($b->keys())->unique()->sort()->map(function ($key) use ($a, $b) {
            $l = $a->get($key, collect()); $r = $b->get($key, collect()); $card = $l->first() ?? $r->first();
            return ['name' => $card->name, 'board' => $card->board, 'left' => $l->sum('quantity'), 'right' => $r->sum('quantity'),
                'delta' => $r->sum('quantity') - $l->sum('quantity'), 'printing_changed' => $l->pluck('scryfall_id')->sort()->values()->all() !== $r->pluck('scryfall_id')->sort()->values()->all()];
        })->values()->all();
    }
}
