<?php

namespace Tests\Feature;

use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\User;
use App\Services\DeckBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeckCardsTest extends TestCase
{
    use RefreshDatabase;

    /** Mini base de cartas usada pelo fake do Scryfall. */
    private array $catalog = [];

    private bool $outage = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalog = [
            'Lightning Bolt' => $this->card('Lightning Bolt', '{R}', 1, 'Instant', ['R']),
            'Counterspell' => $this->card('Counterspell', '{U}{U}', 2, 'Instant', ['U']),
            'Mountain' => $this->card('Mountain', '', 0, 'Basic Land — Mountain', []),
            'Atraxa, Praetors\' Voice' => $this->card('Atraxa, Praetors\' Voice', '{G}{W}{U}{B}', 4, 'Legendary Creature — Phyrexian Angel Horror', ['W', 'U', 'B', 'G']),
            'Goblin Guide' => $this->card('Goblin Guide', '{R}', 1, 'Creature — Goblin Scout', ['R']),
            'Smash to Smithereens' => $this->card('Smash to Smithereens', '{1}{R}', 2, 'Instant', ['R']),
            'Sol Ring' => $this->card('Sol Ring', '{1}', 1, 'Artifact', []),
        ];

        Http::fake(function (Request $request) {
            if ($this->outage) return Http::response([], 503);
            $url = $request->url();

            if (str_contains($url, '/cards/search')) {
                $q = mb_strtolower(explode(' game:', $request->data()['q'] ?? '')[0]);
                $hits = array_values(array_filter($this->catalog, fn ($card) => str_contains(mb_strtolower($card['name']), $q)));
                return $hits ? Http::response(['object' => 'list', 'data' => $hits]) : Http::response(['object' => 'error', 'status' => 404], 404);
            }

            if (str_contains($url, '/cards/collection')) {
                $found = [];
                $missing = [];
                foreach ($request->data()['identifiers'] as $identifier) {
                    $card = collect($this->catalog)->first(fn ($c) => mb_strtolower($c['name']) === mb_strtolower($identifier['name']));
                    $card ? $found[] = $card : $missing[] = $identifier;
                }
                return Http::response(['object' => 'list', 'data' => $found, 'not_found' => $missing]);
            }

            if (preg_match('#/cards/([0-9a-f-]{36})$#', $url, $match)) {
                $card = collect($this->catalog)->firstWhere('id', $match[1]);
                return $card ? Http::response($card) : Http::response(['object' => 'error'], 404);
            }

            return Http::response([], 500);
        });
    }

    public function test_search_endpoint_returns_normalized_cards(): void
    {
        $user = User::factory()->create();
        $this->getJson(route('cards.search', ['q' => 'bolt']))->assertUnauthorized();

        $this->actingAs($user)->getJson(route('cards.search', ['q' => 'bolt']))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Lightning Bolt')
            ->assertJsonPath('data.0.color_identity', 'R')
            ->assertJsonPath('data.0.image_url', 'https://cards.scryfall.io/normal/front/lightning-bolt.jpg');

        $this->actingAs($user)->getJson(route('cards.search', ['q' => 'x']))->assertStatus(422);
    }

    public function test_owner_adds_cards_using_server_side_card_data(): void
    {
        [$user, $deck] = $this->deck('Burn', 'Modern');
        $bolt = $this->catalog['Lightning Bolt'];

        // Mesmo que o cliente mande nome/tipo forjados, o servidor usa os dados do Scryfall.
        $this->actingAs($user)->post(route('decks.cards.store', $deck), [
            'scryfall_id' => $bolt['id'], 'quantity' => 4, 'name' => '<script>x</script>', 'type_line' => 'Land',
        ])->assertSessionHas('status');
        $this->actingAs($user)->post(route('decks.cards.store', $deck), ['scryfall_id' => $bolt['id'], 'quantity' => 1]);

        $card = $deck->cards()->sole();
        $this->assertSame('Lightning Bolt', $card->name);
        $this->assertSame('Instant', $card->type_line);
        $this->assertSame(5, $card->quantity);
        $this->assertSame('R', $deck->fresh()->colors);
    }

    public function test_invalid_ids_and_unknown_cards_are_rejected(): void
    {
        [$user, $deck] = $this->deck('Burn');

        $this->actingAs($user)->post(route('decks.cards.store', $deck), ['scryfall_id' => '../../etc/passwd'])->assertSessionHasErrors('scryfall_id');
        $this->actingAs($user)->post(route('decks.cards.store', $deck), ['scryfall_id' => (string) Str::uuid()])->assertSessionHasErrors('card');
        $this->assertSame(0, $deck->cards()->count());
    }

    public function test_only_legendary_creatures_can_be_commander(): void
    {
        [$user, $deck] = $this->deck('Superfriends', 'Commander');

        $this->actingAs($user)->post(route('decks.cards.store', $deck), ['scryfall_id' => $this->catalog['Sol Ring']['id'], 'board' => 'commander'])
            ->assertSessionHasErrors('card');
        $this->actingAs($user)->post(route('decks.cards.store', $deck), ['scryfall_id' => $this->catalog['Atraxa, Praetors\' Voice']['id'], 'board' => 'commander'])
            ->assertSessionHasNoErrors();

        $this->assertSame(DeckCard::BOARD_COMMANDER, $deck->cards()->sole()->board);
        $this->assertSame('WUBG', $deck->fresh()->colors);
    }

    public function test_import_parses_common_decklist_formats(): void
    {
        [$user, $deck] = $this->deck('Burn', 'Modern');
        $list = "Deck\n4 Lightning Bolt (2X2) 117\n4x Goblin Guide\n20 Mountain *F*\n1 Carta Inexistente\n\nSideboard\n2 Smash to Smithereens";

        $this->actingAs($user)->post(route('decks.cards.import', $deck), ['list' => $list])
            ->assertSessionHas('status', '30 cartas importadas.')
            ->assertSessionHasErrors('import');

        $this->assertSame(28, (int) $deck->cards()->where('board', 'main')->sum('quantity'));
        $this->assertSame(2, (int) $deck->cards()->where('board', 'side')->sum('quantity'));
        $this->assertSame('R', $deck->fresh()->colors);
    }

    public function test_mtgo_blank_line_separates_sideboard_and_replace_option(): void
    {
        [$user, $deck] = $this->deck('Burn', 'Modern');
        $this->actingAs($user)->post(route('decks.cards.import', $deck), ['list' => "4 Lightning Bolt\n\n2 Smash to Smithereens"]);
        $this->assertSame(2, (int) $deck->cards()->where('board', 'side')->sum('quantity'));

        $this->actingAs($user)->post(route('decks.cards.import', $deck), ['list' => '1 Counterspell', 'replace' => '1']);
        $this->assertSame(['Counterspell'], $deck->cards()->pluck('name')->all());
        $this->assertSame('U', $deck->fresh()->colors);
    }

    public function test_parser_handles_arena_commander_sections(): void
    {
        $parsed = app(DeckBuilderService::class)->parse("Commander\n1 Atraxa, Praetors' Voice\n\nDeck\n1 Sol Ring\nSB: 1 Counterspell\n// comentario\nCompanion\n1 Lurrus of the Dream-Den");

        $this->assertSame([
            ['quantity' => 1, 'name' => "Atraxa, Praetors' Voice", 'board' => 'commander'],
            ['quantity' => 1, 'name' => 'Sol Ring', 'board' => 'main'],
            ['quantity' => 1, 'name' => 'Counterspell', 'board' => 'side'],
        ], $parsed);
    }

    public function test_quantity_controls_move_and_remove_cards(): void
    {
        [$user, $deck] = $this->deck('Burn');
        $this->actingAs($user)->post(route('decks.cards.store', $deck), ['scryfall_id' => $this->catalog['Lightning Bolt']['id'], 'quantity' => 2]);
        $card = $deck->cards()->sole();

        $this->actingAs($user)->patch(route('decks.cards.update', [$deck, $card]), ['delta' => 1]);
        $this->assertSame(3, $card->fresh()->quantity);

        $this->actingAs($user)->patch(route('decks.cards.update', [$deck, $card]), ['quantity' => 3, 'board' => 'side']);
        $this->assertSame('side', $card->fresh()->board);

        $this->actingAs($user)->patch(route('decks.cards.update', [$deck, $card]), ['quantity' => 0]);
        $this->assertSame(0, $deck->cards()->count());
    }



    public function test_owner_can_hide_saved_deck_from_friends_and_reveal_it_again(): void
    {
        [$owner, $deck] = $this->deck('Secret CEDH list', 'Commander');
        $profile = $owner->ensurePlayerProfile();
        $profile->update(['preferred_deck_name' => $deck->name, 'preferred_deck_colors' => 'WUBRG']);
        $this->assertTrue((bool) $deck->fresh()->is_visible_to_friends);
        $friend = User::factory()->create();
        $friend->ensurePlayerProfile();
        $friend->friendProfiles()->attach($profile, ['status' => 'accepted']);

        $this->actingAs($owner)->patch(route('decks.update', $deck), [
            'name' => $deck->name,
            'format' => $deck->format,
            'colors' => 'WUBRG',
            'is_visible_to_friends' => '0',
        ])->assertRedirect();

        $this->actingAs($friend)->get(route('profiles.show', $profile))->assertDontSee('Secret CEDH list');
        $this->actingAs($friend)->get(route('decks.show', $deck))->assertNotFound();
        $this->actingAs($owner)->get(route('decks.show', $deck))->assertOk()->assertSee('Secret CEDH list');

        $this->actingAs($owner)->patch(route('decks.update', $deck), [
            'name' => $deck->name,
            'format' => $deck->format,
            'colors' => 'WUBRG',
            'is_visible_to_friends' => '1',
        ])->assertRedirect();

        $this->actingAs($friend)->get(route('profiles.show', $profile))->assertSee('Secret CEDH list');
        $this->actingAs($friend)->get(route('decks.show', $deck))->assertOk();
    }


    public function test_deck_page_is_visible_to_owner_and_friends_only(): void
    {
        [$owner, $deck] = $this->deck('Burn', 'Modern');
        $this->actingAs($owner)->post(route('decks.cards.store', $deck), ['scryfall_id' => $this->catalog['Lightning Bolt']['id'], 'quantity' => 4]);

        $this->actingAs($owner)->get(route('decks.show', $deck))
            ->assertOk()->assertSee('Lightning Bolt')->assertSee('Curva de mana')->assertSee('Buscar cartas')
            ->assertSee('pelo menos 60 cartas');

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get(route('decks.show', $deck))->assertForbidden();
        $this->actingAs($stranger)->post(route('decks.cards.store', $deck), ['scryfall_id' => $this->catalog['Mountain']['id']])->assertForbidden();
        $this->actingAs($stranger)->patch(route('decks.cards.update', [$deck, $deck->cards()->first()]), ['delta' => 1])->assertForbidden();

        $friend = User::factory()->create();
        $friend->ensurePlayerProfile();
        $friend->friendProfiles()->attach($deck->player_profile_id, ['status' => 'accepted']);
        $this->actingAs($friend)->get(route('decks.show', $deck))->assertOk()->assertSee('Lightning Bolt')->assertDontSee('Buscar cartas');
    }

    public function test_card_from_another_deck_cannot_be_edited_through_url(): void
    {
        [$user, $deckA] = $this->deck('A');
        $deckB = $user->ensurePlayerProfile()->decks()->create(['name' => 'B']);
        $this->actingAs($user)->post(route('decks.cards.store', $deckB), ['scryfall_id' => $this->catalog['Sol Ring']['id']]);

        $this->actingAs($user)->patch(route('decks.cards.update', [$deckA, $deckB->cards()->sole()]), ['delta' => 1])->assertNotFound();
    }

    public function test_commander_deck_warnings(): void
    {
        [$user, $deck] = $this->deck('EDH', 'Commander');
        $this->actingAs($user)->post(route('decks.cards.store', $deck), ['scryfall_id' => $this->catalog['Sol Ring']['id'], 'quantity' => 2]);

        $warnings = app(DeckBuilderService::class)->summary($deck)['warnings'];
        $this->assertContains('Defina o comandante do deck.', $warnings);
        $this->assertTrue(collect($warnings)->contains(fn ($w) => str_contains($w, 'singleton')));
    }

    public function test_scryfall_outage_shows_friendly_error(): void
    {
        $this->outage = true;
        [$user, $deck] = $this->deck('Burn');

        $this->actingAs($user)->post(route('decks.cards.store', $deck), ['scryfall_id' => (string) Str::uuid()])->assertSessionHasErrors('card');
        $this->actingAs($user)->getJson(route('cards.search', ['q' => 'bolt']))->assertOk()->assertJsonPath('data', []);
    }

    public function test_only_official_image_urls_are_stored(): void
    {
        $this->catalog['Lightning Bolt']['image_uris']['normal'] = 'https://evil.example.com/x.jpg';
        [$user, $deck] = $this->deck('Burn');

        $this->actingAs($user)->post(route('decks.cards.store', $deck), ['scryfall_id' => $this->catalog['Lightning Bolt']['id']]);
        $this->assertNull($deck->cards()->sole()->image_url);
    }

    public function test_new_deck_redirects_to_builder_and_first_deck_is_primary(): void
    {
        $user = User::factory()->create();


        $this->actingAs($user)->get(route('decks.index'))->assertOk()->assertSee('Novos decks começam privados; marque para compartilhar com amigos');

        $this->actingAs($user)->post(route('decks.store'), ['name' => 'Primeiro', 'format' => 'Pauper'])->assertRedirect();
        $deck = Deck::query()->sole();

        $this->assertTrue($deck->is_primary);
        $this->assertSame('Pauper', $deck->format);


        $this->assertFalse($deck->is_visible_to_friends);

    }

    /** @return array{0: User, 1: Deck} */
    private function deck(string $name, ?string $format = null): array
    {
        $user = User::factory()->create();

        return [$user, $user->ensurePlayerProfile()->decks()->create(['name' => $name, 'format' => $format])];
    }

    private function card(string $name, string $cost, float $cmc, string $type, array $identity): array
    {
        $slug = Str::slug($name);

        return [
            'object' => 'card', 'id' => (string) Str::uuid(), 'name' => $name, 'mana_cost' => $cost, 'cmc' => $cmc,
            'type_line' => $type, 'color_identity' => $identity, 'rarity' => 'common', 'set' => 'tst',
            'legalities' => ['commander' => 'legal'],
            'image_uris' => [
                'normal' => "https://cards.scryfall.io/normal/front/{$slug}.jpg",
                'art_crop' => "https://cards.scryfall.io/art_crop/front/{$slug}.jpg",
            ],
        ];
    }
}
