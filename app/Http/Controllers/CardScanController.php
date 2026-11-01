<?php

namespace App\Http\Controllers;

use App\Services\Cards\CardScannerClient;
use App\Services\ScryfallClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Escanear carta pela camera: o navegador manda um frame, o microsservico de visao
 * (pasta scanner/) devolve apenas IDs e o Scryfall devolve os dados oficiais.
 *
 * A imagem nao e gravada em disco nem no banco: e lida, enviada ao scanner e descartada
 * no fim da requisicao (a foto da mesa de alguem nao e dado que o projeto precise guardar).
 */
class CardScanController extends Controller
{
    public function __construct(
        private readonly CardScannerClient $scanner,
        private readonly ScryfallClient $scryfall,
    ) {}

    public function store(Request $request): JsonResponse
    {
        if (! $this->scanner->enabled()) {
            return response()->json(['status' => 'unavailable', 'message' => 'O reconhecimento de cartas não está configurado neste servidor.'], 503);
        }

        $validated = $request->validate([
            // 4 MB cobre um frame 1080p em JPEG com folga; o scanner recusa acima de 6 MB.
            'image' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:4096'],
            'x' => ['nullable', 'numeric', 'between:0,1'],
            'y' => ['nullable', 'numeric', 'between:0,1'],
        ]);

        $result = $this->scanner->recognize(
            (string) file_get_contents($validated['image']->getRealPath()),
            isset($validated['x']) ? (float) $validated['x'] : null,
            isset($validated['y']) ? (float) $validated['y'] : null,
        );

        // Os dados da carta vem do Scryfall pelo ID (RS17): o scanner nunca envia nome ou imagem.
        $cards = collect($result['ids'])
            ->map(fn (string $id) => $this->scryfall->card($id))
            ->filter()
            ->values();

        if ($cards->isEmpty()) {
            return response()->json([
                'status' => $result['status'] === 'unavailable' ? 'unavailable' : 'no_match',
                'message' => $result['status'] === 'unavailable'
                    ? 'O reconhecimento está indisponível agora. Tente de novo em instantes ou busque pelo nome.'
                    : 'Não reconheci a carta. Toque sobre a carta inteira, com boa luz e sem reflexo.',
            ], $result['status'] === 'unavailable' ? 503 : 200);
        }

        if ($result['status'] === 'match') {
            return response()->json(['status' => 'match', 'card' => $cards->first()]);
        }

        return response()->json([
            'status' => 'unsure',
            'message' => 'Não tenho certeza. É uma destas?',
            'suggestions' => $cards->all(),
        ]);
    }
}
