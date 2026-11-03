<?php

namespace Tests\Feature;

use App\Models\CardBan;
use App\Models\Deck;
use App\Models\DeckCard;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Tag "Inválida" no deck de Pauper: vem do legalities.pauper do Scryfall, nunca da raridade da edição salva. */
class PauperLegalityTest extends TestCase
{
    use RefreshDatabase;

    private const TAG = '<span class="ban-tag is-invalid" title="Não é válida no Pauper: nunca saiu como comum">Inválida</span>';

    /** Mini base de cartas do fake do Scryfall (nome => objeto da API). */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Raridade da edição e legalidade no Pauper de propósito diferentes: só o campo do Scryfall decide.
        $this->catalog = [
            'Swords to Plowshares' => $this->card('Swords to Plowshares', 'uncommon', 'not_legal'),       // incomum, nunca comum
            'Lightning Bolt' => $this->card('Lightning Bolt', 'uncommon', 'legal'),                       // incomum aqui, comum em outra
            'Ponder' => $this->card('Ponder', 'rare', 'legal'),                                           // rara aqui, comum em outra
            'Dark Ritual' => $this->card('Dark Ritual', 'mythic', 'legal'),                               // mítica aqui, comum em outra
            'Rare Legend' => $this->card('Rare Legend', 'rare', 'not_legal', 'Legendary Creature — Human'), // lendária só rara
            'Common Legend' => $this->card('Common Legend', 'common', 'legal', 'Legendary Creature — Elf'),  // lendária comum
            'Marvel Common' => $this->card('Marvel Common', 'common', 'legal', 'Creature — Spider Hero', 'spm'),
            'Marvel Uncommon' => $this->card('Marvel Uncommon', 'uncommon', 'not_legal', 'Creature — Spider Hero', 'spm'),
            'Gush' => $this->card('Gush', 'common', 'banned'),
            'No Data' => $this->card('No Data', 'uncommon', null),
        ];

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/cards/collection')) {
                $found = [];
                $missing = [];
                foreach ($request->data()['identifiers'] as $identifier) {
                    $card = isset($identifier['id'])
                        ? collect($this->catalog)->firstWhere('id', $identifier['id'])
                        : ($this->catalog[$identifier['name']] ?? null);
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

    public function test_tag_comes_from_the_pauper_legality_and_not_from_the_printing_rarity(): void
    {
        [$user, $deck] = $this->deck('Pauper');

        // Adicionar pela busca: a tag aparece logo depois, sem verificação manual.
        foreach (['Swords to Plowshares', 'Lightning Bolt', 'Ponder', 'Dark Ritual', 'Rare Legend', 'Common Legend'] as $name) {
            $this->actingAs($user)->post(route('decks.cards.store', $deck), ['scryfall_id' => $this->catalog[$name]['id']])->assertSessionHas('status');
        }

        $this->assertSame(
            ['Common Legend' => 'legal', 'Dark Ritual' => 'legal', 'Lightning Bolt' => 'legal', 'Ponder' => 'legal', 'Rare Legend' => 'not_legal', 'Swords to Plowshares' => 'not_legal'],
            $deck->cards()->reorder()->orderBy('name')->pluck('pauper_legality', 'name')->all(),
        );

        $page = $this->actingAs($user)->get(route('decks.show', $deck))->assertOk()
            ->assertSee('<strong>2 cartas inválidas no Pauper (nunca saíram como comum).</strong>', false)
            ->assertSeeInOrder(['Swords to Plowshares', 'Inválida'], false)
            ->assertSeeInOrder(['Rare Legend', 'Inválida'], false);
        $this->assertSame(2, substr_count($page->getContent(), self::TAG));
    }

    public function test_marvel_cards_follow_the_same_rule(): void
    {
        [$user, $deck] = $this->deck('Pauper');
        foreach (['Marvel Common', 'Marvel Uncommon'] as $name) {
            $this->actingAs($user)->post(route('decks.cards.store', $deck), ['scryfall_id' => $this->catalog[$name]['id']]);
        }

        $page = $this->actingAs($user)->get(route('decks.show', $deck))->assertOk()
            ->assertSee('<strong>1 carta inválida no Pauper (nunca saiu como comum).</strong>', false);
        $this->assertSame(1, substr_count($page->getContent(), self::TAG));
        $this->assertSame('not_legal', $deck->cards()->where('name', 'Marvel Uncommon')->value('pauper_legality'));
        $this->assertSame('legal', $deck->cards()->where('name', 'Marvel Common')->value('pauper_legality'));
    }

    public function test_banned_card_keeps_its_own_tag_and_is_not_marked_invalid(): void
    {
        CardBan::create(['format' => 'Pauper', 'card_name' => 'Gush']);
        [$user, $deck] = $this->deck('Pauper');
        $this->actingAs($user)->post(route('decks.cards.store', $deck), ['scryfall_id' => $this->catalog['Gush']['id']]);

        $this->actingAs($user)->get(route('decks.show', $deck))->assertOk()
            ->assertSee('<span class="ban-tag" title="Banida no Pauper">Banido</span>', false)
            ->assertSee('<strong>1 carta banida no Pauper.</strong>', false)
            ->assertDontSee('is-invalid', false);

        // Banida e inválida juntas: um alerta só, com as duas tags.
        $this->actingAs($user)->post(route('decks.cards.store', $deck), ['scryfall_id' => $this->catalog['Swords to Plowshares']['id']]);
        $this->actingAs($user)->get(route('decks.show', $deck))->assertOk()
            ->assertSee('<strong>1 carta banida e 1 inválida no Pauper.</strong>', false)
            ->assertSeeInOrder(['Torneios com as regras padrão do formato não aceitam este deck até a troca delas', 'Banido', 'Inválida', 'na lista'], false);
    }

    public function test_invalid_cards_block_pauper_tournaments_with_the_official_rules_like_banned_ones(): void
    {
        $org = $this->user('Org');
        $ana = $this->user('Ana');
        [, $invalid] = $this->deck('Pauper', $ana, 'Swords Deck');
        $this->line($invalid, 'Swords to Plowshares', 'not_legal');
        [, $clean] = $this->deck('Pauper', $ana, 'Bolt Deck');
        $this->line($clean, 'Lightning Bolt', 'legal');
        $t = $this->tournament($org);
        $this->assertSame('Regras padrão do Pauper · sem cartas banidas ou inválidas', $t->rulesLabel());

        // Convite: o seletor avisa e a inscrição com o deck inválido é recusada, com as cartas.
        $this->actingAs($ana)->get(route('tournaments.invite.show', $t->invite_token))
            ->assertSee('Swords Deck · tem cartas banidas ou inválidas')->assertSee('não podem ser usados neste torneio');
        $this->actingAs($ana)->post(route('tournaments.invite.store', $t->invite_token), ['deck_id' => $invalid->id])
            ->assertSessionHasErrors(['deck_id' => 'Seu deck tem cartas banidas ou inválidas no Pauper: Swords to Plowshares. Troque essas cartas ou escolha outro deck.']);
        $this->assertSame(0, $t->players()->count());

        // Entra com o deck limpo; trocar para o inválido não passa.
        $this->actingAs($ana)->post(route('tournaments.invite.store', $t->invite_token), ['deck_id' => $clean->id])->assertSessionHasNoErrors();
        $entry = $t->players()->sole();
        $this->actingAs($ana)->patch(route('tournaments.entry.update', $t), ['deck_id' => $invalid->id])->assertSessionHasErrors('deck_id');
        $this->assertSame($clean->id, $entry->fresh()->deck_id);

        // O deck ganha uma inválida depois da inscrição: check-in (jogadora e organizador) e início barrados.
        $this->line($clean, 'Rare Legend', 'not_legal');
        $this->actingAs($ana)->post(route('tournaments.check-in', $t))->assertSessionHasErrors('check_in');
        $this->actingAs($org)->patch(route('tournaments.players.check-in', [$t, $entry]))
            ->assertSessionHasErrors(['check_in' => 'O deck de Ana tem cartas banidas ou inválidas no Pauper. A presença só pode ser marcada depois que a pessoa trocar as cartas ou o deck.']);
        $this->assertNull($entry->fresh()->checked_in_at);
        $this->actingAs($org)->get(route('tournaments.show', $t))->assertSee('Deck inválido');
        $t->players()->create(['name' => 'Bia']);
        $this->actingAs($org)->post(route('tournaments.start', $t))
            ->assertSessionHasErrors(['tournament' => 'Não dá para começar: Ana está com cartas banidas ou inválidas no Pauper. Peça para trocarem o deck ou remova da lista.']);

        // Trocou a carta: check-in liberado.
        $clean->cards()->where('name', 'Rare Legend')->delete();
        $this->actingAs($ana)->post(route('tournaments.check-in', $t))->assertSessionHas('status', 'Presença confirmada. Boa sorte!');

        // Regras personalizadas que liberam banidas também liberam as inválidas.
        $open = $this->tournament($org, ['rules' => 'custom', 'allow_banned' => true]);
        $this->actingAs($ana)->post(route('tournaments.invite.store', $open->invite_token), ['deck_id' => $invalid->id])->assertSessionHasNoErrors();
    }

    public function test_invalid_pauper_cards_do_not_block_other_formats(): void
    {
        $org = $this->user('Org');
        $ana = $this->user('Ana');
        [, $deck] = $this->deck('Modern', $ana, 'Modern Swords');
        $this->line($deck, 'Swords to Plowshares', 'not_legal');
        $modern = $this->tournament($org, ['format' => 'Modern']);

        $this->assertSame('Regras padrão do Modern · sem cartas banidas', $modern->rulesLabel());
        $this->actingAs($ana)->post(route('tournaments.invite.store', $modern->invite_token), ['deck_id' => $deck->id])->assertSessionHasNoErrors();
        $this->assertSame($deck->id, $modern->players()->sole()->deck_id);
    }

    public function test_only_pauper_decks_are_tagged_and_unknown_legality_is_not(): void
    {
        [$user, $modern] = $this->deck('Modern');
        $this->actingAs($user)->post(route('decks.cards.store', $modern), ['scryfall_id' => $this->catalog['Swords to Plowshares']['id']]);
        $this->actingAs($user)->get(route('decks.show', $modern))->assertOk()->assertDontSee('is-invalid', false)->assertDontSee('Pauper (nunca');

        // Sem o campo na resposta: fica desconhecida (nula) e sem tag, até a sincronização preencher.
        [, $pauper] = $this->deck('Pauper', $user);
        $this->actingAs($user)->post(route('decks.cards.store', $pauper), ['scryfall_id' => $this->catalog['No Data']['id']]);
        $this->assertNull($pauper->cards()->sole()->pauper_legality);
        $this->actingAs($user)->get(route('decks.show', $pauper))->assertOk()->assertDontSee('is-invalid', false);
    }

    public function test_imported_decklist_gets_the_legality_from_the_same_scryfall_response(): void
    {
        [$user, $deck] = $this->deck('Pauper');

        $this->actingAs($user)->post(route('decks.cards.import', $deck), ['list' => "4 Lightning Bolt\n2 Swords to Plowshares"])->assertSessionHas('status');

        $this->assertSame(['Lightning Bolt' => 'legal', 'Swords to Plowshares' => 'not_legal'], $deck->cards()->reorder()->orderBy('name')->pluck('pauper_legality', 'name')->all());
        Http::assertSentCount(1); // um lote só, sem consulta extra por carta
        $this->actingAs($user)->get(route('decks.show', $deck))->assertSee(self::TAG, false);
    }

    public function test_sync_command_fills_old_cards_and_updates_changed_ones(): void
    {
        [$user, $deck] = $this->deck('Pauper');
        // Carta antiga (sem o dado), carta que ganhou impressão comum e carta que o Scryfall não devolve.
        $this->line($deck, 'Swords to Plowshares', null);
        $this->line($deck, 'Ponder', 'not_legal');
        $gone = $deck->cards()->create(['scryfall_id' => (string) Str::uuid(), 'name' => 'Gone', 'quantity' => 1, 'board' => 'main', 'pauper_legality' => 'not_legal']);

        $this->artisan('cards:sync-legality')->expectsOutputToContain('3 carta(s) consultada(s), 2 linha(s) de deck atualizada(s)')->assertExitCode(0);

        $this->assertSame('not_legal', $deck->cards()->where('name', 'Swords to Plowshares')->value('pauper_legality'));
        $this->assertSame('legal', $deck->cards()->where('name', 'Ponder')->value('pauper_legality'));
        $this->assertSame('not_legal', $gone->fresh()->pauper_legality);
    }

    public function test_adding_a_card_again_refreshes_its_legality(): void
    {
        [$user, $deck] = $this->deck('Pauper');
        $this->line($deck, 'Ponder', 'not_legal');

        $this->actingAs($user)->post(route('decks.cards.store', $deck), ['scryfall_id' => $this->catalog['Ponder']['id']]);

        $card = $deck->cards()->sole();
        $this->assertSame([2, 'legal'], [$card->quantity, $card->pauper_legality]);
    }

    /** @return array{0: User, 1: Deck} */
    private function deck(string $format, ?User $user = null, ?string $name = null): array
    {
        $user ??= User::factory()->create();

        return [$user, $user->ensurePlayerProfile()->decks()->create(['name' => $name ?? 'Deck '.Str::random(4), 'format' => $format])];
    }

    private function user(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->ensurePlayerProfile()->update(['nickname' => $name]);

        return $user->fresh();
    }

    private function tournament(User $owner, array $attributes = []): Tournament
    {
        return $owner->tournaments()->create($attributes + ['name' => 'Copa', 'format' => 'Pauper', 'tournament_date' => '2026-11-01', 'status' => 'setup',
            'public_token' => Str::random(40), 'public_slug' => 'copa-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40)]);
    }

    /** Linha do deck gravada direto, como uma carta salva antes desta funcionalidade. */
    private function line(Deck $deck, string $name, ?string $legality): DeckCard
    {
        return $deck->cards()->create(['scryfall_id' => $this->catalog[$name]['id'], 'name' => $name, 'quantity' => 1, 'board' => 'main',
            'type_line' => 'Instant', 'cmc' => 1, 'pauper_legality' => $legality]);
    }

    private function card(string $name, string $rarity, ?string $pauper, string $type = 'Instant', string $set = 'tst'): array
    {
        $slug = Str::slug($name);

        return [
            'object' => 'card', 'id' => (string) Str::uuid(), 'name' => $name, 'mana_cost' => '{U}', 'cmc' => 1,
            'type_line' => $type, 'color_identity' => ['U'], 'rarity' => $rarity, 'set' => $set,
            'legalities' => array_filter(['commander' => 'legal', 'pauper' => $pauper]),
            'image_uris' => ['normal' => "https://cards.scryfall.io/normal/front/{$slug}.jpg", 'art_crop' => "https://cards.scryfall.io/art_crop/front/{$slug}.jpg"],
        ];
    }
}
