<?php

namespace App\Services\Ranked;

/**
 * Patente e PDL derivam SEMPRE do rating (nada persistido em duplicidade).
 * PDL = pontos de ELO ja feitos dentro da divisao (0 ate o tamanho dela - 1).
 */
final class RankTierResolver
{
    /** Divisoes padrao: [nome da divisao, patente, chave css, minimo, maximo|null].
     * Escala curta (divisoes de 25) para um grupo pequeno de jogadores. */
    public const DEFAULT_DIVISIONS = [
        ['Ferro II', 'Ferro', 'ferro', 0, 624],
        ['Ferro I', 'Ferro', 'ferro', 625, 649],
        ['Bronze II', 'Bronze', 'bronze', 650, 674],
        ['Bronze I', 'Bronze', 'bronze', 675, 699],
        ['Prata II', 'Prata', 'prata', 700, 724],
        ['Prata I', 'Prata', 'prata', 725, 749],
        ['Ouro II', 'Ouro', 'ouro', 750, 774],
        ['Ouro I', 'Ouro', 'ouro', 775, 799],
        ['Platina II', 'Platina', 'platina', 800, 824],
        ['Platina I', 'Platina', 'platina', 825, 849],
        ['Diamante II', 'Diamante', 'diamante', 850, 874],
        ['Diamante I', 'Diamante', 'diamante', 875, 899],
        ['Mestre', 'Mestre', 'mestre', 900, null],
    ];

    public function __construct(private readonly array $divisions = self::DEFAULT_DIVISIONS) {}

    /** @return array{division: string, tier: string, key: string, pdl: int, pdl_max: ?int, min: int, max: ?int} */
    public function resolve(int $rating): array
    {
        $rating = max(0, $rating);
        $match = $this->divisions[0];
        foreach ($this->divisions as $division) {
            if ($rating >= $division[3]) {
                $match = $division;
            }
        }
        [$name, $tier, $key, $min, $max] = $match;
        if ($max === null) {
            return ['division' => $name, 'tier' => $tier, 'key' => $key, 'pdl' => $rating - $min, 'pdl_max' => null, 'min' => $min, 'max' => null];
        }
        // Tamanho da divisao; a primeira (larga, ex.: Ferro II 0-624) usa o tamanho padrao
        // e mostra progresso so nos ultimos pontos antes de subir.
        $size = $max - $min + 1;
        if ($min === 0 && isset($this->divisions[1][4])) {
            $size = min($size, $this->divisions[1][4] - $this->divisions[1][3] + 1);
        }
        $pdl = max(0, min($size - 1, $rating - ($max - $size + 1)));

        return ['division' => $name, 'tier' => $tier, 'key' => $key, 'pdl' => $pdl, 'pdl_max' => $size, 'min' => $min, 'max' => $max];
    }
}
