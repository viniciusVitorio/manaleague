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
}
