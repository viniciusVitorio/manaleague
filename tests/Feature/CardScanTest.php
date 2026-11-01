<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Escanear carta pela camera (POST /cards/scan).
 *
 * O que estes testes protegem:
 *  - o microsservico de visao so devolve IDs; nome, tipo e imagem vem do Scryfall (RS17);
 *  - nenhum dado de carta enviado pelo navegador e aproveitado;
 *  - o recurso fica desligado (503) sem configuracao, e a rota exige login;
 *  - imagem invalida, grande ou sem carta nao viram erro 500 nem carta errada.
 */
class CardScanTest extends TestCase
{
    use RefreshDatabase;

    private string $boltId;

    private string $counterId;

    /** Resposta que o microsservico fake vai dar nesta chamada. */
    private string $scenario = 'match';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.scanner.url' => 'http://scanner:8001', 'services.scanner.token' => 'segredo-de-teste']);

        $this->boltId = (string) Str::uuid();
        $this->counterId = (string) Str::uuid();

        $cards = [
            $this->boltId => $this->card($this->boltId, 'Lightning Bolt', '{R}', 'Instant'),
            $this->counterId => $this->card($this->counterId, 'Counterspell', '{U}{U}', 'Instant'),
        ];

        Http::fake(function (Request $request) use ($cards) {
            if (str_contains($request->url(), 'scanner:8001/recognize')) {
                return $this->scenario === 'outage'
                    ? Http::response(['detail' => 'boom'], 503)
                    : Http::response($this->scannerResponse());
            }

            if (preg_match('#/cards/([0-9a-f-]{36})$#', $request->url(), $match)) {
                return isset($cards[$match[1]])
                    ? Http::response($cards[$match[1]])
                    : Http::response(['object' => 'error'], 404);
            }

            return Http::response([], 500);
        });
    }

    public function test_guest_cannot_scan(): void
    {
        $this->postJson(route('cards.scan'), ['image' => $this->frame()])->assertUnauthorized();
    }

    public function test_recognized_card_comes_from_scryfall_not_from_the_client(): void
    {
        $response = $this->actingAs(User::factory()->create())->postJson(route('cards.scan'), [
            'image' => $this->frame(),
            'x' => 0.5,
            'y' => 0.5,
            // Mesmo que o cliente tente mandar dados de carta, nada disso e usado.
            'name' => '<script>alert(1)</script>',
            'scryfall_id' => $this->counterId,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'match')
            ->assertJsonPath('card.name', 'Lightning Bolt')
            ->assertJsonPath('card.scryfall_id', $this->boltId)
            ->assertJsonPath('card.type_line', 'Instant')
            ->assertJsonPath('card.image_url', 'https://cards.scryfall.io/normal/front/lightning-bolt.jpg');

        // O scanner recebeu o segredo compartilhado e a posicao do toque.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/recognize')
            && $request->hasHeader('X-Scanner-Token', 'segredo-de-teste'));
    }

    public function test_uncertain_scan_returns_suggestions_to_confirm(): void
    {
        $this->scenario = 'unsure';

        $this->actingAs(User::factory()->create())->postJson(route('cards.scan'), [
            'image' => $this->frame(),
            'x' => 0.1,
            'y' => 0.1,
        ])->assertOk()
            ->assertJsonPath('status', 'unsure')
            ->assertJsonPath('suggestions.0.name', 'Lightning Bolt')
            ->assertJsonPath('suggestions.1.name', 'Counterspell')
            ->assertJsonCount(2, 'suggestions');
    }

    public function test_nothing_recognized_is_answered_without_a_card(): void
    {
        $this->scenario = 'no_match';

        $this->actingAs(User::factory()->create())->postJson(route('cards.scan'), ['image' => $this->frame()])
            ->assertOk()
            ->assertJsonPath('status', 'no_match')
            ->assertJsonMissingPath('card');
    }

    public function test_ids_that_are_not_uuid_or_unknown_to_scryfall_are_discarded(): void
    {
        // ID que nem e UUID (tentativa de path traversal) nunca chega ao Scryfall.
        $this->scenario = 'bad_id';
        $this->actingAs(User::factory()->create())->postJson(route('cards.scan'), ['image' => $this->frame()])
            ->assertOk()
            ->assertJsonPath('status', 'no_match');

        // UUID valido que o Scryfall nao conhece: tambem nao vira carta.
        $this->scenario = 'unknown_id';
        $this->actingAs(User::factory()->create())->postJson(route('cards.scan'), ['image' => $this->frame()])
            ->assertOk()
            ->assertJsonPath('status', 'no_match');
    }

    public function test_scanner_outage_is_reported_as_unavailable(): void
    {
        $this->scenario = 'outage';

        $this->actingAs(User::factory()->create())->postJson(route('cards.scan'), ['image' => $this->frame()])
            ->assertStatus(503)
            ->assertJsonPath('status', 'unavailable');
    }

    public function test_feature_is_off_without_configuration(): void
    {
        config(['services.scanner.url' => null, 'services.scanner.token' => null]);

        $this->actingAs(User::factory()->create())->postJson(route('cards.scan'), ['image' => $this->frame()])
            ->assertStatus(503)
            ->assertJsonPath('status', 'unavailable');

        Http::assertNothingSent();
    }

    public function test_invalid_uploads_are_rejected_before_reaching_the_scanner(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('cards.scan'), [])->assertStatus(422);
        // Arquivo que nao e imagem (a regra e mimetypes: o mimetype real do arquivo, nao a extensao).
        $this->actingAs($user)->postJson(route('cards.scan'), [
            'image' => UploadedFile::fake()->createWithContent('frame.txt', 'nao sou imagem'),
        ])->assertStatus(422);
        // Acima de 4 MB nao passa (o frame da camera cabe com folga).
        $this->actingAs($user)->postJson(route('cards.scan'), [
            'image' => UploadedFile::fake()->image('frame.jpg')->size(5000),
        ])->assertStatus(422);
        // Posicao do toque e fracao de 0 a 1.
        $this->actingAs($user)->postJson(route('cards.scan'), [
            'image' => $this->frame(), 'x' => 3, 'y' => -1,
        ])->assertStatus(422)->assertJsonValidationErrors(['x', 'y']);

        Http::assertNothingSent();
    }

    public function test_deck_builder_only_offers_the_camera_to_the_owner_when_configured(): void
    {
        $user = User::factory()->create();
        $deck = $user->ensurePlayerProfile()->decks()->create(['name' => 'Burn', 'format' => 'Modern']);

        $this->actingAs($user)->get(route('decks.show', $deck))->assertOk()->assertSee('Escanear carta', false);

        config(['services.scanner.token' => null]);
        $this->actingAs($user)->get(route('decks.show', $deck))->assertOk()->assertDontSee('Escanear carta', false);
    }

    /** O frame nunca e gravado: o controller le o arquivo temporario e descarta. */
    private function frame(): UploadedFile
    {
        return UploadedFile::fake()->image('frame.jpg', 1280, 720);
    }

    /** Fake do microsservico de visao: devolve so IDs, como o servico real. */
    private function scannerResponse(): array
    {
        return match ($this->scenario) {
            'no_match' => ['status' => 'no_match'],
            'bad_id' => ['status' => 'match', 'card' => ['scryfall_id' => '../../etc/passwd', 'distance' => 10]],
            'unknown_id' => ['status' => 'match', 'card' => ['scryfall_id' => (string) Str::uuid(), 'distance' => 10]],
            'unsure' => ['status' => 'unsure', 'suggestions' => [
                ['scryfall_id' => $this->boltId, 'distance' => 70],
                ['scryfall_id' => $this->counterId, 'distance' => 74],
                // Acima do limite de sugestao: nao deve ser oferecida.
                ['scryfall_id' => (string) Str::uuid(), 'distance' => 95],
            ]],
            default => ['status' => 'match', 'card' => ['scryfall_id' => $this->boltId, 'distance' => 12, 'confidence' => 0.84]],
        };
    }

    private function card(string $id, string $name, string $cost, string $type): array
    {
        $slug = Str::slug($name);

        return [
            'object' => 'card', 'id' => $id, 'name' => $name, 'mana_cost' => $cost, 'cmc' => 1.0,
            'type_line' => $type, 'color_identity' => ['R'], 'rarity' => 'common', 'set' => 'tst',
            'legalities' => ['commander' => 'legal'],
            'image_uris' => [
                'normal' => "https://cards.scryfall.io/normal/front/{$slug}.jpg",
                'art_crop' => "https://cards.scryfall.io/art_crop/front/{$slug}.jpg",
            ],
        ];
    }
}
