<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente da API publica do Scryfall (https://scryfall.com/docs/api).
 *
 * Seguranca: o navegador nunca envia dados de carta para o servidor; ele envia apenas o ID e o
 * servidor busca os dados oficiais aqui. Assim nome, tipo, custo e imagem nao podem ser forjados.
 * As respostas sao cacheadas para respeitar o limite de requisicoes do Scryfall.
 */
class ScryfallClient
{
    private const BASE = 'https://api.scryfall.com';

    /** @return list<array<string, mixed>> */
    public function search(string $query, int $limit = 12): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $key = 'scryfall:search:'.sha1(mb_strtolower($query));
        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $response = $this->send(fn (PendingRequest $http) => $http->get(self::BASE.'/cards/search', [
            'q' => $query.' game:paper', 'unique' => 'cards', 'order' => 'name', 'include_multilingual' => 'true',
        ]));

        // Falha de rede nao e cacheada: a proxima busca tenta de novo.
        if (! $response) {
            return [];
        }

        $needle = mb_strtolower($query);
        $cards = collect($response['data'] ?? [])->map(fn (array $card) => $this->normalize($card))
            // Relevancia: nome exato, depois "comeca com", depois o resto (ordem alfabetica do Scryfall).
            ->sortBy(fn (array $card) => match (true) {
                mb_strtolower($card['name']) === $needle || mb_strtolower((string) $card['printed_name']) === $needle => 0,
                str_starts_with(mb_strtolower($card['name']), $needle) || str_starts_with(mb_strtolower((string) $card['printed_name']), $needle) => 1,
                default => 2,
            })
            ->take($limit)->values()->all();
        Cache::put($key, $cards, now()->addHours(12));

        return $cards;
    }

    /** @return array<string, mixed>|null */
    public function card(string $id): ?array
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            return null;
        }

        $key = 'scryfall:card:'.strtolower($id);
        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $response = $this->send(fn (PendingRequest $http) => $http->get(self::BASE.'/cards/'.strtolower($id)));
        if (! $response || ! isset($response['id'])) {
            return null;
        }

        $card = $this->normalize($response);
        Cache::put($key, $card, now()->addDays(7));

        return $card;
    }

    /**
     * Resolve uma lista de cartas (importacao de decklist) em lotes de 75 (limite da API).
     * Cada item e um nome ou ['name' => ..., 'set' => 'LTR']; com set, busca aquela edicao.
     * Se o set informado nao existir para a carta, tenta de novo so pelo nome.
     * Use ScryfallClient::pick() para ler o resultado.
     *
     * @param  list<string|array{name:string, set?:?string}>  $cards
     * @return array{found: array<string, array<string, mixed>>, missing: list<string>}
     */
    public function collection(array $cards): array
    {
        $identifiers = collect($cards)
            ->map(fn ($card) => is_array($card)
                ? array_filter(['name' => (string) $card['name'], 'set' => isset($card['set']) ? strtolower((string) $card['set']) : null])
                : ['name' => (string) $card])
            ->unique(fn (array $identifier) => self::key($identifier['name'], $identifier['set'] ?? null))
            ->values()->all();

        $found = [];
        $notFound = $this->fetchCollection($identifiers, $found);

        // Set errado ou inexistente para a carta: busca qualquer edicao pelo nome.
        $retry = collect($notFound)->filter(fn (array $identifier) => isset($identifier['set']))
            ->map(fn (array $identifier) => ['name' => $identifier['name']])
            ->unique('name')->reject(fn (array $identifier) => isset($found[self::key($identifier['name'])]))->values()->all();
        $missing = collect($notFound)->reject(fn (array $identifier) => isset($identifier['set']))
            ->concat($retry ? $this->fetchCollection($retry, $found) : [])
            ->pluck('name')->filter()->values()->all();

        return ['found' => $found, 'missing' => $missing];
    }

    /**
     * Busca impressões pelo ID do Scryfall (em lotes de 75).
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>> cartas normalizadas, indexadas pelo ID em minúsculas
     */
    public function byIds(array $ids): array
    {
        $cards = [];
        foreach (array_chunk(array_values(array_unique(array_map('strtolower', $ids))), 75) as $chunk) {
            $identifiers = array_map(fn (string $id) => ['id' => $id], $chunk);
            $response = $this->send(fn (PendingRequest $http) => $http->post(self::BASE.'/cards/collection', ['identifiers' => $identifiers]));
            foreach ($response['data'] ?? [] as $card) {
                $normalized = $this->normalize($card);
                $cards[strtolower($normalized['scryfall_id'])] = $normalized;
            }
        }

        return $cards;
    }

    /** Carta encontrada por collection(): a edicao pedida, ou qualquer edicao com aquele nome. */
    public static function pick(array $found, string $name, ?string $set = null): ?array
    {
        return ($set ? $found[self::key($name, $set)] ?? null : null) ?? $found[self::key($name)] ?? null;
    }

    private static function key(string $name, ?string $set = null): string
    {
        return mb_strtolower(trim($name)).($set ? '|'.strtoupper($set) : '');
    }

    /**
     * @param  list<array{name:string, set?:string}>  $identifiers
     * @param  array<string, array<string, mixed>>  $found  preenchido por referencia
     * @return list<array{name:string, set?:string}> identificadores nao encontrados
     */
    private function fetchCollection(array $identifiers, array &$found): array
    {
        $notFound = [];

        foreach (array_chunk($identifiers, 75) as $chunk) {
            $response = $this->send(fn (PendingRequest $http) => $http->post(self::BASE.'/cards/collection', ['identifiers' => $chunk]));

            if (! $response) {
                array_push($notFound, ...$chunk);

                continue;
            }

            foreach ($response['data'] ?? [] as $card) {
                $normalized = $this->normalize($card);
                // Indexa pelo nome completo e pela primeira face ("Fable of the Mirror-Breaker // Reflection..."),
                // com e sem o set, para atender pedidos com e sem edicao.
                foreach ([$normalized['name'], trim(explode('//', $normalized['name'])[0])] as $name) {
                    $found[self::key($name)] ??= $normalized;
                    if ($normalized['set_code']) $found[self::key($name, $normalized['set_code'])] = $normalized;
                }
            }

            foreach ($response['not_found'] ?? [] as $identifier) {
                if (isset($identifier['name'])) {
                    $notFound[] = array_filter(['name' => (string) $identifier['name'], 'set' => $identifier['set'] ?? null]);
                }
            }
        }

        return $notFound;
    }

    /** @return array<string, mixed> */
    private function normalize(array $card): array
    {
        $face = $card['card_faces'][0] ?? [];
        $images = $card['image_uris'] ?? $face['image_uris'] ?? [];

        return [
            'scryfall_id' => $card['id'],
            'name' => $card['name'],
            'printed_name' => $card['printed_name'] ?? null,
            'mana_cost' => $card['mana_cost'] ?? $face['mana_cost'] ?? null,
            'cmc' => (float) ($card['cmc'] ?? 0),
            'type_line' => $card['type_line'] ?? $face['type_line'] ?? null,
            'color_identity' => implode('', $card['color_identity'] ?? []) ?: null,
            'rarity' => $card['rarity'] ?? null,
            'set_code' => isset($card['set']) ? strtoupper($card['set']) : null,
            'image_url' => $this->safeImage($images['normal'] ?? null),
            'art_url' => $this->safeImage($images['art_crop'] ?? null),
            'legal_commander' => ($card['legalities']['commander'] ?? null) === 'legal',
            'price_usd' => isset($card['prices']['usd']) && is_numeric($card['prices']['usd']) ? (float) $card['prices']['usd'] : null,
            'price_usd_foil' => isset($card['prices']['usd_foil']) && is_numeric($card['prices']['usd_foil']) ? (float) $card['prices']['usd_foil'] : null,
        ];
    }

    /** Aceita apenas imagens do CDN oficial (a CSP tambem so libera esse host). */
    private function safeImage(?string $url): ?string
    {
        return $url && preg_match('#^https://cards\.scryfall\.io/[A-Za-z0-9/._\-]+(\?[0-9]+)?$#', $url) ? $url : null;
    }

    private function send(callable $request): ?array
    {
        try {
            $http = Http::withHeaders(['User-Agent' => 'ManaLeague/1.0 (+https://manaleague.com.br)', 'Accept' => 'application/json'])
                ->timeout(8)->retry(2, 300, throw: false);
            $response = $request($http);

            if ($response->status() === 404) {
                return Arr::wrap($response->json()) + ['data' => []];
            }
            if ($response->failed()) {
                Log::warning('scryfall.request_failed', ['status' => $response->status()]);

                return null;
            }

            return $response->json();
        } catch (Throwable $exception) {
            Log::warning('scryfall.unreachable', ['error' => $exception->getMessage()]);

            return null;
        }
    }
}
