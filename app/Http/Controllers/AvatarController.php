<?php

namespace App\Http\Controllers;

use App\Exceptions\AvatarException;
use App\Services\AvatarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AvatarController extends Controller
{
    public function __construct(private readonly AvatarService $avatars) {}

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            // "image" do Laravel ja recusa SVG; mimes confere o tipo pelo conteudo (fileinfo), nao pela extensao.
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.(int) config('avatars.max_kilobytes')],
        ], [
            'photo.required' => 'Escolha uma foto.',
            'photo.image' => 'Envie uma imagem JPG, PNG ou WebP.',
            'photo.mimes' => 'Envie uma imagem JPG, PNG ou WebP.',
            'photo.max' => 'A foto pode ter no máximo '.intdiv((int) config('avatars.max_kilobytes'), 1024).' MB.',
            'photo.uploaded' => 'O envio da foto falhou. Tente um arquivo menor.',
        ]);

        try {
            $this->avatars->store($request->user()->ensurePlayerProfile(), $request->file('photo'));
        } catch (AvatarException $e) {
            return back()->withErrors(['photo' => $e->getMessage()]);
        }

        return redirect()->route('profiles.edit')->with('status', 'Foto de perfil atualizada.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->avatars->remove($request->user()->ensurePlayerProfile());

        return redirect()->route('profiles.edit')->with('status', 'Foto de perfil removida.');
    }

    /**
     * Serve a foto quando o disco e local (dev/testes). Com S3 apenas redireciona para a URL assinada.
     * O nome e um UUID imutavel: pode ser cacheado para sempre.
     */
    public function show(string $file): Response
    {
        $path = config('avatars.directory').'/'.$file;
        abort_unless(AvatarService::isValidPath($path), 404);

        if ($this->avatars->usesS3()) {
            $url = $this->avatars->url($path);
            abort_if($url === null, 404);

            return redirect()->away($url);
        }

        $disk = $this->avatars->disk();
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $file, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
