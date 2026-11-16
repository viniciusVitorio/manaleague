<?php

namespace Tests\Feature;

use App\Models\Deck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** ML-023: o dono compartilha o deck por link; qualquer pessoa com o link vê a lista, mesmo sem conta. */
class DeckShareLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.scryfall.com/*' => Http::response(['data' => []])]);
    }

    public function test_owner_creates_a_link_that_anyone_can_open_without_an_account(): void
    {
        $ana = $this->account('Ana');
        // Deck privado (não aparece para amigos): o link é uma escolha à parte do dono.
        $deck = $this->deck($ana, ['is_visible_to_friends' => false]);

        $this->actingAs($ana)->get(route('decks.show', $deck))->assertOk()
            ->assertSee('Link do deck')->assertSee('Criar link')->assertDontSee('deck-share-url', false);
        // Volta para o deck com a janela do link aberta.
        $this->post(route('decks.share', $deck))->assertRedirect(route('decks.show', $deck))->assertSessionHas('deck_dialog', 'share');

        $deck->refresh();
        $url = $deck->shareUrl();
        $this->assertMatchesRegularExpression('#/d/[a-f0-9]{40}$#', $url);
        // No banco: o token cifrado (não o texto do link) e o hash usado na busca.
        $raw = DB::table('decks')->where('id', $deck->id)->first();
        $this->assertStringNotContainsString(basename($url), (string) $raw->share_token);
        $this->assertSame(hash('sha256', basename($url)), $raw->share_token_hash);
        // O dono vê o link de novo, com copiar, trocar e desativar; e a tag na aba Decks.
        $this->get(route('decks.show', $deck))->assertSee('value="'.$url.'"', false)->assertSee('Copiar link')
            ->assertSee('Gerar novo link')->assertSee('Desativar')->assertSee('data-open-on-load', false);
        $this->get(route('decks.index'))->assertSee('Tem link de compartilhamento ativo');

        // Sem conta: a lista, o dono, a curva e a lista para copiar; nada de edição nem de coleção.
        auth()->logout();
        $page = $this->get($url)->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSeeInOrder(['Deck compartilhado', 'Burn', 'por', 'Ana', 'Lightning Bolt', 'Curva de mana'])
            ->assertSee('Copiar lista')->assertSee('Crie sua conta')
            ->assertSee('<meta property="og:title" content="🃏 Burn">', false)
            ->assertDontSee('Editar deck')->assertDontSee('Adicionar cartas')->assertDontSee('Baixar .csv')
            ->assertDontSee('Link do deck')->assertDontSee(route('decks.cards.update', [$deck, $deck->cards()->first()]), false);
        // O nome do dono só vira link quando quem vê pode abrir o perfil.
        $page->assertDontSee('href="'.route('profiles.show', $ana->playerProfile).'"', false);
    }

    public function test_a_new_link_revokes_the_old_one_and_disabling_takes_it_down(): void
    {
        $ana = $this->account('Ana');
        $deck = $this->deck($ana);
        $old = $deck->enableShareLink();

        $this->actingAs($ana)->post(route('decks.share', $deck))->assertSessionHas('status', 'Link novo criado. O link anterior parou de funcionar.');
        $new = $deck->fresh()->share_token;
        $this->assertNotSame($old, $new);
        $this->get(route('decks.public', $old))->assertNotFound();
        $this->get(route('decks.public', $new))->assertOk();

        $this->delete(route('decks.unshare', $deck))->assertRedirect();
        $this->assertNull($deck->fresh()->share_token);
        $this->get(route('decks.public', $new))->assertNotFound();
        // Link que nunca existiu ou fora do formato.
        $this->get('/d/'.str_repeat('a', 40))->assertNotFound();
        $this->get('/d/nao-e-um-token')->assertNotFound();
    }

    public function test_only_the_owner_manages_the_link_and_it_never_leaks_in_json(): void
    {
        $ana = $this->account('Ana');
        $bia = $this->account('Bia');
        $deck = $this->deck($ana);

        $this->actingAs($bia)->post(route('decks.share', $deck))->assertForbidden();
        $this->actingAs($bia)->delete(route('decks.unshare', $deck))->assertForbidden();
        $this->assertNull($deck->fresh()->share_token);

        $token = $deck->enableShareLink();
        $this->assertStringNotContainsString($token, $deck->fresh()->toJson());
        $this->assertArrayNotHasKey('share_token_hash', $deck->fresh()->toArray());
    }

    private function account(string $nickname): User
    {
        $user = User::factory()->create(['name' => $nickname]);
        $user->ensurePlayerProfile()->update(['nickname' => $nickname]);

        return $user->fresh();
    }

    private function deck(User $owner, array $attributes = []): Deck
    {
        $deck = $owner->playerProfile->decks()->create($attributes + ['name' => 'Burn', 'format' => 'Pauper', 'is_visible_to_friends' => true]);
        $deck->cards()->create(['scryfall_id' => (string) Str::uuid(), 'name' => 'Lightning Bolt', 'quantity' => 4, 'board' => 'main',
            'type_line' => 'Instant', 'cmc' => 1, 'mana_cost' => '{R}', 'color_identity' => 'R', 'set_code' => 'm10']);

        return $deck;
    }
}
