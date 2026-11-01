<?php

namespace App\Services\Cards;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente do microsservico de reconhecimento de cartas (scanner/, FastAPI + OpenCV).
 *
 * Seguranca: o scanner devolve SOMENTE IDs do Scryfall. Nome, tipo, custo e imagem sao
 * resolvidos depois pelo ScryfallClient a partir do ID (requisito RS17), entao nem o
 * navegador nem o microsservico podem forjar dados de carta. Todo ID recebido e validado
 * aqui no formato UUID antes de sair deste objeto.
 *
 * O servico roda na rede interna do Docker, sem porta publicada, e exige o segredo
 * compartilhado CARD_SCANNER_TOKEN.
 */
class CardScannerClient
{
    /** Distancia de Hamming (de 256 bits) acima da qual um palpite incerto nem e oferecido. */
    private const MAX_SUGGESTION_DISTANCE = 82;

    private const MAX_SUGGESTIONS = 3;

    public function enabled(): bool
    {
        return $this->url() !== '' && $this->token() !== '';
    }

    /**
     * Reconhece a carta em um frame da camera.
     *
     * @param  string  $image  bytes da imagem (JPEG/PNG/WebP)
     * @param  float|null  $x  posicao do toque (fracao 0-1 da largura)
     * @param  float|null  $y  posicao do toque (fracao 0-1 da altura)
     * @return array{status: 'match'|'unsure'|'no_match'|'unavailable', ids: list<string>}
     */
    public function recognize(string $image, ?float $x = null, ?float $y = null): array
    {
        if (! $this->enabled()) {
            return ['status' => 'unavailable', 'ids' => []];
        }

        $payload = array_filter(
            ['x' => $x, 'y' => $y],
            static fn (?float $value) => $value !== null,
        );

        try {
            $response = $this->request()
                ->attach('image', $image, 'frame.jpg', ['Content-Type' => 'image/jpeg'])
                ->post($this->url().'/recognize', $payload);
        } catch (Throwable $exception) {
            Log::warning('scanner.unreachable', ['error' => $exception->getMessage()]);

            return ['status' => 'unavailable', 'ids' => []];
        }

        if ($response->failed()) {
            // 401 aqui significa segredo trocado em um dos lados: erro de configuracao, nao do usuario.
            Log::warning('scanner.request_failed', ['status' => $response->status()]);

            return ['status' => 'unavailable', 'ids' => []];
        }

        return $this->parse((array) $response->json());
    }

    /** @return array{status: 'match'|'unsure'|'no_match'|'unavailable', ids: list<string>} */
    private function parse(array $body): array
    {
        return match ($body['status'] ?? null) {
            'match' => $this->ids([$body['card'] ?? []], 'match'),
            'unsure' => $this->ids((array) ($body['suggestions'] ?? []), 'unsure'),
            'no_match' => ['status' => 'no_match', 'ids' => []],
            default => ['status' => 'unavailable', 'ids' => []],
        };
    }

    /**
     * Mantem apenas IDs em formato UUID e dentro da distancia aceitavel; sem nenhum ID valido,
     * o resultado vira "nao reconhecida" em vez de um erro para o usuario.
     *
     * @param  list<mixed>  $cards
     * @return array{status: 'match'|'unsure'|'no_match', ids: list<string>}
     */
    private function ids(array $cards, string $status): array
    {
        $ids = collect($cards)
            ->filter(fn ($card) => is_array($card) && is_string($card['scryfall_id'] ?? null))
            ->filter(fn (array $card) => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $card['scryfall_id']) === 1)
            ->filter(fn (array $card) => $status === 'match' || (int) ($card['distance'] ?? 999) <= self::MAX_SUGGESTION_DISTANCE)
            ->pluck('scryfall_id')
            ->map(fn (string $id) => strtolower($id))
            ->unique()
            ->take(self::MAX_SUGGESTIONS)
            ->values()
            ->all();

        return $ids === [] ? ['status' => 'no_match', 'ids' => []] : ['status' => $status, 'ids' => $ids];
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders(['X-Scanner-Token' => $this->token(), 'Accept' => 'application/json'])
            ->timeout((int) config('services.scanner.timeout', 20))
            ->connectTimeout(3);
    }

    private function url(): string
    {
        return rtrim((string) config('services.scanner.url'), '/');
    }

    private function token(): string
    {
        return (string) config('services.scanner.token');
    }
}
