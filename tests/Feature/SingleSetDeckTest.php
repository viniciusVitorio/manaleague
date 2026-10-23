<?php

namespace Tests\Feature;

use App\Models\Deck;
use App\Models\User;
use App\Services\DeckBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SingleSetDeckTest extends TestCase
{
    use RefreshDatabase;

    public function test_deck_is_single_set_only_with_60_main_cards_and_spells_from_one_set(): void
    {
        $deck = $this->deck();
        $this->assertNull($deck->singleSet(), 'Deck vazio não é coleção única');

        $deck->cards()->create($this->card('Forest', 'main', 20, 'LTR', 'Basic Land — Forest'));
        $deck->cards()->create($this->card('Generous Ent', 'main', 39, 'LTR'));
        $this->assertNull($deck->singleSet(), '59 cartas ainda não contam');

        $deck->cards()->create($this->card('Lembas', 'main', 1, 'LTR'));
        $this->assertSame('LTR', $deck->singleSet());

        $deck->cards()->create($this->card('Duress', 'side', 4, 'M21'));
        $this->assertSame('LTR', $deck->fresh()->singleSet(), 'Sideboard não conta');

        $deck->cards()->create($this->card('Counterspell', 'main', 1, 'DMR'));
        $this->assertNull($deck->fresh()->singleSet(), 'Uma mágica de outra coleção quebra a regra');
    }

    public function test_lands_from_any_set_are_ignored_but_count_toward_60(): void
    {
        $deck = $this->deck();
        $deck->cards()->create($this->card('Plains', 'main', 12, 'DMU', 'Basic Land — Plains'));
        $deck->cards()->create($this->card('Evolving Wilds', 'main', 4, 'M21', 'Land'));
        $deck->cards()->create($this->card('Brightstone Ritual', 'main', 1, 'ZNR', 'Sorcery // Land'));
        $this->assertNull($deck->singleSet(), 'Menos de 60 cartas');

        $deck->cards()->create($this->card('Samwise the Stouthearted', 'main', 43, 'LTR', 'Legendary Creature — Halfling Peasant'));
        $this->assertNull($deck->fresh()->singleSet(), 'Carta de duas faces com frente de mágica conta como mágica');

        $deck->cards()->where('name', 'Brightstone Ritual')->update(['type_line' => 'Land // Sorcery']);
        $this->assertSame('LTR', $deck->fresh()->singleSet(), 'Terrenos (inclusive com frente de terreno) não contam');
    }

    public function test_cards_without_set_never_make_a_single_set_deck(): void
    {
        $deck = $this->deck();
        $deck->cards()->create($this->card('Generous Ent', 'main', 60, null));

        $this->assertNull($deck->singleSet());
    }

    public function test_badge_shows_on_deck_page_and_profile(): void
    {
        $deck = $this->deck();
        $deck->cards()->create($this->card('Forest', 'main', 24, 'M21', 'Basic Land — Forest'));
        $deck->cards()->create($this->card('Generous Ent', 'main', 36, 'LTR'));
        $owner = $deck->profile->account;

        $this->actingAs($owner)->get(route('decks.show', $deck))->assertOk()->assertSee('Coleção única · LTR');
        $this->actingAs($owner)->get(route('profiles.edit'))->assertOk()->assertSee('Coleção única · LTR');
        $this->actingAs($owner)->get(route('profiles.show', $deck->profile))->assertOk()->assertSee('Coleção única · LTR');

        $deck->cards()->create($this->card('Counterspell', 'main', 1, 'DMR'));
        $this->actingAs($owner)->get(route('decks.show', $deck))->assertOk()->assertDontSee('Coleção única');
    }

    public function test_import_keeps_the_printing_given_in_parentheses(): void
    {
        $printings = [
            'Forest|LTR' => $this->scryfallCard('Forest', 'ltr'),
            'Forest' => $this->scryfallCard('Forest', 'dmu'),
            'Lembas' => $this->scryfallCard('Lembas', 'ltr'),
        ];
        Http::fake(function (Request $request) use ($printings) {
            $found = $missing = [];
            foreach ($request->data()['identifiers'] as $identifier) {
                $key = $identifier['name'].(isset($identifier['set']) ? '|'.strtoupper($identifier['set']) : '');
                isset($printings[$key]) ? $found[] = $printings[$key] : $missing[] = $identifier;
            }

            return Http::response(['object' => 'list', 'data' => $found, 'not_found' => $missing]);
        });

        $deck = $this->deck();
        $owner = $deck->profile->account;

        // "Lembas (XXX)" não existe nessa coleção: cai para a edição padrão pelo nome.
        // "2 Forest" sem coleção reaproveita a edição já pedida na lista (LTR), em vez de misturar edições.
        $this->actingAs($owner)->post(route('decks.cards.import', $deck), ['list' => "20 Forest (LTR) 262\n4 Lembas (XXX)\n2 Forest"])
            ->assertSessionHasNoErrors();

        $sets = $deck->cards()->get()->map(fn ($c) => $c->name.':'.$c->set_code.':'.$c->quantity)->sort()->values()->all();
        $this->assertSame(['Forest:LTR:22', 'Lembas:LTR:4'], $sets);
    }

    public function test_import_without_set_uses_the_default_printing(): void
    {
        Http::fake(fn () => Http::response(['object' => 'list', 'data' => [$this->scryfallCard('Forest', 'dmu')], 'not_found' => []]));
        $deck = $this->deck();

        $this->actingAs($deck->profile->account)->post(route('decks.cards.import', $deck), ['list' => '20 Forest'])->assertSessionHasNoErrors();

        $this->assertSame('DMU', $deck->cards()->sole()->set_code);
    }

    public function test_parser_reads_the_set_code(): void
    {
        $parsed = app(DeckBuilderService::class)->parse("20 Forest (LTR) 262\n1x Sol Ring (cmm) 400 *F*\n4 Lightning Bolt");

        $this->assertSame('LTR', $parsed[0]['set']);
        $this->assertSame('Forest', $parsed[0]['name']);
        $this->assertSame('CMM', $parsed[1]['set']);
        $this->assertSame('Sol Ring', $parsed[1]['name']);
        $this->assertArrayNotHasKey('set', $parsed[2]);
    }

    private function deck(): Deck
    {
        $profile = User::factory()->create()->ensurePlayerProfile();

        return $profile->decks()->create(['name' => 'Sociedade do Anel', 'format' => 'Pauper']);
    }

    private function card(string $name, string $board, int $quantity, ?string $set, string $type = 'Creature — Treefolk'): array
    {
        return ['scryfall_id' => (string) Str::uuid(), 'name' => $name, 'board' => $board, 'quantity' => $quantity,
            'cmc' => 0, 'type_line' => $type, 'set_code' => $set];
    }

    private function scryfallCard(string $name, string $set): array
    {
        return ['object' => 'card', 'id' => (string) Str::uuid(), 'name' => $name, 'cmc' => 0, 'type_line' => 'Basic Land',
            'color_identity' => [], 'rarity' => 'common', 'set' => $set, 'legalities' => []];
    }
}
