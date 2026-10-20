<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\DeckVersion;
use App\Models\DeckUse;
use App\Services\Decks\DeckVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeckToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_pages_and_comparison_enforce_privacy_and_quantity_deltas(): void
    {
        [$user, $deck] = $this->deck();
        $other = $user->playerProfile->decks()->create(['name' => 'Other']);
        $this->actingAs($user)->get('/me/deck-history')->assertOk()->assertSee('Histórico');
        $this->post('/decks/'.$deck->id.'/versions', ['label' => 'Primeira'])->assertRedirect();
        $version = DeckVersion::query()->sole();
        $this->get('/me/deck-history/'.$version->id)->assertOk()->assertSee('Mountain');
        $this->get('/decks/compare?left='.$deck->id.'&right='.$other->id)->assertOk()->assertSee('Mountain')->assertSee('-24');
        $this->actingAs(User::factory()->create())->get('/me/deck-history/'.$version->id)->assertNotFound();
        $this->get('/decks/compare?left='.$deck->id.'&right='.$other->id)->assertNotFound();
    }

    public function test_simulator_renders_main_only_and_is_private(): void
    {
        [$user, $deck] = $this->deck();
        $deck->cards()->create(['scryfall_id' => (string) Str::uuid(), 'name' => 'Side Secret', 'board' => 'side', 'quantity' => 1]);
        $this->actingAs($user)->get('/decks/'.$deck->id.'/simulator')->assertOk()->assertSee('Londres')->assertDontSee('Side Secret')->assertSee('deck-simulator.js');
        $this->actingAs(User::factory()->create())->get('/decks/'.$deck->id.'/simulator')->assertNotFound();
    }

    public function test_collection_preserves_print_finish_condition_and_allocates_copies_once(): void
    {
        [$user, $deck, $card] = $this->deck();
        $this->mock(\App\Services\ScryfallClient::class, function ($mock) use ($card) {
            $mock->shouldReceive('card')->with($card->scryfall_id)->andReturn(['scryfall_id' => $card->scryfall_id, 'name' => 'Mountain', 'set_code' => 'TST', 'image_url' => null]);
        });
        $this->actingAs($user)->get('/me/collection')->assertOk();
        $this->post('/me/collection', ['scryfall_id' => $card->scryfall_id, 'quantity' => 10, 'foil' => 1, 'condition' => 'NM'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/me/collection', ['scryfall_id' => $card->scryfall_id, 'quantity' => 2, 'foil' => 0, 'condition' => 'LP'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('collection_items', 2);
        $this->get('/me/collection')->assertOk()->assertSee('Mountain')->assertSee('NM');
        $deck->cards()->create(['scryfall_id' => $card->scryfall_id, 'name' => 'Mountain', 'quantity' => 2, 'board' => 'side']);
        $this->get('/me/collection/missing/'.$deck->id)->assertOk()->assertViewHas('rows', fn ($rows) => $rows[0]['missing'] === 14 && $rows[0]['owned'] === 12);
        $item = \App\Models\CollectionItem::query()->first();
        $this->actingAs(User::factory()->create())->patch('/me/collection/'.$item->id, ['quantity' => 100])->assertNotFound();
        $this->delete('/me/collection/'.$item->id)->assertNotFound();
        $this->get('/me/collection/missing/'.$deck->id)->assertNotFound();
        $this->get('/me/collection')->assertOk()->assertDontSee('Mountain');
        $this->actingAs($user)->patch('/me/collection/'.$item->id, ['quantity' => 8])->assertRedirect();
        $this->assertSame(8, $item->fresh()->quantity);
        $this->delete('/me/collection/'.$item->id)->assertRedirect();
        $this->assertDatabaseCount('collection_items', 1);
    }

    private function deck(): array
    {
        $user = User::factory()->create();
        $deck = $user->ensurePlayerProfile()->decks()->create(['name' => 'Burn', 'format' => 'Modern']);
        $card = $deck->cards()->create(['scryfall_id' => (string) Str::uuid(), 'name' => 'Mountain', 'board' => 'main', 'quantity' => 24, 'type_line' => 'Basic Land', 'art_url' => 'https://cards.scryfall.io/art_crop/front/a/b/example.jpg']);
        $deck->forceFill(['cover_card_id' => $card->id])->save();
        return [$user, $deck, $card];
    }

    public function test_tournament_snapshot_is_idempotent_immutable_and_survives_deck_deletion(): void
    {
        [$user, $deck, $card] = $this->deck();
        $tournament = $user->tournaments()->create(['name' => 'Friday', 'format' => 'Modern', 'tournament_date' => '2026-10-20', 'public_token' => Str::random(40)]);
        $player = $tournament->players()->create(['name' => 'Player', 'player_profile_id' => $deck->player_profile_id, 'deck_id' => $deck->id]);
        $service = app(DeckVersionService::class);
        $service->snapshotForTournament($tournament);
        $card->update(['quantity' => 20]);
        $service->snapshotForTournament($tournament);
        $deck->delete();
        $use = DeckUse::query()->sole();
        $this->assertSame($player->id, $use->player_id);
        $this->assertSame(24, $use->version->payload['cards'][0]['quantity']);
        $this->assertSame($card->art_url, $use->version->payload['cover_art']);
        $this->expectException(\LogicException::class);
        $use->version->update(['label' => 'tampered']);
    }
}
