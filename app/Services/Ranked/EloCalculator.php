<?php

namespace App\Services\Ranked;

use InvalidArgumentException;

/**
 * Elo classico, unico e reproduzivel:
 *   E_A = 1 / (1 + 10^((R_B - R_A) / 400))
 *   delta_A = round(K * (S_A - E_A));  delta_B = -delta_A  (soma zero, um so arredondamento)
 */
final class EloCalculator
{
    public function expected(int $ratingA, int $ratingB): float
    {
        return 1 / (1 + 10 ** (($ratingB - $ratingA) / 400));
    }

    /** @return array{expected_a: float, expected_b: float, delta_a: int, delta_b: int} */
    public function calculate(int $ratingA, int $ratingB, float $scoreA, int $k): array
    {
        if (! in_array($scoreA, [0.0, 0.5, 1.0], true)) {
            throw new InvalidArgumentException('Resultado deve ser 1 (vitória), 0.5 (empate) ou 0 (derrota).');
        }
        if ($k < 1 || $k > 100) {
            throw new InvalidArgumentException('Fator K fora do intervalo permitido.');
        }
        $expectedA = $this->expected($ratingA, $ratingB);
        $deltaA = (int) round($k * ($scoreA - $expectedA));

        return ['expected_a' => $expectedA, 'expected_b' => 1 - $expectedA, 'delta_a' => $deltaA, 'delta_b' => -$deltaA];
    }
}
