<?php

namespace Tests\Feature;

use App\Models\Deck;
use App\Models\GameMatch;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeckAndTournamentToolsTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $nickname): User
    {
        $user = User::factory()->create(['name' => $nickname]);
        $user->ensurePlayerProfile()->update(['nickname' => $nickname]);

        return $user->fresh();
    }

    private function befriend(User $a, User $b): void
    {
        $a->friendProfiles()->attach($b->playerProfile, ['status' => 'accepted']);
        $b->friendProfiles()->attach($a->playerProfile, ['status' => 'accepted']);
    }

    private function card(Deck $deck, string $name, int $quantity, string $board = 'main', string $type = 'Instant')
    {
        return $deck->cards()->create(['scryfall_id' => (string) Str::uuid(), 'name' => $name, 'quantity' => $quantity, 'board' => $board,
            'type_line' => $type, 'cmc' => 1, 'mana_cost' => '{R}', 'color_identity' => 'R', 'set_code' => 'm10']);
    }

    private function tournament(User $owner, array $attributes = []): Tournament
    {
        return $owner->tournaments()->create($attributes + ['name' => 'Copa', 'format' => 'Pauper', 'tournament_date' => '2026-11-01', 'status' => 'setup',
            'public_token' => Str::random(40), 'public_slug' => 'copa-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40)]);
    }

    public function test_deck_shows_when_the_card_list_last_changed(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $ana = $this->account('Ana');
        $deck = $ana->playerProfile->decks()->create(['name' => 'Burn', 'format' => 'Pauper', 'is_visible_to_friends' => true]);
        $this->assertNull($deck->fresh()->cards_updated_at);

        $bolt = $this->card($deck, 'Lightning Bolt', 4);
        $this->assertSame('2026-10-01 10:00:00', $deck->fresh()->cards_updated_at->toDateTimeString());

        // mudar nome, capa ou principal não conta como mudança na lista
        $this->travelTo('2026-10-05 10:00:00');
        $this->actingAs($ana)->patch(route('decks.update', $deck), ['name' => 'Burn de Pauper', 'format' => 'Pauper', 'is_visible_to_friends' => 1])->assertRedirect();
        $this->assertSame('2026-10-01 10:00:00', $deck->fresh()->cards_updated_at->toDateTimeString());

        $this->travelTo('2026-10-10 10:00:00');
        $this->actingAs($ana)->patch(route('decks.cards.update', [$deck, $bolt]), ['delta' => -1]);
        $this->assertSame('2026-10-10 10:00:00', $deck->fresh()->cards_updated_at->toDateTimeString());

        // mover parte das cópias para o sideboard também conta
        $this->travelTo('2026-10-12 10:00:00');
        $this->actingAs($ana)->patchJson(route('decks.cards.update', [$deck, $bolt]), ['board' => 'side', 'move' => 1])->assertOk();
        $this->assertSame('2026-10-12 10:00:00', $deck->fresh()->cards_updated_at->toDateTimeString());

        $this->travelTo('2026-10-15 10:00:00');
        $this->actingAs($ana)->get(route('decks.show', $deck))->assertSee('Lista atualizada')->assertSee('há 3 dias');
        $this->actingAs($ana)->get(route('decks.index'))->assertSee('atualizada há 3 dias');

        $this->actingAs($ana)->delete(route('decks.cards.destroy', [$deck, $bolt]));
        $this->assertSame('2026-10-15 10:00:00', $deck->fresh()->cards_updated_at->toDateTimeString());
    }

    public function test_deck_list_exports_for_whoever_can_see_the_deck(): void
    {
        $ana = $this->account('Ana');
        $friend = $this->account('Bia');
        $stranger = $this->account('Zeca');
        $this->befriend($ana, $friend);
        $deck = $ana->playerProfile->decks()->create(['name' => 'Burn Ruim!', 'format' => 'Pauper', 'is_visible_to_friends' => true]);
        $this->card($deck, 'Lightning Bolt', 4);
        $this->card($deck, 'Mountain', 20, 'main', 'Basic Land — Mountain');
        $this->card($deck, 'Smash to Smithereens', 2, 'side');
        $expected = "Deck\n4 Lightning Bolt\n20 Mountain\n\nSideboard\n2 Smash to Smithereens\n";
        Http::fake(['api.scryfall.com/*' => Http::response(['data' => []])]);

        $this->actingAs($ana)->get(route('decks.show', $deck))->assertSee('Exportar lista')->assertSee('data-copy-target="#deck-export-text"', false)
            ->assertSee('Baixar .csv')->assertSee($expected);
        $this->assertSame($expected, app(\App\Services\DeckBuilderService::class)->exportText($deck));
        $this->actingAs($stranger)->get(route('decks.export', $deck))->assertForbidden();

        // a lista exportada volta igual pelo importador
        $parsed = app(\App\Services\DeckBuilderService::class)->parse($expected);
        $this->assertSame([['Lightning Bolt', 4, 'main'], ['Mountain', 20, 'main'], ['Smash to Smithereens', 2, 'side']],
            array_map(fn ($line) => [$line['name'], $line['quantity'], $line['board']], $parsed));

        // deck privado: só a dona
        $deck->update(['is_visible_to_friends' => false]);
        $this->actingAs($friend)->get(route('decks.export', $deck))->assertForbidden();
        $this->actingAs($ana)->get(route('decks.export', $deck))->assertOk();

        $commander = $ana->playerProfile->decks()->create(['name' => 'Krenko', 'format' => 'Commander']);
        $this->card($commander, 'Krenko, Mob Boss', 1, 'commander', 'Legendary Creature — Goblin');
        $this->card($commander, 'Sol Ring', 1);
        $this->assertSame("Commander\n1 Krenko, Mob Boss\n\nDeck\n1 Sol Ring\n", app(\App\Services\DeckBuilderService::class)->exportText($commander));
    }

    public function test_deck_builder_searches_the_owner_collection(): void
    {
        $ana = $this->account('Ana');
        $bia = $this->account('Bia');
        $deck = $ana->playerProfile->decks()->create(['name' => 'Burn', 'format' => 'Pauper']);
        $boltId = (string) Str::uuid();
        $item = fn (User $user, string $id, string $name, int $qty, bool $foil = false) => \App\Models\CollectionItem::create(
            ['user_id' => $user->id, 'scryfall_id' => $id, 'name' => $name, 'set_code' => 'M10', 'type_line' => 'Instant', 'quantity' => $qty, 'foil' => $foil, 'condition' => 'NM']);

        $this->get(route('collection.search', ['q' => 'light']))->assertRedirect(); // visitante sem login

        // sem coleção: só a busca no Scryfall, sem os botões de escolher
        $this->actingAs($ana)->get(route('decks.show', $deck))->assertSee('Buscar cartas')->assertDontSee('Minha coleção');

        $item($ana, $boltId, 'Lightning Bolt', 3);
        $item($ana, $boltId, 'Lightning Bolt', 1, true); // mesma edição em foil: resultado separado
        $item($ana, (string) Str::uuid(), 'Chain Lightning', 2);
        $item($ana, (string) Str::uuid(), 'Counterspell', 4);
        $item($bia, (string) Str::uuid(), 'Lightning Axe', 4); // coleção de outra pessoa não aparece

        $this->actingAs($ana)->get(route('decks.show', $deck))->assertSee('Minha coleção')
            ->assertSee('data-search-source="'.route('collection.search').'"', false);

        $data = $this->actingAs($ana)->getJson(route('collection.search', ['q' => 'light']))->assertOk()->json('data');
        $this->assertSame(['Lightning Bolt', 'Lightning Bolt', 'Chain Lightning'], array_column($data, 'name'));
        $this->assertSame([[false, 3], [true, 1]], [[$data[0]['foil'], $data[0]['owned']], [$data[1]['foil'], $data[1]['owned']]]);
        $this->assertSame($boltId, $data[0]['scryfall_id']);

        $this->actingAs($ana)->getJson(route('collection.search', ['q' => 'x']))->assertUnprocessable();
    }

    public function test_deck_downloads_as_manabox_csv(): void
    {
        $ana = $this->account('Ana');
        $deck = $ana->playerProfile->decks()->create(['name' => 'Krenko Tokens', 'format' => 'Commander']);
        $krenko = $this->card($deck, 'Krenko, Mob Boss', 1, 'commander', 'Legendary Creature — Goblin');
        $krenko->update(['rarity' => 'rare']);
        $bolt = $this->card($deck, 'Lightning Bolt', 1);
        $smash = $this->card($deck, 'Smash to Smithereens', 1, 'side');

        // O Scryfall devolve só duas das três cartas: a terceira sai sem coleção/número.
        Http::fake(['api.scryfall.com/cards/collection' => Http::response(['data' => [
            ['id' => $krenko->scryfall_id, 'name' => 'Krenko, Mob Boss', 'set' => 'm13', 'set_name' => 'Magic 2013', 'collector_number' => '139', 'lang' => 'en', 'rarity' => 'rare'],
            ['id' => $bolt->scryfall_id, 'name' => 'Lightning Bolt', 'set' => 'm10', 'set_name' => 'Magic 2010', 'collector_number' => '146', 'lang' => 'pt', 'rarity' => 'common'],
        ]])]);

        $response = $this->actingAs($ana)->get(route('decks.export', $deck))->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="krenko-tokens.csv"');
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));

        $rows = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\n", trim($response->getContent())));
        $this->assertSame(\App\Services\DeckBuilderService::CSV_COLUMNS, $rows[0]);
        $this->assertSame(['Krenko, Mob Boss', 'M13', 'Magic 2013', '139', 'normal', 'rare', '1', '', $krenko->scryfall_id, '', 'false', 'false', 'near_mint', 'en', '', 'Commander'], $rows[1]);
        $this->assertSame(['Lightning Bolt', 'M10', 'Magic 2010', '146', 'normal', 'common', '1', '', $bolt->scryfall_id, '', 'false', 'false', 'near_mint', 'pt', '', 'Deck'], $rows[2]);
        $this->assertSame(['Smash to Smithereens', 'M10', '', '', 'normal', '', '1', '', $smash->scryfall_id, '', 'false', 'false', 'near_mint', 'en', '', 'Sideboard'], $rows[3]);
        $this->assertCount(4, $rows);
    }

    public function test_organizer_repeats_a_tournament_with_the_same_rules_and_players(): void
    {
        $this->travelTo('2026-10-29 12:00:00');
        $owner = $this->account('Org');
        $ana = $this->account('Ana');
        $deck = $ana->playerProfile->decks()->create(['name' => 'Burn', 'format' => 'Pauper']);
        $old = $this->tournament($owner, ['name' => 'Liga de Quinta #4', 'status' => 'finished', 'system' => 'swiss', 'round_limit' => 3, 'max_players' => 16]);
        $old->forceFill(['round_minutes' => 40])->save();
        $old->players()->create(['name' => 'Ana', 'player_profile_id' => $ana->playerProfile->id, 'deck_id' => $deck->id, 'deck_name' => 'Burn', 'deck_colors' => 'R']);
        $old->players()->create(['name' => 'Convidado'])->forceFill(['checked_in_at' => now()])->save();

        $this->actingAs($owner)->get(route('tournaments.show', $old))->assertOk()->assertSee('Repetir torneio')
            ->assertSee('value="Liga de Quinta #5"', false)->assertSee('value="2026-11-08"', false)->assertSee('Trazer os mesmos 2 jogadores');
        $this->actingAs($ana)->post(route('tournaments.repeat', $old), ['name' => 'X', 'tournament_date' => '2026-11-08', 'copy_players' => 1])->assertForbidden();

        $this->actingAs($owner)->post(route('tournaments.repeat', $old), ['name' => 'Liga de Quinta #5', 'tournament_date' => '2026-11-08', 'copy_players' => 1])
            ->assertSessionHas('status', 'Torneio criado a partir de "Liga de Quinta #4" com 2 jogadores. Confira a lista antes de começar.');
        $new = Tournament::query()->latest('id')->first();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame(['Liga de Quinta #5', 'setup', 'Pauper', 'swiss', 3, 16, 40, '2026-11-08'],
            [$new->name, $new->status, $new->format, $new->system, $new->round_limit, $new->max_players, $new->round_minutes, $new->tournament_date->toDateString()]);
        $this->assertNotSame($old->public_slug, $new->public_slug);
        $this->assertNotSame($old->invite_token, $new->invite_token);
        $copiedAna = $new->players()->where('name', 'Ana')->first();
        $this->assertSame([$ana->playerProfile->id, $deck->id, 'Burn', 'R'], [$copiedAna->player_profile_id, $copiedAna->deck_id, $copiedAna->deck_name, $copiedAna->deck_colors]);
        $this->assertSame(0, $new->players()->whereNotNull('checked_in_at')->count());
        $this->assertSame(2, $old->players()->count());

        // sem os jogadores; um torneio antigo sugere a próxima data a partir de hoje
        $this->actingAs($owner)->post(route('tournaments.repeat', $old), ['name' => 'Avulso', 'tournament_date' => '2026-11-08', 'copy_players' => 0]);
        $this->assertSame(0, Tournament::query()->where('name', 'Avulso')->first()->players()->count());
        $ancient = $this->tournament($owner, ['tournament_date' => '2026-09-03', 'status' => 'finished']); // quinta-feira
        $this->assertSame(['Copa', '2026-10-29'], array_values($ancient->nextEditionDefaults()));
    }
}
