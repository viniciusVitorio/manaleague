<?php
namespace App\Services\Decks;
use App\Models\{Deck, DeckVersion, DeckUse, Tournament};
use App\Services\DeckBuilderService;
use Illuminate\Support\Facades\DB;
class DeckVersionService {
    public function snapshot(Deck $deck, string $label = 'Alteração do deck'): DeckVersion {
        return DB::transaction(function () use ($deck, $label) {
            $deck = Deck::query()->lockForUpdate()->findOrFail($deck->id);
            $cards = $deck->cards()->reorder()->orderBy('board')->orderBy('scryfall_id')->get();
            $payload = ['name' => $deck->name, 'format' => $deck->format, 'colors' => $deck->colors,
                'cover_art' => app(DeckBuilderService::class)->summary($deck)['cover'],
                'cover_scryfall_id' => $cards->firstWhere('id', $deck->cover_card_id)?->scryfall_id,
                'cards' => $cards->map(fn ($c) => $c->only(['scryfall_id','name','board','quantity','mana_cost','cmc','type_line','color_identity','set_code','image_url','art_url']))->all()];
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $last = DeckVersion::where('source_deck_id', $deck->id)->orderByDesc('number')->first();
            if ($last && $last->fingerprint === $hash) return $last;
            return DeckVersion::create(['source_deck_id' => $deck->id, 'player_profile_id' => $deck->player_profile_id,
                'number' => ($last?->number ?? 0) + 1, 'label' => $label, 'fingerprint' => $hash, 'payload' => $payload, 'created_at' => now()]);
        });
    }
    public function snapshotForTournament(Tournament $tournament): void {
        DB::transaction(function () use ($tournament) {
            Tournament::whereKey($tournament->id)->lockForUpdate()->firstOrFail();
            foreach ($tournament->players()->with('deck')->get() as $player) {
                if (!$player->deck || DeckUse::where('player_id', $player->id)->exists()) continue;
                $version = $this->snapshot($player->deck, 'Inscrição em torneio');
                DeckUse::create(['deck_version_id' => $version->id, 'player_id' => $player->id, 'tournament_id' => $tournament->id,
                    'tournament_name' => $tournament->name, 'created_at' => now()]);
            }
        });
    }
}
