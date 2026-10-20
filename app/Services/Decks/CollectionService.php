<?php
namespace App\Services\Decks;
use App\Models\{CollectionItem, Deck, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class CollectionService {
    public function add(User $user, array $card, array $data): CollectionItem {
        return DB::transaction(function () use ($user, $card, $data) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $identity = ['user_id' => $user->id, 'scryfall_id' => strtolower($card['scryfall_id']), 'foil' => (bool) ($data['foil'] ?? false), 'condition' => $data['condition']];
            $item = CollectionItem::firstOrNew($identity);
            $quantity = ($item->quantity ?? 0) + $data['quantity'];
            if ($quantity > 9999) throw ValidationException::withMessages(['quantity' => 'Limite de 9.999 cópias por impressão, acabamento e condição.']);
            $item->fill(['name' => $card['name'], 'set_code' => $card['set_code'] ?? null, 'image_url' => $card['image_url'] ?? null, 'quantity' => $quantity])->save();
            return $item;
        });
    }
    public function missing(User $user, Deck $deck, bool $exact): array {
        $key = fn ($card) => $exact ? strtolower($card->scryfall_id) : mb_strtolower($card->name);
        $owned = CollectionItem::where('user_id', $user->id)->get()->groupBy($key)->map(fn ($cards) => (int) $cards->sum('quantity'));
        return $deck->cards()->get()->groupBy($key)->map(function ($cards, $key) use ($owned) {
            $required = (int) $cards->sum('quantity'); $available = (int) $owned->get($key, 0);
            return ['name' => $cards->first()->name, 'printing' => $cards->first()->set_code, 'required' => $required, 'owned' => $available, 'missing' => max(0, $required - $available)];
        })->sortBy('name')->values()->all();
    }
}
