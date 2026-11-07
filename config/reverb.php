<?php

/*
 * Tempo real (Laravel Reverb): um servidor WebSocket proprio, no mesmo Compose, que so avisa o navegador
 * "esta tela mudou". O dado continua vindo dos endpoints HTTP de sempre (com autorizacao e allowlist).
 *
 * Chave e segredo saem do APP_KEY por HMAC quando REVERB_APP_KEY/REVERB_APP_SECRET nao sao definidos:
 * o app e o servidor Reverb leem o mesmo APP_KEY, entao nao existe segredo novo para guardar no deploy.
 * A chave e publica (vai na pagina); o segredo assina as mensagens app → Reverb e a autorizacao de canais.
 */
$derived = static fn (string $purpose, int $length): string => substr(hash_hmac('sha256', 'reverb:'.$purpose, (string) env('APP_KEY', '')), 0, $length);

$appHost = parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost';
$origins = array_values(array_filter(array_map('trim', explode(',', (string) env('REVERB_ALLOWED_ORIGINS', '')))));
if ($origins === []) {
    // Mesmo dominio do app (com e sem www); nada de '*': outro site nao abre conexao em nome do visitante.
    $bare = preg_replace('/^www\./', '', $appHost);
    $origins = array_values(array_unique([$appHost, $bare, 'www.'.$bare]));
}

return [

    'default' => env('REVERB_SERVER', 'reverb'),

    'servers' => [

        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_HOST'),
            'options' => [
                'tls' => [],
            ],
            // Corpo das chamadas da API HTTP (app → Reverb). Os avisos sao pequenos.
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 20_000),
            'scaling' => [
                'enabled' => false,
                'channel' => 'reverb',
                'server' => [],
            ],
            'pulse_ingest_interval' => 15,
            'telescope_ingest_interval' => 15,
        ],

    ],

    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                'key' => env('REVERB_APP_KEY') ?: 'ml'.$derived('key', 18),
                'secret' => env('REVERB_APP_SECRET') ?: $derived('secret', 40),
                'app_id' => env('REVERB_APP_ID', 'manaleague'),
                // Endereco interno usado pelo Laravel para publicar (rede do Docker, sem TLS).
                'options' => [
                    'host' => env('REVERB_HOST', '127.0.0.1'),
                    'port' => env('REVERB_PORT', 8080),
                    'scheme' => env('REVERB_SCHEME', 'http'),
                    'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
                ],
                'allowed_origins' => $origins,
                'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
                'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                // Teto de conexoes simultaneas (t3.micro): acima disso o navegador fica no polling.
                'max_connections' => (int) env('REVERB_APP_MAX_CONNECTIONS', 500),
                // O navegador so assina canais e responde ping: mensagens pequenas bastam.
                'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 2_000),
                // Sem "client events" (whisper): o navegador nunca fala com outro navegador pelo socket.
                'accept_client_events_from' => 'none',
                'rate_limiting' => [
                    'enabled' => true,
                    'max_attempts' => (int) env('REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS', 60),
                    'decay_seconds' => 60,
                    'terminate_on_limit' => true,
                ],
            ],
        ],

    ],

    // Navegador: liga o cliente so com BROADCAST_CONNECTION=reverb. Sem URL, conecta na mesma origem da pagina
    // (o Caddy encaminha /app/* para o Reverb). REVERB_CLIENT_URL serve para dev sem Caddy (ws://127.0.0.1:8080).
    'client' => [
        'enabled' => env('BROADCAST_CONNECTION') === 'reverb',
        'url' => env('REVERB_CLIENT_URL'),
    ],

];
