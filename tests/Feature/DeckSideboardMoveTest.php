<?php

namespace Tests\Feature;

use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeckSideboardMoveTest extends TestCase
{
    use RefreshDatabase;

    public function test_moving_some_copies_splits_the_card_between_deck_and_sideboard(): void
    {
        [$owner, $deck] = $this->deck();
        $duress = $this->card($deck, 'Duress', 'main', 4);

        $this->actingAs($owner)->patch(route('decks.cards.update', [$deck, $duress]), ['board' => 'side', 'move' => 1])->assertRedirect();
        $this->assertSame(['main' => 3, 'side' => 1], $this->boards($deck, 'Duress'));

        // Mover de volta soma na linha que já existe no deck.
        $side = $deck->cards()->where('name', 'Duress')->where('board', 'side')->sole();
        $this->actingAs($owner)->patch(route('decks.cards.update', [$deck, $side]), ['board' => 'main', 'move' => 1]);
        $this->assertSame(['main' => 4], $this->boards($deck, 'Duress'));
    }

    public function test_moving_all_copies_keeps_the_same_line_and_merges_when_needed(): void
    {
        [$owner, $deck] = $this->deck();
        $bolt = $this->card($deck, 'Lightning Bolt', 'main', 4);

        $this->actingAs($owner)->patch(route('decks.cards.update', [$deck, $bolt]), ['board' => 'side', 'move' => 4]);
        $this->assertSame('side', $bolt->fresh()->board, 'Movendo tudo, a mesma linha troca de lado');
        $this->assertSame(4, $bolt->fresh()->quantity);

        // Pedir mais cópias do que existem move só as que existem.
        $this->actingAs($owner)->patch(route('decks.cards.update', [$deck, $bolt]), ['board' => 'main', 'move' => 99]);
        $this->assertSame(['main' => 4], $this->boards($deck, 'Lightning Bolt'));
    }

    public function test_drag_and_drop_request_gets_json(): void
    {
        [$owner, $deck] = $this->deck();
        $card = $this->card($deck, 'Pyroblast', 'main', 2);

        $this->actingAs($owner)->patchJson(route('decks.cards.update', [$deck, $card]), ['board' => 'side', 'move' => 2])
            ->assertOk()->assertJson(['moved' => 2]);
        $this->assertSame(['side' => 2], $this->boards($deck, 'Pyroblast'));
    }

    public function test_commander_cannot_be_moved_and_other_users_cannot_move_cards(): void
    {
        [$owner, $deck] = $this->deck();
        $commander = $this->card($deck, 'Atraxa', 'commander', 1);
        $this->actingAs($owner)->patch(route('decks.cards.update', [$deck, $commander]), ['board' => 'side', 'move' => 1]);
        $this->assertSame('commander', $commander->fresh()->board);

        $card = $this->card($deck, 'Duress', 'main', 4);
        $this->actingAs(User::factory()->create())->patch(route('decks.cards.update', [$deck, $card]), ['board' => 'side', 'move' => 1])->assertForbidden();
        $this->assertSame(['main' => 4], $this->boards($deck, 'Duress'));

        $this->actingAs($owner)->patch(route('decks.cards.update', [$deck, $card]), ['board' => 'side', 'move' => 0])->assertSessionHasErrors('move');
    }

    public function test_deck_page_shows_drag_targets_only_to_the_owner(): void
    {
        [$owner, $deck] = $this->deck();
        $this->card($deck, 'Duress', 'main', 4);

        $this->actingAs($owner)->get(route('decks.show', $deck))->assertOk()
            ->assertSee('data-drop-board="side"', false)->assertSee('draggable="true"', false)->assertSee('Arraste cartas para cá');

        $friend = User::factory()->create();
        $friend->ensurePlayerProfile();
        $friend->friendProfiles()->attach($deck->profile, ['status' => 'accepted']);
        $this->actingAs($friend)->get(route('decks.show', $deck))->assertOk()
            ->assertSee('Duress')->assertDontSee('data-drop-board', false)->assertDontSee('draggable="true"', false);
    }

    private function deck(): array
    {
        $owner = User::factory()->create();
        $deck = $owner->ensurePlayerProfile()->decks()->create(['name' => 'Teste', 'format' => 'Pauper']);

        return [$owner, $deck];
    }

    private function card(Deck $deck, string $name, string $board, int $quantity): DeckCard
    {
        return $deck->cards()->create(['scryfall_id' => (string) Str::uuid(), 'name' => $name, 'board' => $board,
            'quantity' => $quantity, 'cmc' => 1, 'type_line' => 'Instant', 'set_code' => 'M21']);
    }

    /** @return array<string, int> quantidade por board */
    private function boards(Deck $deck, string $name): array
    {
        return $deck->cards()->reorder()->where('name', $name)->orderBy('board')->pluck('quantity', 'board')->all();
    }
}
