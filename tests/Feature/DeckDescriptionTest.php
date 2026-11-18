<?php

namespace Tests\Feature;

use App\Models\Deck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Descrição curta do deck: o dono escreve, aparece no topo para quem pode ver o deck e ninguém mais altera. */
class DeckDescriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_writes_a_description_that_shows_in_the_deck_header(): void
    {
        [$owner, $deck] = $this->deck();

        $this->actingAs($owner)->get(route('decks.show', $deck))->assertOk()
            ->assertSee('Adicionar descrição')->assertDontSee('class="deck-hero-about"', false);

        $this->actingAs($owner)->patch(route('decks.description', $deck), ['description' => 'Aggro de goblins.'])
            ->assertRedirect()->assertSessionHas('status', 'Descrição salva.');

        // Logo abaixo das informações do deck, ainda dentro do topo.
        $this->actingAs($owner)->get(route('decks.show', $deck))->assertOk()
            ->assertSeeInOrder(['class="deck-hero-meta"', 'class="deck-hero-about">Aggro de goblins.</p>', 'class="deck-edit"', 'class="deck-layout"'], false)
            ->assertSee('Editar descrição')->assertDontSee('Adicionar descrição');
    }

    public function test_text_becomes_a_single_line_and_blank_text_removes_it(): void
    {
        [$owner, $deck] = $this->deck();

        $this->actingAs($owner)->patch(route('decks.description', $deck), ['description' => "  Plano A\r\n\r\n  Plano   B  "]);
        $this->assertSame('Plano A Plano B', $deck->fresh()->description);

        $this->actingAs($owner)->patch(route('decks.description', $deck), ['description' => "  \r\n "])
            ->assertSessionHas('status', 'Descrição removida.');
        $this->assertNull($deck->fresh()->description);
    }

    public function test_limit_applies_to_the_cleaned_text_and_rejects_longer_text(): void
    {
        [$owner, $deck] = $this->deck();

        // Espaços e quebras que somem na limpeza não contam para o limite.
        $atLimit = str_repeat('a', 39)."\r\n\r\n".str_repeat('b', 40);
        $this->actingAs($owner)->patch(route('decks.description', $deck), ['description' => $atLimit])->assertSessionHasNoErrors();
        $this->assertSame(Deck::DESCRIPTION_MAX, mb_strlen($deck->fresh()->description));

        $this->actingAs($owner)->patch(route('decks.description', $deck), ['description' => str_repeat('c', Deck::DESCRIPTION_MAX + 1)])
            ->assertSessionHasErrors('description');
        $this->actingAs($owner)->patch(route('decks.description', $deck), ['description' => ['lista']])->assertSessionHasErrors('description');
        $this->assertStringStartsWith('aaa', $deck->fresh()->description);
    }

    public function test_friends_and_public_link_visitors_read_it_without_edit_controls(): void
    {
        [, $deck] = $this->deck('Barato. <script>alert(1)</script>');
        $friend = $this->friendOf($deck);

        $this->actingAs($friend)->get(route('decks.show', $deck))->assertOk()
            ->assertSee('Barato.')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('Editar descrição')->assertDontSee('id="deck-description"', false);

        $token = $deck->enableShareLink();
        auth()->logout();
        $this->get(route('decks.public', $token))->assertOk()
            ->assertSee('Barato.')->assertDontSee('Editar descrição')->assertDontSee('Adicionar descrição');
    }

    public function test_visitors_see_nothing_when_there_is_no_description(): void
    {
        [, $deck] = $this->deck();

        $this->actingAs($this->friendOf($deck))->get(route('decks.show', $deck))->assertOk()
            ->assertDontSee('class="deck-hero-about"', false)->assertDontSee('Adicionar descrição');
    }

    public function test_hidden_deck_keeps_its_description_away_from_others(): void
    {
        [, $deck] = $this->deck('Plano secreto');
        $deck->update(['is_visible_to_friends' => false]);

        $this->actingAs($this->friendOf($deck))->get(route('decks.show', $deck))->assertNotFound();

        $stranger = User::factory()->create();
        $stranger->ensurePlayerProfile();
        $this->actingAs($stranger)->get(route('decks.show', $deck))->assertForbidden();
    }

    public function test_only_the_owner_can_change_it(): void
    {
        [, $deck] = $this->deck('Original');

        $this->actingAs($this->friendOf($deck))->patch(route('decks.description', $deck), ['description' => 'Invadido'])->assertForbidden();
        $this->assertSame('Original', $deck->fresh()->description);
    }

    public function test_editing_the_deck_settings_keeps_the_description(): void
    {
        [$owner, $deck] = $this->deck('Original');

        $this->actingAs($owner)->patch(route('decks.update', $deck), ['name' => 'Novo nome', 'format' => 'Pauper', 'is_visible_to_friends' => 1]);

        $this->assertSame('Novo nome', $deck->fresh()->name);
        $this->assertSame('Original', $deck->fresh()->description);
    }

    private function deck(?string $description = null): array
    {
        $owner = User::factory()->create();
        $deck = $owner->ensurePlayerProfile()->decks()->create(['name' => 'Teste', 'format' => 'Pauper', 'description' => $description, 'is_visible_to_friends' => true]);

        return [$owner, $deck];
    }

    private function friendOf(Deck $deck): User
    {
        $friend = User::factory()->create();
        $friend->ensurePlayerProfile();
        $friend->friendProfiles()->attach($deck->profile, ['status' => 'accepted']);

        return $friend;
    }
}
