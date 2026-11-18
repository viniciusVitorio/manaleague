<?php

namespace Tests\Feature;

use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Escolher o comandante entre as cartas do próprio deck: mover, trocar, parceiro, devolver e quem vê os controles. */
class DeckCommanderTest extends TestCase
{
    use RefreshDatabase;

    private const LEGEND = 'Legendary Creature — Phyrexian Angel';

    public function test_a_card_from_the_list_becomes_commander_and_the_deck_size_stays_the_same(): void
    {
        [$owner, $deck] = $this->deck();
        $atraxa = $this->card($deck, "Atraxa, Praetors' Voice", 'main');
        $this->card($deck, 'Sol Ring', 'main', 'Artifact');

        $this->actingAs($owner)->post(route('decks.cards.commander', [$deck, $atraxa]))
            ->assertRedirect()->assertSessionHas('status', "Atraxa, Praetors' Voice agora é comandante.");

        $this->assertSame('commander', $atraxa->fresh()->board);
        $this->actingAs($owner)->get(route('decks.show', $deck))
            ->assertSee('2</b> cartas', false)->assertDontSee('Defina o comandante do deck.');
    }

    public function test_only_one_copy_moves_and_a_sideboard_card_can_be_chosen(): void
    {
        [$owner, $deck] = $this->deck();
        $copies = $this->card($deck, 'Krenko', 'main', self::LEGEND, 3);
        $side = $this->card($deck, 'Tymna', 'side');

        $this->actingAs($owner)->post(route('decks.cards.commander', [$deck, $copies]));
        $this->assertSame(['commander' => 1, 'main' => 2], $this->boards($deck, 'Krenko'));

        $this->actingAs($owner)->post(route('decks.cards.commander', [$deck, $side]));
        $this->assertSame('commander', $side->fresh()->board);
    }

    public function test_swapping_sends_the_old_commander_back_to_the_deck(): void
    {
        [$owner, $deck] = $this->deck();
        $old = $this->card($deck, 'Atraxa', 'commander');
        $new = $this->card($deck, 'Krenko', 'main');

        $this->actingAs($owner)->post(route('decks.cards.commander', [$deck, $new]), ['replace' => $old->id])->assertSessionHasNoErrors();

        $this->assertSame('main', $old->fresh()->board);
        $this->assertSame('commander', $new->fresh()->board);
    }

    public function test_a_partner_fits_but_a_third_commander_must_replace_someone(): void
    {
        [$owner, $deck] = $this->deck();
        $first = $this->card($deck, 'Tymna', 'commander');
        $partner = $this->card($deck, 'Thrasios', 'main');
        $third = $this->card($deck, 'Krenko', 'main');

        $this->actingAs($owner)->post(route('decks.cards.commander', [$deck, $partner]))->assertSessionHasNoErrors();
        $this->assertSame('commander', $partner->fresh()->board);

        $this->actingAs($owner)->post(route('decks.cards.commander', [$deck, $third]))->assertSessionHasErrors('card');
        $this->assertSame('main', $third->fresh()->board);

        $this->actingAs($owner)->post(route('decks.cards.commander', [$deck, $third]), ['replace' => $first->id])->assertSessionHasNoErrors();
        $this->assertSame(['Krenko', 'Thrasios'], $deck->cards()->reorder()->where('board', 'commander')->orderBy('name')->pluck('name')->all());
    }

    public function test_invalid_choices_change_nothing(): void
    {
        [$owner, $deck] = $this->deck();
        $commander = $this->card($deck, 'Atraxa', 'commander');
        $ring = $this->card($deck, 'Sol Ring', 'main', 'Artifact');
        $reprint = $this->card($deck, 'Atraxa', 'main');
        $legend = $this->card($deck, 'Krenko', 'main');

        // Carta que não pode comandar, o mesmo comandante em outra edição e troca de quem não é comandante.
        $this->actingAs($owner)->post(route('decks.cards.commander', [$deck, $ring]))->assertSessionHasErrors('card');
        $this->actingAs($owner)->post(route('decks.cards.commander', [$deck, $reprint]))->assertSessionHasErrors('card');
        $this->actingAs($owner)->post(route('decks.cards.commander', [$deck, $legend]), ['replace' => $ring->id])->assertSessionHasErrors('card');

        $this->assertSame([$commander->id], $deck->cards()->where('board', 'commander')->pluck('id')->all());
        $this->assertSame('main', $ring->fresh()->board);

        // Fora do formato Commander não existe comandante para escolher.
        [$pauperOwner, $pauper] = $this->deck('Pauper');
        $card = $this->card($pauper, 'Krenko', 'main');
        $this->actingAs($pauperOwner)->post(route('decks.cards.commander', [$pauper, $card]))->assertSessionHasErrors('card');
        $this->assertSame('main', $card->fresh()->board);
    }

    public function test_moving_the_commander_back_keeps_the_card_and_merges_with_the_list(): void
    {
        [$owner, $deck] = $this->deck();
        $commander = $this->card($deck, 'Atraxa', 'commander');

        $this->actingAs($owner)->delete(route('decks.cards.commander.remove', [$deck, $commander]))
            ->assertSessionHas('status', 'Atraxa voltou para o deck.');
        $this->assertSame('main', $commander->fresh()->board);

        // Uma carta comum não "sai do comando".
        $this->actingAs($owner)->delete(route('decks.cards.commander.remove', [$deck, $commander]))->assertSessionHasErrors('card');

        // Mesma edição já na lista: soma em vez de duplicar a linha.
        $line = $this->card($deck, 'Krenko', 'main');
        $again = $deck->cards()->create([...$line->only(['scryfall_id', 'name', 'type_line', 'cmc', 'set_code']), 'board' => 'commander', 'quantity' => 1]);
        $this->actingAs($owner)->delete(route('decks.cards.commander.remove', [$deck, $again]));
        $this->assertSame(['main' => 2], $this->boards($deck, 'Krenko'));
    }

    public function test_only_the_owner_can_change_the_commander(): void
    {
        [$owner, $deck] = $this->deck();
        $card = $this->card($deck, 'Krenko', 'main');
        $commander = $this->card($deck, 'Atraxa', 'commander');

        $stranger = User::factory()->create();
        $stranger->ensurePlayerProfile();
        $this->actingAs($stranger)->post(route('decks.cards.commander', [$deck, $card]))->assertForbidden();
        $this->actingAs($stranger)->delete(route('decks.cards.commander.remove', [$deck, $commander]))->assertForbidden();

        // Carta de outro deck na URL do deck do dono.
        [, $other] = $this->deck();
        $foreign = $this->card($other, 'Tymna', 'main');
        $this->actingAs($owner)->post(route('decks.cards.commander', [$deck, $foreign]))->assertNotFound();

        $this->assertSame('main', $card->fresh()->board);
        $this->assertSame('commander', $commander->fresh()->board);
    }

    public function test_page_without_commander_invites_the_owner_to_choose_among_eligible_cards(): void
    {
        [$owner, $deck] = $this->deck();
        $legend = $this->card($deck, 'Krenko', 'main');
        $ring = $this->card($deck, 'Sol Ring', 'main', 'Artifact');

        $this->actingAs($owner)->get(route('decks.show', $deck))->assertOk()
            ->assertSee('Escolha o comandante')->assertSee('1 carta da sua lista pode comandar este deck.')
            ->assertSee('data-drop-board="commander"', false)
            ->assertSee('id="deck-commander"', false)
            ->assertSee('data-commander-card="'.$legend->id.'"', false)
            ->assertDontSee('data-commander-card="'.$ring->id.'"', false)
            ->assertSee('Tornar comandante');
    }

    public function test_page_with_a_commander_offers_swap_partner_and_move_back(): void
    {
        [$owner, $deck] = $this->deck();
        $commander = $this->card($deck, "Atraxa, Praetors' Voice", 'commander');
        $this->card($deck, 'Krenko', 'main');

        $this->actingAs($owner)->get(route('decks.show', $deck))->assertOk()
            ->assertDontSee('Escolha o comandante')
            ->assertSee('data-commander-slot="'.$commander->id.'"', false)
            ->assertSee('No lugar de Atraxa')->assertSee('Adicionar como parceiro')->assertSee('Mover para o deck');
    }

    public function test_deck_without_eligible_cards_points_to_the_search(): void
    {
        [$owner, $deck] = $this->deck();
        $this->card($deck, 'Sol Ring', 'main', 'Artifact');

        $this->actingAs($owner)->get(route('decks.show', $deck))->assertOk()
            ->assertSee('Buscar comandante')->assertDontSee('id="deck-commander"', false);
    }

    public function test_visitors_and_other_formats_do_not_get_the_commander_controls(): void
    {
        [, $deck] = $this->deck();
        $this->card($deck, 'Krenko', 'main');
        $this->card($deck, 'Atraxa', 'commander');

        $friend = User::factory()->create();
        $friend->ensurePlayerProfile();
        $friend->friendProfiles()->attach($deck->profile, ['status' => 'accepted']);
        $this->actingAs($friend)->get(route('decks.show', $deck))->assertOk()
            ->assertSee('Atraxa')->assertDontSee('deck-commander')->assertDontSee('data-commander-card', false)
            ->assertDontSee('Mover para o deck')->assertDontSee('data-drop-board', false);

        [$owner, $pauper] = $this->deck('Pauper');
        $this->card($pauper, 'Krenko', 'main');
        $this->actingAs($owner)->get(route('decks.show', $pauper))->assertOk()
            ->assertDontSee('Escolha o comandante')->assertDontSee('data-commander-card', false)->assertDontSee('data-drop-board="commander"', false);
    }

    private function deck(string $format = 'Commander'): array
    {
        $owner = User::factory()->create();
        $deck = $owner->ensurePlayerProfile()->decks()->create(['name' => 'Teste', 'format' => $format]);

        return [$owner, $deck];
    }

    private function card(Deck $deck, string $name, string $board, string $type = self::LEGEND, int $quantity = 1): DeckCard
    {
        return $deck->cards()->create(['scryfall_id' => (string) Str::uuid(), 'name' => $name, 'board' => $board,
            'quantity' => $quantity, 'cmc' => 1, 'type_line' => $type, 'set_code' => 'CMM']);
    }

    /** @return array<string, int> quantidade por lado */
    private function boards(Deck $deck, string $name): array
    {
        return $deck->cards()->reorder()->where('name', $name)->orderBy('board')->pluck('quantity', 'board')->all();
    }
}
