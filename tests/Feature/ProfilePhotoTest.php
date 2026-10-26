<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfilePhotoTest extends TestCase
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

    public function test_uploaded_photo_is_reencoded_as_square_webp_and_shown_everywhere(): void
    {
        $user = User::factory()->create(['name' => 'Vini']);

        $this->actingAs($user)->post(route('profiles.photo.update'), ['photo' => $this->jpeg('foto.jpg', 640, 480)])
            ->assertRedirect(route('profiles.edit'))->assertSessionHas('status', 'Foto de perfil atualizada.');

        $path = $user->ensurePlayerProfile()->avatar_path;
        $this->assertTrue(AvatarService::isValidPath($path));
        Storage::disk('local')->assertExists($path);

        $bytes = Storage::disk('local')->get($path);
        $info = getimagesizefromstring($bytes);
        $this->assertSame([256, 256, IMAGETYPE_WEBP], [$info[0], $info[1], $info[2]]);

        $url = route('avatars.show', basename($path));
        $this->actingAs($user)->get(route('profiles.index'))->assertOk()->assertSee('src="'.$url.'"', false);
        // Cabecalho (todas as paginas) tambem usa a foto.
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('src="'.$url.'"', false);

        $response = $this->get($url)->assertOk();
        $this->assertSame('image/webp', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_metadata_and_appended_payloads_never_reach_storage(): void
    {
        $user = User::factory()->create();
        $jpeg = $this->jpegBytes(300, 300);
        // APP1 com "GPS" logo apos o SOI + payload HTML/PHP depois do fim da imagem (polyglot).
        $app1 = "Exif\0\0SEGREDO-GPS-23.5505S-46.6333W";
        $poisoned = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2)
            .'<?php system($_GET["c"]); ?><script>alert(1)</script>';

        $this->actingAs($user)->post(route('profiles.photo.update'), [
            'photo' => UploadedFile::fake()->createWithContent('foto.jpg', $poisoned),
        ])->assertRedirect(route('profiles.edit'));

        $stored = Storage::disk('local')->get($user->ensurePlayerProfile()->avatar_path);
        $this->assertStringNotContainsString('SEGREDO-GPS', $stored);
        $this->assertStringNotContainsString('<?php', $stored);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringStartsWith('RIFF', $stored);
    }

    public function test_exif_orientation_is_applied_before_metadata_is_dropped(): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('Extensao exif ausente.');
        }
        $user = User::factory()->create();

        // Paisagem 200x100: metade esquerda vermelha, direita azul; EXIF Orientation=6 (girar 90° horario).
        $image = imagecreatetruecolor(200, 100);
        imagefilledrectangle($image, 0, 0, 99, 99, imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, 100, 0, 199, 99, imagecolorallocate($image, 0, 0, 255));
        ob_start();
        imagejpeg($image, null, 95);
        $jpeg = (string) ob_get_clean();
        $tiff = "II\x2A\x00\x08\x00\x00\x00"."\x01\x00"."\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00"."\x00\x00\x00\x00";
        $app1 = "Exif\0\0".$tiff;
        $rotated = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);

        $this->actingAs($user)->post(route('profiles.photo.update'), [
            'photo' => UploadedFile::fake()->createWithContent('celular.jpg', $rotated),
        ])->assertRedirect(route('profiles.edit'));

        $result = imagecreatefromstring(Storage::disk('local')->get($user->ensurePlayerProfile()->avatar_path));
        // Depois de girar, o vermelho (lado esquerdo) fica em cima e o azul embaixo.
        $top = imagecolorsforindex($result, imagecolorat($result, 128, 20));
        $bottom = imagecolorsforindex($result, imagecolorat($result, 128, 235));
        $this->assertGreaterThan($top['blue'] + 100, $top['red']);
        $this->assertGreaterThan($bottom['red'] + 100, $bottom['blue']);
    }

    public function test_replacing_and_removing_the_photo_deletes_the_old_object(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('profiles.photo.update'), ['photo' => $this->jpeg('a.jpg', 300, 300)]);
        $first = $user->ensurePlayerProfile()->avatar_path;

        $this->actingAs($user)->post(route('profiles.photo.update'), ['photo' => UploadedFile::fake()->image('b.png', 400, 300)])
            ->assertRedirect(route('profiles.edit'));
        $second = $user->ensurePlayerProfile()->fresh()->avatar_path;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);

        $this->actingAs($user)->delete(route('profiles.photo.destroy'))->assertRedirect(route('profiles.edit'));
        $this->assertNull($user->ensurePlayerProfile()->fresh()->avatar_path);
        Storage::disk('local')->assertMissing($second);
        // Sem foto o emoji/inicial volta a aparecer.
        $this->actingAs($user)->get(route('profiles.index'))->assertOk()->assertDontSee('/avatars/', false);
    }

    public function test_invalid_files_are_rejected_without_touching_storage(): void
    {
        $user = User::factory()->create();
        $cases = [
            'svg' => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'svg disfarcado' => UploadedFile::fake()->createWithContent('x.png', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'),
            'texto' => UploadedFile::fake()->createWithContent('x.jpg', 'nao sou uma imagem'),
            'gif' => UploadedFile::fake()->image('x.gif', 200, 200),
            'pequena demais' => UploadedFile::fake()->image('x.png', 32, 32),
            'arquivo grande' => UploadedFile::fake()->create('x.jpg', 6000, 'image/jpeg'),
        ];

        foreach ($cases as $label => $file) {
            $this->actingAs($user)->from(route('profiles.edit'))
                ->post(route('profiles.photo.update'), ['photo' => $file])
                ->assertRedirect(route('profiles.edit'))
                ->assertSessionHasErrors('photo', null, 'default');
            $this->assertNull($user->ensurePlayerProfile()->fresh()->avatar_path, $label);
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_photo_routes_require_login_and_only_serve_valid_names(): void
    {
        $this->post(route('profiles.photo.update'), ['photo' => $this->jpeg('a.jpg', 100, 100)])->assertRedirect(route('login'));
        $this->delete(route('profiles.photo.destroy'))->assertRedirect(route('login'));

        $this->get('/avatars/123e4567-e89b-42d3-a456-426614174000.webp')->assertNotFound();
        $this->get('/avatars/..%2F.env')->assertNotFound();
        $this->get('/avatars/database.sqlite')->assertNotFound();
    }

    public function test_tampered_path_in_database_is_never_turned_into_a_url(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensurePlayerProfile();
        $profile->forceFill(['avatar_path' => '../../.env'])->save();

        $this->assertNull($profile->avatarUrl());
        $this->actingAs($user)->get(route('profiles.index'))->assertOk()->assertDontSee('.env', false);
    }

    public function test_s3_disk_uses_stable_presigned_urls_and_allows_only_the_bucket_in_the_csp(): void
    {
        $this->useFakeS3();
        $user = User::factory()->create();
        $path = 'avatars/123e4567-e89b-42d3-a456-426614174000.webp';
        $user->ensurePlayerProfile()->forceFill(['avatar_path' => $path])->save();

        $first = $this->actingAs($user)->get(route('profiles.index'))->assertOk();
        $csp = (string) $first->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("img-src 'self' data: https://cards.scryfall.io https://manaleague-avatars-test.s3.amazonaws.com;", $csp);

        preg_match('#src="(https://manaleague-avatars-test\.s3\.amazonaws\.com/'.preg_quote($path, '#').'\?[^"]+)"#', $first->getContent(), $match);
        $this->assertNotEmpty($match, 'A pagina deve usar a URL assinada do S3.');
        $url = html_entity_decode($match[1]);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
        $this->assertStringContainsString('X-Amz-Expires=', $url);

        // Outra requisicao na mesma janela gera exatamente a mesma URL (cache do navegador funciona).
        $again = $this->actingAs($user)->get(route('profiles.index'))->getContent();
        $this->assertStringContainsString($match[1], $again);

        // A rota local apenas redireciona para o S3.
        $this->get(route('avatars.show', basename($path)))->assertRedirect($url);
    }

    public function test_s3_outage_shows_a_friendly_error_instead_of_a_500(): void
    {
        $this->useFakeS3(['endpoint' => 'http://127.0.0.1:9', 'use_path_style_endpoint' => true, 'retries' => 0]);
        $user = User::factory()->create();

        $this->actingAs($user)->from(route('profiles.edit'))
            ->post(route('profiles.photo.update'), ['photo' => $this->jpeg('a.jpg', 300, 300)])
            ->assertRedirect(route('profiles.edit'))
            ->assertSessionHasErrors(['photo' => 'Não foi possível salvar a foto agora. Tente de novo em instantes.']);
        $this->assertNull($user->ensurePlayerProfile()->fresh()->avatar_path);
    }

    public function test_csp_has_no_bucket_origin_when_photos_are_local(): void
    {
        $csp = (string) $this->get('/login')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("img-src 'self' data: https://cards.scryfall.io;", $csp);
        $this->assertStringNotContainsString('amazonaws', $csp);
    }

    private function useFakeS3(array $overrides = []): void
    {
        config([
            'avatars.disk' => 's3',
            'filesystems.disks.s3' => array_merge(config('filesystems.disks.s3'), [
                'key' => 'AKIATESTTESTTESTTEST', 'secret' => 'test-secret', 'region' => 'us-east-1',
                'bucket' => 'manaleague-avatars-test', 'endpoint' => null,
            ], $overrides),
        ]);
        Storage::forgetDisk('s3');
        $this->app->forgetScopedInstances();
    }

    private function jpeg(string $name, int $width, int $height): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->jpegBytes($width, $height));
    }

    private function jpegBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 30, 120, 200));
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }
}
