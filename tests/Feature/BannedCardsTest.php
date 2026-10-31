<?php

namespace Tests\Feature;

use App\Models\CardBan;
use App\Models\Deck;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BannedCardsTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $nickname): User
    {
        $user = User::factory()->create(['name' => $nickname]);
        $user->ensurePlayerProfile()->update(['nickname' => $nickname]);

        return $user->fresh();
    }

    private function deck(User $user, string $name, array $cards, string $format = 'Pauper', bool $primary = false): Deck
    {
        $deck = $user->playerProfile->decks()->create(['name' => $name, 'format' => $format, 'is_primary' => $primary, 'is_visible_to_friends' => true]);
        foreach ($cards as $card) {
            $deck->cards()->create(['scryfall_id' => (string) Str::uuid(), 'name' => $card, 'quantity' => 4, 'board' => 'main',
                'type_line' => 'Instant', 'cmc' => 1, 'mana_cost' => '{U}', 'color_identity' => 'U', 'set_code' => 'mmq']);
        }

        return $deck;
    }

    private function tournament(User $owner, array $attributes = []): Tournament
    {
        return $owner->tournaments()->create($attributes + ['name' => 'Copa', 'format' => 'Pauper', 'tournament_date' => '2026-11-01', 'status' => 'setup',
            'public_token' => Str::random(40), 'public_slug' => 'copa-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40)]);
    }

    private function ban(string $format, string ...$names): void
    {
        foreach ($names as $name) CardBan::create(['format' => $format, 'card_name' => $name]);
    }

    public function test_ban_list_syncs_from_scryfall_and_keeps_the_old_list_when_it_fails(): void
    {
        $this->ban('Pauper', 'Carta Antiga');
        $this->ban('Modern', 'Continua Banida');
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return match (true) {
                ($query['q'] ?? '') === 'banned:pauper' && ($query['page'] ?? '1') === '1' => Http::response(['data' => [['name' => 'Gush'], ['name' => 'High Tide']], 'has_more' => true, 'next_page' => 'https://api.scryfall.com/cards/search?page=2&q=banned%3Apauper']),
                ($query['q'] ?? '') === 'banned:pauper' => Http::response(['data' => [['name' => 'Peregrine Drake']], 'has_more' => false]),
                ($query['q'] ?? '') === 'banned:modern' => Http::response(['object' => 'error'], 500),
                default => Http::response(['object' => 'error', 'code' => 'not_found'], 404),
            };
        });

        $this->artisan('cards:sync-bans')->assertExitCode(1);

        $this->assertSame(['Gush', 'High Tide', 'Peregrine Drake'], CardBan::where('format', 'Pauper')->orderBy('card_name')->pluck('card_name')->all());
        $this->assertSame(['Continua Banida'], CardBan::where('format', 'Modern')->pluck('card_name')->all());
        $this->assertSame(0, CardBan::where('format', 'Standard')->count());
    }

    public function test_deck_page_tags_cards_banned_in_the_deck_format(): void
    {
        $this->ban('Pauper', 'Gush');
        $ana = $this->account('Ana');
        $pauper = $this->deck($ana, 'Faeries', ['Gush', 'Counterspell']);
        $modern = $this->deck($ana, 'Faeries Modern', ['Gush', 'Counterspell'], 'Modern');
        $tag = '<span class="ban-tag" title="Banida no Pauper">Banido</span>';

        $this->actingAs($ana)->get(route('decks.show', $pauper))->assertOk()
            ->assertSee('<strong>1 carta banida no Pauper.</strong>', false)
            ->assertSee($tag, false)
            ->assertSeeInOrder(['Gush', 'Banido', 'Counterspell'], false);
        $this->assertSame(1, substr_count($this->actingAs($ana)->get(route('decks.show', $pauper))->getContent(), $tag));

        $this->actingAs($ana)->get(route('decks.show', $modern))->assertOk()->assertDontSee('class="ban-tag"', false);
    }

    public function test_organizer_picks_standard_or_custom_rules(): void
    {
        $org = $this->account('Org');
        $base = ['name' => 'Liga', 'format' => 'Pauper', 'tournament_date' => '2026-11-10'];

        $this->actingAs($org)->post(route('tournaments.store'), $base)->assertRedirect();
        $standard = Tournament::latest('id')->first();
        $this->assertSame([Tournament::RULES_STANDARD, false, true], [$standard->rules, $standard->allow_banned, $standard->blocksBannedCards()]);
        $this->assertSame('Regras padrão do Pauper · sem cartas banidas', $standard->rulesLabel());

        // padrão ignora "permitir banidas" e regras da casa
        $this->actingAs($org)->post(route('tournaments.store'), $base + ['rules' => 'standard', 'allow_banned' => 1, 'house_rules' => 'Proxies ok'])->assertRedirect();
        $this->assertSame([false, null], [Tournament::latest('id')->first()->allow_banned, Tournament::latest('id')->first()->house_rules]);

        $this->actingAs($org)->post(route('tournaments.store'), $base + ['rules' => 'custom', 'allow_banned' => 1, 'house_rules' => 'Proxies liberados'])->assertRedirect();
        $custom = Tournament::latest('id')->first();
        $this->assertSame([Tournament::RULES_CUSTOM, true, false], [$custom->rules, $custom->allow_banned, $custom->blocksBannedCards()]);
        $this->get(route('arena.public', $custom->public_slug))->assertSee('Regras personalizadas · cartas banidas liberadas')->assertSee('Proxies liberados');
        $this->actingAs($org)->get(route('tournaments.invite.show', $custom->invite_token))->assertSee('Proxies liberados');
        $this->actingAs($org)->get(route('tournaments.create'))->assertSee('Regras do torneio')->assertSee('Permitir cartas banidas no formato');

        // ranqueado não aceita banidas
        $this->actingAs($org)->post(route('tournaments.store'), $base + ['ranked' => 1, 'rules' => 'custom', 'allow_banned' => 1])->assertSessionHasErrors('allow_banned');

        // Draft não tem lista de banidas: nada é conferido
        $draft = $this->tournament($org, ['format' => 'Draft']);
        $this->assertFalse($draft->blocksBannedCards());
        $this->assertSame('Regras padrão do Draft', $draft->rulesLabel());

        // repetir torneio leva as regras junto
        $this->actingAs($org)->post(route('tournaments.repeat', $custom), ['name' => 'Liga 2', 'tournament_date' => '2026-11-17'])->assertRedirect();
        $copy = Tournament::where('name', 'Liga 2')->first();
        $this->assertSame([Tournament::RULES_CUSTOM, true, 'Proxies liberados'], [$copy->rules, $copy->allow_banned, $copy->house_rules]);
    }

    public function test_banned_deck_cannot_join_swap_or_check_in(): void
    {
        $this->ban('Pauper', 'Gush', 'High Tide');
        $org = $this->account('Org');
        $ana = $this->account('Ana');
        $banned = $this->deck($ana, 'Tron Gush', ['Gush', 'High Tide', 'Counterspell']);
        $clean = $this->deck($ana, 'Faeries', ['Counterspell']);
        $t = $this->tournament($org);

        // convite: o seletor avisa e a inscrição com o deck banido é recusada, com as cartas
        $this->actingAs($ana)->get(route('tournaments.invite.show', $t->invite_token))->assertSee('Tron Gush · tem cartas banidas')->assertSee('não podem ser usados neste torneio');
        $this->actingAs($ana)->post(route('tournaments.invite.store', $t->invite_token), ['deck_id' => $banned->id])
            ->assertSessionHasErrors(['deck_id' => 'Seu deck tem cartas banidas no Pauper: Gush e High Tide. Troque essas cartas ou escolha outro deck.']);
        $this->assertSame(0, $t->players()->count());

        $this->actingAs($ana)->post(route('tournaments.invite.store', $t->invite_token), ['deck_id' => $clean->id])->assertSessionHasNoErrors();
        $entry = $t->players()->first();

        // trocar para o deck banido também não passa
        $this->actingAs($ana)->patch(route('tournaments.entry.update', $t), ['deck_id' => $banned->id])->assertSessionHasErrors('deck_id');
        $this->assertSame($clean->id, $entry->fresh()->deck_id);

        // o deck muda depois da inscrição: check-in barrado (jogadora e organizador), início barrado
        $clean->cards()->create(['scryfall_id' => (string) Str::uuid(), 'name' => 'Gush', 'quantity' => 1, 'board' => 'side', 'type_line' => 'Instant', 'cmc' => 5, 'mana_cost' => '{4}{U}']);
        $this->actingAs($ana)->get(route('arena.public', $t->public_slug))->assertSee('Seu deck tem cartas banidas no Pauper: Gush.');
        $this->actingAs($ana)->post(route('tournaments.check-in', $t))->assertSessionHasErrors('check_in');
        $this->actingAs($org)->patch(route('tournaments.players.check-in', [$t, $entry]))
            ->assertSessionHasErrors(['check_in' => 'O deck de Ana tem cartas banidas no Pauper. A presença só pode ser marcada depois que a pessoa trocar as cartas ou o deck.']);
        $this->assertNull($entry->fresh()->checked_in_at);
        $this->actingAs($org)->get(route('tournaments.show', $t))->assertSee('Deck inválido');
        $t->players()->create(['name' => 'Bia']);
        $this->actingAs($org)->post(route('tournaments.start', $t))->assertSessionHasErrors(['tournament' => 'Não dá para começar: Ana está com cartas banidas no Pauper. Peça para trocarem o deck ou remova da lista.']);
        $this->assertSame('setup', $t->fresh()->status);

        // trocou as cartas: check-in liberado
        $clean->cards()->where('name', 'Gush')->delete();
        $this->actingAs($ana)->post(route('tournaments.check-in', $t))->assertSessionHas('status', 'Presença confirmada. Boa sorte!');

        // regras personalizadas que liberam banidas: o deck banido entra
        $open = $this->tournament($org, ['rules' => 'custom', 'allow_banned' => true]);
        $this->actingAs($ana)->post(route('tournaments.invite.store', $open->invite_token), ['deck_id' => $banned->id])->assertSessionHasNoErrors();
        $this->assertSame($banned->id, $open->players()->first()->deck_id);
    }

    public function test_organizer_cannot_add_a_friend_whose_main_deck_is_banned(): void
    {
        $this->ban('Pauper', 'Gush');
        $org = $this->account('Org');
        $ana = $this->account('Ana');
        $org->friendProfiles()->attach($ana->playerProfile, ['status' => 'accepted']);
        $ana->friendProfiles()->attach($org->playerProfile, ['status' => 'accepted']);
        $deck = $this->deck($ana, 'Gush Storm', ['Gush'], primary: true);
        $t = $this->tournament($org);

        $this->actingAs($org)->post(route('tournaments.friends.store', [$t, $ana->playerProfile]))->assertSessionHasErrors('player');
        $this->assertSame(0, $t->players()->count());

        // formato "Outro" (sem lista de banidas) libera
        $other = $this->tournament($org, ['format' => 'Outro']);
        $this->actingAs($org)->post(route('tournaments.friends.store', [$other, $ana->playerProfile]))->assertSessionHas('status', 'Amigo adicionado ao torneio.');
        $this->assertSame($deck->id, $other->players()->first()->deck_id);

        // editar o torneio para as regras padrão avisa sobre inscritos com banidas
        $loose = $this->tournament($org, ['rules' => 'custom', 'allow_banned' => true]);
        $loose->players()->create(['name' => 'Ana', 'player_profile_id' => $ana->playerProfile->id, 'deck_id' => $deck->id]);
        $this->actingAs($org)->put(route('tournaments.update', $loose), ['name' => 'Copa', 'format' => 'Pauper', 'tournament_date' => '2026-11-01', 'rules' => 'standard'])
            ->assertSessionHas('status', 'Torneio atualizado. Atenção: 1 inscrito está com cartas banidas no deck e só pode fazer check-in depois de trocar.');
    }
}
