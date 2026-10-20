<?php

namespace Tests\Feature;

use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\Player;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeckCoverAndEntryTest extends TestCase
{
    use RefreshDatabase;

    // ===== Capa do deck =====

    public function test_owner_can_choose_deck_cover_card(): void
    {
        [$user, $deck] = $this->deckWithCards();
        $frog = $deck->cards()->where('name', 'Frog Butler')->sole();

        $this->actingAs($user)->get(route('decks.show', $deck))
            ->assertOk()->assertSee('Trocar capa')->assertSee('art/smaug.jpg', false);

        $this->actingAs($user)->patch(route('decks.cover', $deck), ['card_id' => $frog->id])->assertRedirect();
        $this->assertSame($frog->id, $deck->fresh()->cover_card_id);

        $this->actingAs($user)->get(route('decks.show', $deck))
            ->assertSee("--cover: url('https://img.test/art/frog.jpg')", false);
    }

    public function test_empty_cover_restores_automatic_choice(): void
    {
        [$user, $deck] = $this->deckWithCards();
        $deck->forceFill(['cover_card_id' => $deck->cards()->first()->id])->save();

        $this->actingAs($user)->patch(route('decks.cover', $deck), ['card_id' => ''])->assertRedirect();
        $this->assertNull($deck->fresh()->cover_card_id);

        // Automatico = nao-terreno de maior CMC (Smaug).
        $this->actingAs($user)->get(route('decks.show', $deck))
            ->assertSee("--cover: url('https://img.test/art/smaug.jpg')", false);
    }

    public function test_cover_must_be_a_card_from_the_same_deck(): void
    {
        [$user, $deck] = $this->deckWithCards();
        [, $otherDeck] = $this->deckWithCards();

        $this->actingAs($user)->patch(route('decks.cover', $deck), ['card_id' => $otherDeck->cards()->first()->id])
            ->assertSessionHasErrors('card_id');
        $this->assertNull($deck->fresh()->cover_card_id);
    }

    public function test_only_owner_can_change_cover(): void
    {
        [, $deck] = $this->deckWithCards();
        $this->actingAs(User::factory()->create())
            ->patch(route('decks.cover', $deck), ['card_id' => $deck->cards()->first()->id])->assertForbidden();
    }

    public function test_removed_cover_card_falls_back_to_automatic(): void
    {
        [$user, $deck] = $this->deckWithCards();
        $frog = $deck->cards()->where('name', 'Frog Butler')->sole();
        $deck->forceFill(['cover_card_id' => $frog->id])->save();
        $frog->delete();

        $this->actingAs($user)->get(route('decks.show', $deck))
            ->assertOk()->assertSee("--cover: url('https://img.test/art/smaug.jpg')", false);
    }

    // ===== Troca de deck na inscricao =====

    public function test_player_can_switch_deck_before_tournament_starts(): void
    {
        [$user, $deck] = $this->deckWithCards();
        $profile = $user->playerProfile;
        $other = $profile->decks()->create(['name' => 'Mono Blue Faeries', 'colors' => 'U', 'format' => 'Pauper']);
        $tournament = $this->tournament();
        $entry = $tournament->players()->create(['player_profile_id' => $profile->id, 'deck_id' => $deck->id, 'name' => $profile->nickname, 'deck_name' => $deck->name, 'deck_colors' => 'R']);

        $this->actingAs($user)->get(route('arena.public', $tournament->public_slug))
            ->assertOk()->assertSee('Sua inscrição')->assertSee('Trocar deck');

        $this->actingAs($user)->patch(route('tournaments.entry.update', $tournament), ['deck_id' => $other->id])
            ->assertRedirect()->assertSessionHas('status');

        $entry->refresh();
        $this->assertSame($other->id, $entry->deck_id);
        $this->assertSame('Mono Blue Faeries', $entry->deck_name);
        $this->assertSame('U', $entry->deck_colors);
    }

    public function test_player_cannot_switch_deck_after_tournament_starts(): void
    {
        [$user, $deck] = $this->deckWithCards();
        $profile = $user->playerProfile;
        $tournament = $this->tournament();
        $entry = $tournament->players()->create(['player_profile_id' => $profile->id, 'name' => $profile->nickname, 'deck_name' => 'Antigo']);
        $tournament->update(['status' => Tournament::STATUS_LEAGUE]);

        $this->actingAs($user)->get(route('arena.public', $tournament->public_slug))->assertOk()->assertDontSee('Sua inscrição');
        $this->actingAs($user)->patch(route('tournaments.entry.update', $tournament), ['deck_id' => $deck->id])->assertStatus(409);
        $this->assertSame('Antigo', $entry->fresh()->deck_name);
    }

    public function test_player_cannot_use_someone_elses_deck(): void
    {
        [$user] = $this->deckWithCards();
        [, $foreignDeck] = $this->deckWithCards();
        $profile = $user->playerProfile;
        $tournament = $this->tournament();
        $entry = $tournament->players()->create(['player_profile_id' => $profile->id, 'name' => $profile->nickname, 'deck_name' => 'Meu']);

        $this->actingAs($user)->patch(route('tournaments.entry.update', $tournament), ['deck_id' => $foreignDeck->id])
            ->assertSessionHasErrors('deck_id');
        $this->assertSame('Meu', $entry->fresh()->deck_name);
        $this->assertNull($entry->fresh()->deck_id);
    }

    public function test_non_enrolled_user_gets_404_and_other_entries_are_untouched(): void
    {
        $tournament = $this->tournament();
        $someone = $tournament->players()->create(['name' => 'Outro', 'deck_name' => 'Elves']);

        $this->actingAs(User::factory()->create())
            ->patch(route('tournaments.entry.update', $tournament), ['deck_name' => 'Hackeado'])->assertNotFound();
        $this->assertSame('Elves', $someone->fresh()->deck_name);
    }

    public function test_guest_cannot_switch_deck(): void
    {
        $tournament = $this->tournament();
        $this->patch(route('tournaments.entry.update', $tournament), ['deck_name' => 'X'])->assertRedirect(route('login'));
    }

    public function test_invite_page_offers_switch_for_enrolled_player_and_join_saves_deck_id(): void
    {
        [$user, $deck] = $this->deckWithCards();
        $tournament = $this->tournament();

        $this->actingAs($user)->post(route('tournaments.invite.store', $tournament->invite_token), ['deck_id' => $deck->id])
            ->assertRedirect(route('arena.public', $tournament->public_slug));
        $entry = Player::query()->where('tournament_id', $tournament->id)->sole();
        $this->assertSame($deck->id, $entry->deck_id);
        $this->assertSame($deck->name, $entry->deck_name);

        $this->actingAs($user)->get(route('tournaments.invite.show', $tournament->invite_token))
            ->assertOk()->assertSee('Você já está inscrito')->assertSee('Trocar deck');
    }

    /** @return array{0: User, 1: Deck} */
    private function deckWithCards(): array
    {
        $user = User::factory()->create();
        $profile = $user->ensurePlayerProfile();
        $deck = $profile->decks()->create(['name' => 'Mono Red Smaug', 'colors' => 'R', 'format' => 'Pauper']);
        foreach ([
            ['Frog Butler', '{1}{G}', 2, 'Creature — Frog', 'frog'],
            ['Smaug, the Great Calamity // Spew Flame', '{5}{R}{R} // {4}{R}', 7, 'Legendary Creature — Dragon // Instant', 'smaug'],
            ['Mountain', '', 0, 'Basic Land — Mountain', 'mountain'],
        ] as [$name, $cost, $cmc, $type, $art]) {
            $deck->cards()->create([
                'scryfall_id' => (string) Str::uuid(), 'board' => DeckCard::BOARD_MAIN, 'quantity' => 2, 'name' => $name,
                'mana_cost' => $cost, 'cmc' => $cmc, 'type_line' => $type, 'image_url' => "https://img.test/card/{$art}.jpg",
                'art_url' => "https://img.test/art/{$art}.jpg",
            ]);
        }

        return [$user->fresh(), $deck];
    }

    private function tournament(): Tournament
    {
        return User::factory()->create()->tournaments()->create([
            'name' => 'Liga de sexta', 'format' => 'Pauper', 'tournament_date' => '2026-10-10',
            'public_token' => Str::random(40), 'public_slug' => 'liga-'.Str::lower(Str::random(8)), 'invite_token' => Str::random(40),
        ]);
    }
}
