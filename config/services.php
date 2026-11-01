<?php

return [

    /*
    | Scanner de cartas — microsservico de visao computacional (pasta scanner/).
    |
    | Roda na rede interna do Docker, sem porta publicada, e so responde com o segredo
    | compartilhado CARD_SCANNER_TOKEN. Sem URL ou sem token o recurso fica desligado:
    | o botao "Escanear carta" nao aparece e a rota responde 503.
    */
    'scanner' => [
        'url' => env('CARD_SCANNER_URL'),
        'token' => env('CARD_SCANNER_TOKEN'),
        // Reconhecer custa ~0,3 s de CPU; 20 s cobre fila na t3.micro sem travar o PHP-FPM.
        'timeout' => (int) env('CARD_SCANNER_TIMEOUT', 20),
    ],

    /*
    | Amazon SES — envio de e-mails transacionais (redefinicao e alteracao de senha).
    |
    | Em producao NAO configure AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY: a EC2 recebe uma
    | IAM Role (terraform/ses.tf) e o SDK obtem credenciais temporarias via IMDSv2.
    | As chaves estaticas existem apenas como alternativa para desenvolvimento.
    */
    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_SES_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
        'options' => array_filter([
            // Configuration set: metricas de entrega e supressao automatica de bounces/reclamacoes.
            'ConfigurationSetName' => env('AWS_SES_CONFIGURATION_SET'),
        ]),
    ],

];
