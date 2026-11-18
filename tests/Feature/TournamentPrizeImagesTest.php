<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Tournament;
use App\Models\TournamentPrize;
use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Arte do troféu: opcional, sempre guardada como WebP quadrado de 512 px, mostrada no torneio e no perfil de quem ganhou. */
class TournamentPrizeImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('Extensao GD com WebP e necessaria.');
        }
        Storage::fake('local');
    }

    public function test_trophy_image_becomes_a_512_square_and_shows_on_the_tournament_and_the_winner_profile(): void
    {
        [$t, $owner] = $this->arena();

        // Imagem deitada (640×300): entra inteira no quadrado, sem recorte.
        $this->actingAs($owner)->put(route('tournaments.prizes.update', $t), [
            'prizes' => [1 => ['style' => 'trophy', 'trophy_label' => 'Taça da Loja']],
            'images' => [1 => $this->jpeg('taca.jpg', 640, 300)],
        ])->assertRedirect(route('tournaments.show', $t).'#premiacao');

        $path = $t->prizes()->sole()->image_path;
        $this->assertTrue(AvatarService::isValidPath($path));
        $info = getimagesizefromstring(Storage::disk('local')->get($path));
        $this->assertSame([TournamentPrize::IMAGE_SIZE, TournamentPrize::IMAGE_SIZE, IMAGETYPE_WEBP], [$info[0], $info[1], $info[2]]);
        $this->assertSame(512, TournamentPrize::IMAGE_SIZE);

        $img = 'src="'.route('avatars.show', basename($path)).'"';
        $this->get(route('tournaments.show', $t))->assertOk()->assertSee($img, false);
        $this->get(route('arena.public', $t->public_slug))->assertOk()->assertSee($img, false);
        $this->get(route('tournaments.prizes.edit', $t))->assertOk()->assertSee($img, false)->assertSee('Remover a imagem atual');

        // Quem ganha leva a arte para a estante do perfil; visitante também vê.
        $this->finish($t);
        $final = $t->matches()->where('stage', 'final')->sole();
        $champion = ($final->player_one_games > $final->player_two_games ? $final->playerOne : $final->playerTwo)->profile;
        $this->actingAs($champion->account)->get(route('profiles.index'))->assertOk()->assertSeeInOrder(['Troféus e medalhas', $img, 'Taça da Loja'], false);
        $this->actingAs(User::factory()->create())->get(route('profiles.show', $champion))->assertOk()->assertSee($img, false);
    }

    public function test_both_upload_forms_offer_the_crop_before_sending(): void
    {
        [$t, $owner] = $this->arena();

        // Troféu: pode afastar até a imagem caber inteira; sai no lado padrão das artes.
        $this->actingAs($owner)->get(route('tournaments.prizes.edit', $t))->assertOk()
            ->assertSee('js/image-crop.js', false)->assertSee('js/crop-core.js', false)
            ->assertSee('data-crop="contain" data-crop-size="'.TournamentPrize::IMAGE_SIZE.'"', false);
        // Foto de perfil: sempre cobre o quadrado, com o círculo em que ela aparece no site.
        $this->get(route('profiles.edit'))->assertOk()
            ->assertSee('js/image-crop.js', false)
            ->assertSee('data-crop="cover" data-crop-shape="circle"', false)
            ->assertSee('id="photo-preview"', false);
    }

    public function test_replacing_or_removing_the_image_cleans_the_old_file(): void
    {
        [$t, $owner] = $this->arena();
        $send = fn (array $row, array $images = []) => $this->actingAs($owner)->put(route('tournaments.prizes.update', $t), ['prizes' => [1 => $row], 'images' => $images]);

        $send(['style' => 'trophy'], [1 => $this->jpeg('a.jpg', 300, 300)])->assertRedirect();
        $first = $t->prizes()->sole()->image_path;

        // Salvar sem mandar arquivo mantém a arte.
        $send(['style' => 'medal', 'trophy_label' => 'Medalha da Loja'])->assertRedirect();
        $this->assertSame($first, $t->prizes()->sole()->image_path);

        // Trocar apaga a anterior.
        $send(['style' => 'medal'], [1 => $this->jpeg('b.jpg', 300, 300)])->assertRedirect();
        $second = $t->prizes()->sole()->image_path;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);

        // Remover volta para o ícone do catálogo.
        $send(['style' => 'medal', 'remove_image' => 1])->assertRedirect();
        $this->assertNull($t->prizes()->sole()->image_path);
        Storage::disk('local')->assertMissing($second);

        // Tirar a peça também leva a arte embora.
        $send(['style' => 'crown'], [1 => $this->jpeg('c.jpg', 300, 300)])->assertRedirect();
        $third = $t->prizes()->sole()->image_path;
        $send(['style' => ''])->assertRedirect();
        $this->assertSame(0, $t->prizes()->count());
        Storage::disk('local')->assertMissing($third);
    }

    public function test_invalid_images_are_refused_and_nothing_is_stored(): void
    {
        [$t, $owner] = $this->arena();
        $send = fn (array $row, UploadedFile $file) => $this->actingAs($owner)->put(route('tournaments.prizes.update', $t), ['prizes' => [1 => $row], 'images' => [1 => $file]]);

        // Imagem sem peça escolhida, arquivo que não é imagem, grande demais e pequena demais.
        $send(['style' => ''], $this->jpeg('a.jpg', 300, 300))->assertSessionHasErrors('images.1');
        $send(['style' => 'trophy'], UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))->assertSessionHasErrors('images.1');
        $send(['style' => 'trophy'], UploadedFile::fake()->create('a.jpg', TournamentPrize::IMAGE_MAX_KILOBYTES + 1, 'image/jpeg'))->assertSessionHasErrors('images.1');
        $send(['style' => 'trophy'], $this->jpeg('a.jpg', 20, 20))->assertSessionHasErrors('images.1');
        // Nome ofensivo junto com uma imagem válida: nada fica no storage.
        $send(['style' => 'trophy', 'trophy_label' => 'Taça do Merda'], $this->jpeg('a.jpg', 300, 300))->assertSessionHasErrors('prizes.1.trophy_label');

        $this->assertSame(0, $t->prizes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_repeated_tournament_shares_the_image_until_the_last_one_is_deleted(): void
    {
        [$t, $owner] = $this->arena();
        $this->actingAs($owner)->put(route('tournaments.prizes.update', $t), ['prizes' => [1 => ['style' => 'trophy']], 'images' => [1 => $this->jpeg('a.jpg', 300, 300)]]);
        $path = $t->prizes()->sole()->image_path;

        $this->post(route('tournaments.repeat', $t), ['name' => 'Copa da Loja #2', 'tournament_date' => now()->addWeek()->toDateString()])->assertRedirect();
        $copy = Tournament::where('name', 'Copa da Loja #2')->sole();
        $this->assertSame($path, $copy->prizes()->sole()->image_path);

        // A cópia troca a arte: a do original continua no lugar.
        $this->put(route('tournaments.prizes.update', $copy), ['prizes' => [1 => ['style' => 'trophy']], 'images' => [1 => $this->jpeg('b.jpg', 300, 300)]])->assertRedirect();
        Storage::disk('local')->assertExists($path);
        $other = $copy->prizes()->sole()->image_path;

        $this->delete(route('tournaments.destroy', $copy))->assertRedirect();
        Storage::disk('local')->assertMissing($other);
        Storage::disk('local')->assertExists($path);
        $this->delete(route('tournaments.destroy', $t))->assertRedirect();
        Storage::disk('local')->assertMissing($path);
    }

    private function finish(Tournament $t): void
    {
        $this->post(route('tournaments.start', $t))->assertRedirect();
        $t->matches()->update(['status' => GameMatch::STATUS_FINISHED, 'player_one_games' => 2, 'player_two_games' => 1]);
        $this->post(route('tournaments.finals', $t))->assertRedirect();
        foreach ($t->matches()->whereIn('stage', ['final', 'third_place'])->get() as $match) {
            $this->put(route('matches.update', [$t, $match]), ['player_one_games' => 2, 'player_two_games' => 0])->assertRedirect();
        }
    }

    private function arena(): array
    {
        $owner = User::factory()->create();
        $t = $owner->tournaments()->create([
            'name' => 'Copa da Loja', 'format' => 'Pauper', 'system' => 'round_robin', 'tournament_date' => '2026-11-10',
            'public_token' => Str::random(40), 'public_slug' => 'copa-'.Str::lower(Str::random(16)), 'invite_token' => Str::random(40),
        ]);
        foreach (range(1, 4) as $i) {
            $profile = User::factory()->create()->ensurePlayerProfile();
            $deck = $profile->decks()->create(['name' => "Deck {$i}", 'format' => 'Pauper']);
            $t->players()->create(['name' => "Jogador {$i}", 'player_profile_id' => $profile->id, 'deck_id' => $deck->id]);
        }

        return [$t, $owner];
    }

    private function jpeg(string $name, int $width, int $height): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 200, 160, 40));
        ob_start();
        imagejpeg($image, null, 90);

        return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
    }
}
