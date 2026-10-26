<?php

namespace App\Services;

use App\Exceptions\AvatarException;
use App\Models\PlayerProfile;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Foto de perfil: valida, reencoda e guarda no disco configurado (S3 privado em producao).
 *
 * Seguranca:
 * - o arquivo enviado nunca e gravado como veio: e decodificado pelo GD e reencodado em WebP 256x256.
 *   Isso descarta EXIF (GPS, modelo do celular), payloads embutidos/polyglots e qualquer SVG/HTML;
 * - nome aleatorio (UUID) sem relacao com o usuario: nada de enumeracao nem sobrescrita de terceiros;
 * - bucket privado, leitura so por URL assinada de curta duracao;
 * - falha do S3 vira mensagem amigavel (sem 500) e a foto antiga so e apagada depois do novo upload.
 */
#[Scoped] // uma instancia por requisicao: o memo de URLs nao vaza entre requisicoes/workers
final class AvatarService
{
    public const PATH_PATTERN = '/^avatars\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.webp$/';

    /** @var array<string, ?string> URLs ja calculadas nesta requisicao. */
    private array $urls = [];

    public function store(PlayerProfile $profile, UploadedFile $file): void
    {
        $bytes = $this->normalize($file);
        $path = config('avatars.directory').'/'.Str::uuid()->toString().'.webp';

        try {
            $saved = $this->disk()->put($path, $bytes, [
                'visibility' => 'private',
                'ContentType' => 'image/webp',
                'CacheControl' => 'private, max-age=31536000, immutable',
            ]);
        } catch (Throwable $e) {
            $saved = false;
            Log::warning('avatar.upload_failed', ['profile_id' => $profile->id, 'error' => $e->getMessage()]);
        }

        if ($saved === false) {
            throw new AvatarException('Não foi possível salvar a foto agora. Tente de novo em instantes.');
        }

        $old = $profile->avatar_path;
        $profile->forceFill(['avatar_path' => $path])->save();
        $this->deleteQuietly($old);
    }

    public function remove(PlayerProfile $profile): void
    {
        $old = $profile->avatar_path;
        if ($old === null) {
            return;
        }

        $profile->forceFill(['avatar_path' => null])->save();
        $this->deleteQuietly($old);
    }

    /** URL para <img src>, ou null (sem foto ou storage indisponivel: a view cai no emoji/inicial). */
    public function url(?string $path): ?string
    {
        if ($path === null || ! self::isValidPath($path)) {
            return null;
        }

        if (! array_key_exists($path, $this->urls)) {
            try {
                $this->urls[$path] = $this->usesS3() ? $this->presignedUrl($path) : route('avatars.show', basename($path));
            } catch (Throwable $e) {
                Log::warning('avatar.url_failed', ['path' => $path, 'error' => $e->getMessage()]);
                $this->urls[$path] = null;
            }
        }

        return $this->urls[$path];
    }

    public static function isValidPath(string $path): bool
    {
        return preg_match(self::PATH_PATTERN, $path) === 1;
    }

    public function usesS3(): bool
    {
        return config('filesystems.disks.'.config('avatars.disk').'.driver') === 's3';
    }

    public function disk(): FilesystemAdapter
    {
        return Storage::disk(config('avatars.disk'));
    }

    /**
     * Origem que precisa entrar no img-src da CSP quando as fotos vem do S3 (null fora do S3).
     * Calculada so com a configuracao do cliente: nao faz chamada de rede nem busca credenciais.
     */
    public function cspOrigin(): ?string
    {
        if (! $this->usesS3()) {
            return null;
        }

        try {
            $config = config('filesystems.disks.'.config('avatars.disk'));
            $endpoint = $this->s3()->getClient()->getEndpoint();
            $host = $endpoint->getHost();
            if (! ($config['use_path_style_endpoint'] ?? false)) {
                $host = $config['bucket'].'.'.$host;
            }

            return $endpoint->getScheme().'://'.$host.($endpoint->getPort() ? ':'.$endpoint->getPort() : '');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Presigned GET com inicio arredondado para a "janela" atual: durante a janela a URL e a mesma
     * (o navegador usa o cache em vez de baixar a foto a cada pagina) e ela vale ate janela + folga.
     */
    private function presignedUrl(string $path): string
    {
        $window = max(1, (int) config('avatars.url_window_minutes')) * 60;
        $start = intdiv(time(), $window) * $window;
        $expires = $start + $window + (int) config('avatars.url_grace_minutes') * 60;

        $disk = $this->s3();
        $client = $disk->getClient();
        $command = $client->getCommand('GetObject', [
            'Bucket' => $disk->getConfig()['bucket'],
            'Key' => $disk->path($path),
        ]);

        return (string) $client->createPresignedRequest($command, $expires, ['start_time' => $start])->getUri();
    }

    private function s3(): AwsS3V3Adapter
    {
        $disk = $this->disk();
        if (! $disk instanceof AwsS3V3Adapter) {
            throw new \LogicException('O disco de avatares não é S3.');
        }

        return $disk;
    }

    private function deleteQuietly(?string $path): void
    {
        if ($path === null || ! self::isValidPath($path)) {
            return;
        }

        try {
            $this->disk()->delete($path);
        } catch (Throwable $e) {
            // A troca ja foi salva; o objeto orfao nao e servido a ninguem e pode ser limpo depois.
            Log::warning('avatar.delete_failed', ['path' => $path, 'error' => $e->getMessage()]);
        }
    }

    /** Decodifica a imagem enviada e devolve um WebP quadrado, sem metadados. */
    private function normalize(UploadedFile $file): string
    {
        $source = $file->getRealPath();
        $info = $source ? @getimagesize($source) : false;
        $types = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
        if ($info === false || ! in_array($info[2], $types, true)) {
            throw new AvatarException('Envie uma imagem JPG, PNG ou WebP.');
        }

        [$width, $height] = $info;
        if ($width * $height > (int) config('avatars.max_pixels')) {
            throw new AvatarException('A imagem tem resolução grande demais (máximo de 16 megapixels).');
        }
        $minSide = (int) config('avatars.min_side');
        if (min($width, $height) < $minSide) {
            throw new AvatarException("A foto precisa ter pelo menos {$minSide}×{$minSide} pixels.");
        }

        $this->ensureMemory($width * $height);

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source),
        };
        if ($image === false) {
            throw new AvatarException('Não foi possível ler a imagem. Tente outro arquivo.');
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            $image = $this->applyExifOrientation($image, $source);
        }

        $size = (int) config('avatars.size');
        $side = min(imagesx($image), imagesy($image));
        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled(
            $canvas, $image, 0, 0,
            intdiv(imagesx($image) - $side, 2), intdiv(imagesy($image) - $side, 2),
            $size, $size, $side, $side,
        );
        unset($image);

        ob_start();
        $ok = imagewebp($canvas, null, (int) config('avatars.quality'));
        $bytes = (string) ob_get_clean();
        unset($canvas);

        if (! $ok || $bytes === '') {
            throw new AvatarException('Não foi possível processar a imagem. Tente outro arquivo.');
        }

        return $bytes;
    }

    /** Fotos de celular guardam a rotacao no EXIF; como o EXIF e descartado, aplicamos antes. */
    private function applyExifOrientation(\GdImage $image, string $source): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = (int) (@exif_read_data($source)['Orientation'] ?? 1);
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated === false ? $image : $rotated;
    }

    /** O GD usa ~4 bytes por pixel (mais a copia de rotacao); garante memoria so durante o upload. */
    private function ensureMemory(int $pixels): void
    {
        $needed = $pixels * 10 + 64 * 1024 * 1024;
        $limit = ini_get('memory_limit');
        if ($limit === false || $limit === '-1') {
            return;
        }

        $current = $this->bytes($limit);
        if ($current < $needed) {
            @ini_set('memory_limit', (string) $needed);
        }
    }

    private function bytes(string $value): int
    {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
