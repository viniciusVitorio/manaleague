<?php

namespace Tests\Feature;

use App\Models\CollectionItem;
use App\Models\Deck;
use App\Models\User;
use App\Services\Decks\CollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FoilAndManaBoxImportTest extends TestCase
{
    use RefreshDatabase;

    private const BOLT = '11111111-1111-4111-8111-111111111111';
    private const SPELL = '22222222-2222-4222-8222-222222222222';

    private function scryfallCard(string $id, string $name, string $set = 'm10'): array
    {
        return ['id' => $id, 'name' => $name, 'set' => $set, 'set_name' => 'Magic 2010', 'collector_number' => '146', 'lang' => 'en',
            'rarity' => 'common', 'type_line' => 'Instant', 'mana_cost' => '{R}', 'cmc' => 1, 'color_identity' => ['R'], 'prices' => ['usd' => '1.00', 'usd_foil' => '5.00']];
    }

    /** Scryfall falso: carta por ID, /cards/collection por ID ou por nome. */
    private function fakeScryfall(): void
    {
        $cards = [self::BOLT => $this->scryfallCard(self::BOLT, 'Lightning Bolt'), self::SPELL => $this->scryfallCard(self::SPELL, 'Counterspell', 'mh2')];
        Http::fake(function (HttpRequest $request) use ($cards) {
            if (str_contains($request->url(), '/cards/collection')) {
                $data = [];
                $notFound = [];
                foreach ($request['identifiers'] as $identifier) {
                    $match = collect($cards)->first(fn ($card) => ($identifier['id'] ?? null) === $card['id'] || ($identifier['name'] ?? null) === $card['name']);
                    $match ? $data[] = $match : $notFound[] = $identifier;
                }

                return Http::response(['data' => $data, 'not_found' => $notFound]);
            }
            foreach ($cards as $id => $card) {
                if (str_ends_with($request->url(), '/cards/'.$id)) return Http::response($card);
            }

            return Http::response(['object' => 'error'], 404);
        });
    }

    private function deckOf(User $user): Deck
    {
        return $user->ensurePlayerProfile()->decks()->create(['name' => 'Burn Foil', 'format' => 'Pauper']);
    }

    public function test_deck_keeps_foil_and_normal_copies_apart(): void
    {
        $this->fakeScryfall();
        $user = User::factory()->create();
        $deck = $this->deckOf($user);

        $this->actingAs($user)->post(route('decks.cards.store', $deck), ['scryfall_id' => self::BOLT, 'board' => 'main', 'foil' => 1])
            ->assertSessionHas('status', 'Lightning Bolt (foil) adicionada.');
        $this->actingAs($user)->post(route('decks.cards.store', $deck), ['scryfall_id' => self::BOLT, 'board' => 'main', 'quantity' => 2]);
        $this->actingAs($user)->post(route('decks.cards.store', $deck), ['scryfall_id' => self::BOLT, 'board' => 'main', 'foil' => 1]);

        $this->assertSame([[false, 2], [true, 2]], $deck->cards()->reorder()->orderBy('foil')->get()->map(fn ($c) => [$c->foil, $c->quantity])->all());
        $this->actingAs($user)->get(route('decks.show', $deck))->assertSee('<span class="foil-tag" title="Foil">foil</span>', false);

        // mover o foil para o sideboard leva o acabamento junto e não mistura com a cópia normal
        $foil = $deck->cards()->where('foil', true)->first();
        $this->actingAs($user)->patchJson(route('decks.cards.update', [$deck, $foil]), ['board' => 'side', 'move' => 2])->assertOk();
        $this->assertTrue($foil->fresh()->foil);
        $this->assertSame('side', $foil->fresh()->board);
        $this->assertSame(2, $deck->cards()->where('foil', false)->where('board', 'main')->value('quantity'));

        // lista do Moxfield com *F* importa como foil; o CSV sai com "foil"
        app(\App\Services\DeckBuilderService::class)->import($deck, "1 Counterspell (MH2) 267 *F*\n1 Counterspell", false);
        $this->assertSame([false, true], $deck->cards()->where('name', 'Counterspell')->reorder()->orderBy('foil')->pluck('foil')->all());
        $csv = $this->actingAs($user)->get(route('decks.export', $deck))->assertOk()->getContent();
        $this->assertStringContainsString('"Lightning Bolt",M10,"Magic 2010",146,foil,common,2', $csv);
        $this->assertStringContainsString('"Lightning Bolt",M10,"Magic 2010",146,normal,common,2', $csv);
    }

    public function test_collection_search_and_missing_cards_respect_foil(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckOf($user);
        $deck->cards()->create(['scryfall_id' => self::BOLT, 'name' => 'Lightning Bolt', 'board' => 'main', 'foil' => true, 'quantity' => 4, 'type_line' => 'Instant']);
        CollectionItem::create(['user_id' => $user->id, 'scryfall_id' => self::BOLT, 'name' => 'Lightning Bolt', 'quantity' => 4, 'foil' => false, 'condition' => 'NM']);
        CollectionItem::create(['user_id' => $user->id, 'scryfall_id' => self::BOLT, 'name' => 'Lightning Bolt', 'quantity' => 1, 'foil' => true, 'condition' => 'NM']);
        CollectionItem::create(['user_id' => $user->id, 'scryfall_id' => self::BOLT, 'name' => 'Lightning Bolt', 'quantity' => 1, 'foil' => true, 'condition' => 'LP']);

        // busca na coleção: normal e foil separados; condições somam
        $data = $this->actingAs($user)->getJson(route('collection.search', ['q' => 'bolt']))->json('data');
        $this->assertSame([[false, 4], [true, 2]], array_map(fn ($c) => [$c['foil'], $c['owned']], $data));

        // pelo nome o deck está completo; na edição exata faltam 2 foils
        $service = app(CollectionService::class);
        $this->assertSame(0, $service->missing($user, $deck, exact: false)[0]['missing']);
        $this->assertSame(2, $service->missing($user, $deck, exact: true)[0]['missing']);
    }

    public function test_collection_imports_manabox_csv_with_printing_foil_and_condition(): void
    {
        $this->fakeScryfall();
        $user = User::factory()->create();
        $csv = "\xEF\xBB\xBFBinder Name,Binder Type,Name,Set code,Set name,Collector number,Foil,Rarity,Quantity,ManaBox ID,Scryfall ID,Purchase price,Misprint,Altered,Condition,Language,Purchase price currency\n"
            ."Trade,binder,Lightning Bolt,M10,Magic 2010,146,foil,common,2,1,".self::BOLT.",0.5,false,false,excellent,en,USD\n"
            ."Trade,binder,Lightning Bolt,M10,Magic 2010,146,normal,common,3,2,".self::BOLT.",0.5,false,false,near_mint,en,USD\n"
            ."Trade,binder,Counterspell,MH2,Modern Horizons 2,267,normal,uncommon,1,3,,,false,false,played,en,\n"
            ."Trade,binder,Carta Inventada,XXX,Nada,1,normal,common,1,4,,,false,false,near_mint,en,\n";

        $this->actingAs($user)->post(route('collection.import.csv'), ['file' => UploadedFile::fake()->createWithContent('manabox.csv', $csv)])
            ->assertSessionHas('status', '6 cartas importadas do CSV.')
            ->assertSessionHasErrors(['file' => 'Não encontradas: Carta Inventada']);

        $items = CollectionItem::where('user_id', $user->id)->orderBy('name')->orderBy('foil')->get()->map(fn ($i) => [$i->name, $i->foil, $i->condition, $i->quantity])->all();
        $this->assertSame([['Counterspell', false, 'HP', 1], ['Lightning Bolt', false, 'NM', 3], ['Lightning Bolt', true, 'LP', 2]], $items);
        $this->assertSame(5.0, (float) CollectionItem::where('foil', true)->value('price_usd'));
    }

    public function test_csv_import_accepts_semicolons_and_rejects_files_without_name_column(): void
    {
        $this->fakeScryfall();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('collection.import.csv'), ['file' => UploadedFile::fake()->createWithContent('excel.csv', "Name;Quantity;Foil\nLightning Bolt;4;foil\n")])
            ->assertSessionHas('status', '4 cartas importadas do CSV.');
        $this->assertSame(4, CollectionItem::where('user_id', $user->id)->where('foil', true)->value('quantity'));

        $this->actingAs($user)->post(route('collection.import.csv'), ['file' => UploadedFile::fake()->createWithContent('outro.csv', "Card,Qty\nLightning Bolt,4\n")])
            ->assertSessionHasErrors(['file' => 'O arquivo não parece um CSV do ManaBox: falta a coluna "Name".']);
        $this->actingAs($user)->post(route('collection.import.csv'), ['file' => UploadedFile::fake()->create('foto.png', 10, 'image/png')])
            ->assertSessionHasErrors('file');
        $this->actingAs($user)->get(route('collection.index'))->assertSee('Importar CSV do ManaBox');
    }
}
