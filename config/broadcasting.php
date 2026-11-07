<?php

$reverb = require __DIR__.'/reverb.php';
$app = $reverb['apps']['apps'][0];

return [

    // null = desligado (testes e quem nao sobe o Reverb): as telas continuam no polling.
    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => $app['key'],
            'secret' => $app['secret'],
            'app_id' => $app['app_id'],
            'options' => $app['options'],
            // Reverb fora do ar nao pode travar o PHP: desiste rapido (o polling cobre).
            'client_options' => [
                'connect_timeout' => 1,
                'timeout' => 2,
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
