<?php

namespace App\Support;

/**
 * Cores que o jogador pode escolher para o fundo do seu lado no contador de vida (Preferências do perfil).
 * No branco o contador troca o texto para escuro (life-counter.js decide pela luminância). Sem escolha = cor automática do lugar.
 */
final class LifeColor
{
    public const ALL = [
        '#7c3aed' => 'Roxo', '#4f46e5' => 'Índigo', '#2563eb' => 'Azul', '#0891b2' => 'Ciano',
        '#0d9488' => 'Turquesa', '#059669' => 'Verde', '#65a30d' => 'Lima', '#d97706' => 'Âmbar',
        '#ea580c' => 'Laranja', '#dc2626' => 'Vermelho', '#db2777' => 'Rosa', '#c026d3' => 'Magenta',
        '#92400e' => 'Marrom', '#78716c' => 'Pedra', '#475569' => 'Ardósia', '#27272a' => 'Preto',
        '#f4f4f5' => 'Branco',
    ];

    /** Cor salva, só se ainda estiver na paleta (uma cor removida volta para a automática). */
    public static function of(?\App\Models\User $user): ?string
    {
        $color = $user?->life_color;

        return $color !== null && isset(self::ALL[$color]) ? $color : null;
    }
}
