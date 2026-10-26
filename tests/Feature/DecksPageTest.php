<?php

namespace Tests\Feature;

use App\Models\CollectionItem;
use App\Models\GameMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DecksPageTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $user = User::factory()->create();
        $user->ensurePlayerProfile()->update(['nickname' => 'Ana']);

        return $user->fresh();
    }

    public function test_decks_page_lists_decks_with_stats_and_is_in_the_main_nav(): void
    {
        $user = $this->user();
        $profile = $user->playerProfile;
        $burn = $profile->decks()->create(['name' => 'Burn', 'format' => 'Pauper', 'is_primary' => true, 'is_visible_to_friends' => true]);
        $burn->cards()->create(['scryfall_id' => (string) Str::uuid(), 'name' => 'Lightning Bolt', 'quantity' => 4, 'board' => 'main']);
        $elves = $profile->decks()->create(['name' => 'Elfos', 'format' => 'Pauper', 'is_visible_to_friends' => false]);
        $elves->cards()->create(['scryfall_id' => (string) Str::uuid(), 'name' => 'Llanowar Elves', 'quantity' => 2, 'board' => 'main']);
        $profile->decks()->create(['name' => 'Atraxa', 'format' => 'Commander', 'is_visible_to_friends' => false]);

        $html = $this->actingAs($user)->get('/me/decks')->assertOk()->getContent();
        foreach (['Meus decks', 'Burn', 'Elfos', 'Atraxa', 'Decks cadastrados', '1 visível para amigos · 2 privados', 'Formato favorito', '2 decks', '★ Principal', 'Novo deck'] as $needle) {
            $this->assertStringContainsString($needle, $html, 'faltou: '.$needle);
        }
        // aba "Decks" no menu principal, marcada como atual
        $this->assertStringNotContainsString('Cartas nos decks', $html);
        $this->assertMatchesRegularExpression('#class="site-nav-link" href="'.preg_quote(route('decks.index'), '#').'"\s+aria-current="page"#', $html);

        $this->get('/me/decks?format=Commander')->assertSee('Atraxa')->assertDontSee('Elfos');
        $this->get('/me/decks?format=Draft')->assertSee('Nenhum deck de Draft.');
    }

    public function test_deck_tournament_record_and_collection_coverage(): void
    {
        $user = $this->user();
        $profile = $user->playerProfile;
        $deck = $profile->decks()->create(['name' => 'Burn', 'format' => 'Pauper']);
        $deck->cards()->create(['scryfall_id' => (string) Str::uuid(), 'name' => 'Lightning Bolt', 'quantity' => 4, 'board' => 'main']);

        $organizer = User::factory()->create();
        $tournament = $organizer->tournaments()->create([
            'name' => 'Liga', 'format' => 'Pauper', 'tournament_date' => '2026-10-10',
            'public_token' => Str::random(40), 'public_slug' => 'liga-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40),
        ]);
        $me = $tournament->players()->create(['name' => 'Ana', 'player_profile_id' => $profile->id, 'deck_id' => $deck->id, 'deck_name' => 'Burn']);
        $other = $tournament->players()->create(['name' => 'Oponente']);
        foreach ([[2, 0], [1, 2], [2, 1]] as $i => [$mine, $theirs]) {
            $tournament->matches()->create(['round' => $i + 1, 'stage' => GameMatch::STAGE_LEAGUE, 'player_one_id' => $me->id, 'player_two_id' => $other->id,
                'player_one_games' => $mine, 'player_two_games' => $theirs, 'status' => GameMatch::STATUS_FINISHED, 'played_at' => now()->addMinutes($i)]);
        }

        // sem coleção: o 4º indicador mostra o desempenho em torneios
        $this->actingAs($user)->get('/me/decks')->assertOk()->assertSee('Em torneios')->assertSee('2V – 1D')->assertSee('1 · 2V 1D');

        // com coleção: mostra quantos decks estão prontos e o % de cada um
        CollectionItem::create(['user_id' => $user->id, 'scryfall_id' => (string) Str::uuid(), 'name' => 'Lightning Bolt', 'quantity' => 4, 'condition' => 'NM']);
        $this->get('/me/decks')->assertSee('Prontos na coleção')->assertSee('1 de 1')->assertSee('100%');
    }

    public function test_profile_edit_no_longer_lists_decks_and_delete_returns_to_decks_page(): void
    {
        $user = $this->user();
        $profile = $user->playerProfile;
        $profile->decks()->create(['name' => 'Principal', 'is_primary' => true]);
        $extra = $profile->decks()->create(['name' => 'Rascunho Secreto']);

        $this->actingAs($user)->get('/me/profile/edit')->assertOk()->assertDontSee('Rascunho Secreto')->assertDontSee('Ir para Meus decks');
        $this->delete(route('decks.destroy', $extra))->assertRedirect(route('decks.index'));
    }

    public function test_decks_page_requires_login(): void
    {
        $this->get('/me/decks')->assertRedirect('/login');
    }
}
