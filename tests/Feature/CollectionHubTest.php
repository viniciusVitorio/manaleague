<?php

namespace Tests\Feature;

use App\Models\CollectionItem;
use App\Models\User;
use App\Services\ScryfallClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CollectionHubTest extends TestCase
{
    use RefreshDatabase;

    private function card(string $name, ?float $price = null): array
    {
        return ['scryfall_id' => (string) Str::uuid(), 'name' => $name, 'set_code' => 'TST', 'image_url' => null, 'type_line' => 'Instant', 'rarity' => 'common', 'price_usd' => $price, 'price_usd_foil' => $price ? $price * 2 : null];
    }

    private function user(string $name): User
    {
        $u = User::factory()->create(['name' => $name]);
        $u->ensurePlayerProfile()->update(['nickname' => $name]);

        return $u->fresh();
    }

    public function test_import_list_stats_value_and_export(): void
    {
        $bolt = $this->card('Lightning Bolt', 1.5);
        $ring = $this->card('Sol Ring', 2.0);
        $this->mock(ScryfallClient::class, fn ($m) => $m->shouldReceive('collection')->andReturn(['found' => ['lightning bolt' => $bolt, 'sol ring' => $ring], 'missing' => ['Carta Inventada']]));
        $u = $this->user('Ana');

        $this->actingAs($u)->post('/me/collection/import', ['list' => "4 Lightning Bolt\n1x Sol Ring (CMM) 400\n2 Carta Inventada", 'condition' => 'NM', 'foil' => 0])
            ->assertRedirect()->assertSessionHas('status', '5 cartas importadas.')->assertSessionHasErrors('list');
        $this->assertSame(5, (int) CollectionItem::sum('quantity'));
        $this->get('/me/collection')->assertOk()->assertSee('US$ 8,00')->assertSee('2 diferentes')->assertSee('Lightning Bolt');
        $this->get('/me/collection?q=sol')->assertSee('Sol Ring')->assertDontSee('Lightning Bolt');
        $this->get('/me/collection/export')->assertOk()->assertHeader('content-disposition', 'attachment; filename="colecao-manaleague.txt"')->assertSee('4 Lightning Bolt (TST)', false);
        $this->post('/me/collection/import', ['list' => '   ', 'condition' => 'NM'])->assertSessionHasErrors('list');
    }

    public function test_deck_coverage_wishlist_and_friend_trade_matches(): void
    {
        [$ana, $bia, $zeca] = [$this->user('Ana'), $this->user('Bia'), $this->user('Zeca')];
        $ana->friendProfiles()->attach($bia->playerProfile, ['status' => 'accepted']);
        $bia->friendProfiles()->attach($ana->playerProfile, ['status' => 'accepted']);
        $deck = $ana->playerProfile->decks()->create(['name' => 'Burn', 'format' => 'Pauper']);
        foreach ([['Lightning Bolt', 4], ['Mountain', 10], ['Chain Lightning', 4]] as [$n, $q]) {
            $deck->cards()->create(['scryfall_id' => (string) Str::uuid(), 'name' => $n, 'quantity' => $q, 'board' => 'main']);
        }
        CollectionItem::create(['user_id' => $ana->id, 'scryfall_id' => (string) Str::uuid(), 'name' => 'Lightning Bolt', 'quantity' => 4, 'condition' => 'NM']);
        CollectionItem::create(['user_id' => $ana->id, 'scryfall_id' => (string) Str::uuid(), 'name' => 'Mountain', 'quantity' => 6, 'condition' => 'NM']);
        CollectionItem::create(['user_id' => $bia->id, 'scryfall_id' => (string) Str::uuid(), 'name' => 'Chain Lightning', 'set_code' => 'BBD', 'quantity' => 3, 'for_trade' => 2, 'condition' => 'LP']);
        CollectionItem::create(['user_id' => $zeca->id, 'scryfall_id' => (string) Str::uuid(), 'name' => 'Chain Lightning', 'quantity' => 4, 'for_trade' => 4, 'condition' => 'NM']);

        $page = $this->actingAs($ana)->get('/me/collection')->assertOk();
        $page->assertSee('Burn')->assertSee('55%')->assertSee('faltam 8 cartas')   // 10 de 18
            ->assertSee('4× Chain Lightning')->assertDontSee('4× Mountain')          // básicos fora da lista de desejos
            ->assertSee('Amigos têm pra troca')->assertSee('2× BBD')->assertDontSee('Zeca'); // só amigos

        // fichário de trocas no perfil: amigo vê, estranho não
        $this->actingAs($ana)->get('/players/'.$bia->playerProfile->id)->assertOk()->assertSee('Para troca')->assertSee('Chain Lightning');
        $item = CollectionItem::where('user_id', $ana->id)->where('name', 'Mountain')->first();
        $this->actingAs($ana)->patch('/me/collection/'.$item->id, ['quantity' => 6, 'for_trade' => 99])->assertRedirect();
        $this->assertSame(6, $item->fresh()->for_trade); // nunca mais que a quantidade
        $this->actingAs($zeca)->patch('/me/collection/'.$item->id, ['quantity' => 1])->assertNotFound();
    }

    private function item(User $user, string $name, array $attrs = []): CollectionItem
    {
        return CollectionItem::create($attrs + ['user_id' => $user->id, 'scryfall_id' => (string) Str::uuid(), 'name' => $name, 'quantity' => 1, 'condition' => 'NM']);
    }

    public function test_filters_by_color_type_rarity_and_price_and_list_view(): void
    {
        $u = $this->user('Ana');
        $this->item($u, 'Lightning Bolt', ['color_identity' => 'R', 'type_line' => 'Instant', 'rarity' => 'common', 'price_usd' => 1.5]);
        $this->item($u, 'Goblin Guide', ['color_identity' => 'R', 'type_line' => 'Creature — Goblin Scout', 'rarity' => 'rare', 'price_usd' => 4]);
        $this->item($u, 'Sol Ring', ['color_identity' => 'C', 'type_line' => 'Artifact', 'rarity' => 'uncommon', 'price_usd' => 2]);
        $this->item($u, 'Lightning Helix', ['color_identity' => 'RW', 'type_line' => 'Instant', 'rarity' => 'uncommon', 'price_usd' => 0.5]);
        $this->actingAs($u);

        $this->get('/me/collection?color=R')->assertSee('Goblin Guide')->assertSee('Lightning Helix')->assertDontSee('Sol Ring');
        $this->get('/me/collection?color=C')->assertSee('Sol Ring')->assertDontSee('Goblin Guide');
        $this->get('/me/collection?color=M')->assertSee('Lightning Helix')->assertDontSee('Lightning Bolt');
        $this->get('/me/collection?type=Creature')->assertSee('Goblin Guide')->assertDontSee('Lightning Bolt');
        $this->get('/me/collection?rarity=uncommon')->assertSee('Sol Ring')->assertSee('Lightning Helix')->assertDontSee('Goblin Guide');
        $this->get('/me/collection?min=1&max=3')->assertSee('Lightning Bolt')->assertSee('Sol Ring')->assertDontSee('Goblin Guide')->assertDontSee('Lightning Helix');
        $this->get('/me/collection?color=X')->assertSessionHasErrors('color');
        $this->get('/me/collection?view=list')->assertOk()->assertSee('binder-table', false)->assertSee('Goblin Guide');
    }

    public function test_plus_minus_shortcut_and_removal_at_zero(): void
    {
        [$ana, $bia] = [$this->user('Ana'), $this->user('Bia')];
        $item = $this->item($ana, 'Sol Ring', ['quantity' => 2, 'for_trade' => 2]);

        $this->actingAs($ana)->patch('/me/collection/'.$item->id, ['delta' => 1])->assertRedirect(route('collection.index').'#item-'.$item->id);
        $this->assertSame(3, $item->fresh()->quantity);
        $this->patch('/me/collection/'.$item->id, ['delta' => -1]);
        $this->patch('/me/collection/'.$item->id, ['delta' => -1]);
        $this->assertSame(1, $item->fresh()->quantity);
        $this->assertSame(1, $item->fresh()->for_trade); // troca acompanha a quantidade
        $this->patch('/me/collection/'.$item->id, ['delta' => 5])->assertSessionHasErrors('delta');
        $this->actingAs($bia)->patch('/me/collection/'.$item->id, ['delta' => -1])->assertNotFound();
        $this->actingAs($ana)->patch('/me/collection/'.$item->id, ['delta' => -1])->assertSessionHas('status', 'Sol Ring removida da coleção.');
        $this->assertNull($item->fresh());
    }

    public function test_missing_page_shopping_list_and_add_missing_to_collection(): void
    {
        [$ana, $bia] = [$this->user('Ana'), $this->user('Bia')];
        $ana->friendProfiles()->attach($bia->playerProfile, ['status' => 'accepted']);
        $bia->friendProfiles()->attach($ana->playerProfile, ['status' => 'accepted']);
        $deck = $ana->playerProfile->decks()->create(['name' => 'Burn', 'format' => 'Pauper', 'is_visible_to_friends' => true]);
        $deck->cards()->create(['scryfall_id' => (string) Str::uuid(), 'name' => 'Lightning Bolt', 'quantity' => 4, 'board' => 'main', 'set_code' => 'M11', 'color_identity' => 'R', 'type_line' => 'Instant', 'rarity' => 'common']);
        $deck->cards()->create(['scryfall_id' => (string) Str::uuid(), 'name' => 'Sol Ring', 'quantity' => 1, 'board' => 'main']);
        $this->item($ana, 'Lightning Bolt', ['quantity' => 1]);

        $this->actingAs($ana)->get(route('collection.missing', $deck))->assertOk()
            ->assertSee("3 Lightning Bolt\n1 Sol Ring", false)->assertSee('Adicionar faltantes à coleção');
        $this->post(route('collection.missing.add', $deck), ['condition' => 'LP', 'foil' => 1])
            ->assertRedirect(route('collection.missing', $deck))->assertSessionHas('status', '4 cartas adicionadas à coleção. O deck está completo.');
        $this->assertSame(4, (int) CollectionItem::where('user_id', $ana->id)->where('name', 'Lightning Bolt')->sum('quantity'));
        $new = CollectionItem::where('user_id', $ana->id)->where('name', 'Lightning Bolt')->where('condition', 'LP')->first();
        $this->assertTrue($new->foil);
        $this->assertSame('R', $new->color_identity);
        $this->assertSame('C', CollectionItem::where('name', 'Sol Ring')->first()->color_identity);
        $this->post(route('collection.missing.add', $deck), ['condition' => 'NM'])->assertSessionHas('status', 'Nada faltando: o deck já está completo.');

        // amigo vê os faltantes, mas não pode jogar cartas na própria coleção a partir do deck alheio
        $this->actingAs($bia)->get(route('collection.missing', $deck))->assertOk()->assertDontSee('Adicionar faltantes à coleção');
        $this->post(route('collection.missing.add', $deck), ['condition' => 'NM'])->assertNotFound();
    }

    public function test_refresh_command_fills_missing_colors_from_scryfall(): void
    {
        $u = $this->user('Ana');
        $item = $this->item($u, 'Sol Ring');
        $this->mock(ScryfallClient::class, fn ($m) => $m->shouldReceive('byIds')->once()->andReturn([
            strtolower($item->scryfall_id) => ['scryfall_id' => $item->scryfall_id, 'name' => 'Sol Ring', 'color_identity' => null, 'type_line' => 'Artifact', 'rarity' => 'uncommon', 'image_url' => null],
        ]));

        $this->artisan('collection:refresh')->assertSuccessful();
        $this->assertSame(['C', 'Artifact', 'uncommon'], [$item->fresh()->color_identity, $item->fresh()->type_line, $item->fresh()->rarity]);
    }
}
