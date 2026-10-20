<?php

return [
    // Feature flag: desliga rotas/menus do ranked sem apagar dados.
    'enabled' => env('RANKED_ENABLED', true),

    // Formatos 1v1 elegiveis. Commander multiplayer nao concede ELO 1v1.
    'formats' => ['Pauper', 'Standard', 'Modern', 'Pioneer', 'Legacy', 'Vintage', 'Draft'],

    'defaults' => [
        'starting_rating' => 600,       // Ferro II, 0/100 PDL
        'k_factor' => 32,
        'pair_limit_24h' => 3,          // confrontos ranqueados validos por par/formato em 24h
        'provisional_games' => 5,       // selo "Provisório" nas primeiras partidas
        'leaderboard_min_games' => 3,   // minimo de partidas para posicao no snapshot final
        'dispute_grace_hours' => 72,    // prazo para resolver disputas apos o fim da temporada
        'allow_draws' => true,
    ],

    'ip_retention_days' => 90,
    'rules_version' => 'elo-v2', // v2: inicio em 600
];
