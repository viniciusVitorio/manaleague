<?php

return [
    // Feature flag: desliga rotas/menus do ranked sem apagar dados.
    'enabled' => env('RANKED_ENABLED', true),

    // Formatos 1v1 elegiveis. Commander multiplayer nao concede ELO 1v1.
    'formats' => ['Pauper', 'Standard', 'Modern', 'Pioneer', 'Legacy', 'Vintage', 'Draft'],

    'defaults' => [
        'starting_rating' => 1000,
        'k_factor' => 32,
        'pair_limit_24h' => 3,          // confrontos ranqueados validos por par/formato em 24h
        'provisional_games' => 5,       // selo "Provisório" nas primeiras partidas
        'leaderboard_min_games' => 3,   // minimo para aparecer no ranking publico
        'dispute_grace_hours' => 72,    // prazo para resolver disputas apos o fim da temporada
        'allow_draws' => true,
    ],

    // Busca progressiva da fila: [minutos de espera => diferenca maxima de rating].
    'matchmaking' => [
        'windows' => [0 => 100, 2 => 200, 5 => 300, 8 => 400],
        'wide_gap_warning' => 300,
        'presence_minutes' => 10,       // sem sinal da pagina por 10 min a entrada expira
        'max_queue_minutes' => 120,
        'proposal_seconds' => 180,
    ],

    'ip_retention_days' => 90,
    'rules_version' => 'elo-v1',
];
