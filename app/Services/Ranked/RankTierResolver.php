<?php

namespace App\Services\Ranked;

/**
 * Patente e PDL derivam SEMPRE do rating (nada persistido em duplicidade).
 * PDL = progresso 0-99 dentro de uma divisao de 100 pontos.
 */
final class RankTierResolver
{
    /** Divisoes padrao: [nome da divisao, patente, chave css, minimo, maximo|null]. */
    public const DEFAULT_DIVISIONS = [
        ['Ferro II', 'Ferro', 'ferro', 0, 699],
        ['Ferro I', 'Ferro', 'ferro', 700, 799],
        ['Bronze II', 'Bronze', 'bronze', 800, 899],
        ['Bronze I', 'Bronze', 'bronze', 900, 999],
        ['Prata II', 'Prata', 'prata', 1000, 1099],
        ['Prata I', 'Prata', 'prata', 1100, 1199],
        ['Ouro II', 'Ouro', 'ouro', 1200, 1299],
        ['Ouro I', 'Ouro', 'ouro', 1300, 1399],
        ['Platina II', 'Platina', 'platina', 1400, 1499],
        ['Platina I', 'Platina', 'platina', 1500, 1599],
        ['Diamante II', 'Diamante', 'diamante', 1600, 1699],
        ['Diamante I', 'Diamante', 'diamante', 1700, 1799],
        ['Mestre', 'Mestre', 'mestre', 1800, null],
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
        // Divisoes largas (ex.: Ferro II 0-699) mostram progresso so nos ultimos 100 pontos.
        $pdl = max(0, min(99, $rating - ($max - 99)));

        return ['division' => $name, 'tier' => $tier, 'key' => $key, 'pdl' => $pdl, 'pdl_max' => 100, 'min' => $min, 'max' => $max];
    }
}
