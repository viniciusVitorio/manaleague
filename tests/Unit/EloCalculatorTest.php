<?php

namespace Tests\Unit;

use App\Services\Ranked\EloCalculator;
use App\Services\Ranked\RankTierResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EloCalculatorTest extends TestCase
{
    /** Tabela de exemplos da especificacao (A = 1000, K = 32). */
    public static function specTable(): array
    {
        return [
            'favorito vs 800' => [800, 8, -24],
            'equilibrado 1000' => [1000, 16, -16],
            'zebra vs 1200' => [1200, 24, -8],
        ];
    }

    #[DataProvider('specTable')]
    public function test_spec_examples(int $opponent, int $ifWin, int $ifLose): void
    {
        $elo = new EloCalculator;
        $win = $elo->calculate(1000, $opponent, 1.0, 32);
        $lose = $elo->calculate(1000, $opponent, 0.0, 32);
        $this->assertSame($ifWin, $win['delta_a']);
        $this->assertSame(-$ifWin, $win['delta_b']);
        $this->assertSame($ifLose, $lose['delta_a']);
        $this->assertSame(-$ifLose, $lose['delta_b']);
    }

    public function test_draw_between_equals_changes_nothing_and_favourite_loses_on_draw(): void
    {
        $elo = new EloCalculator;
        $this->assertSame(0, $elo->calculate(1000, 1000, 0.5, 32)['delta_a']);
        $r = $elo->calculate(1200, 1000, 0.5, 32);
        $this->assertSame(-8, $r['delta_a']);
        $this->assertSame(8, $r['delta_b']);
    }

    public function test_zero_sum_holds_for_every_rounding_case(): void
    {
        $elo = new EloCalculator;
        foreach (range(0, 2000, 37) as $ra) {
            foreach ([0.0, 0.5, 1.0] as $s) {
                foreach ([16, 24, 32, 40] as $k) {
                    $r = $elo->calculate($ra, 1013, $s, $k);
                    $this->assertSame(0, $r['delta_a'] + $r['delta_b']);
                    $this->assertEqualsWithDelta(1.0, $r['expected_a'] + $r['expected_b'], 1e-9);
                }
            }
        }
    }

    public function test_expected_score_and_k_factor_are_used(): void
    {
        $elo = new EloCalculator;
        $r = $elo->calculate(1000, 800, 1.0, 16);
        $this->assertEqualsWithDelta(0.7597, $r['expected_a'], 0.0001);
        $this->assertSame(4, $r['delta_a']);
    }

    public function test_invalid_inputs_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EloCalculator)->calculate(1000, 1000, 0.7, 32);
    }

    public function test_tiers_and_pdl_derive_from_rating(): void
    {
        $t = new RankTierResolver;
        $this->assertSame(['Prata II', 0], [$t->resolve(1000)['division'], $t->resolve(1000)['pdl']]);
        $this->assertSame(['Prata I', 28], [$t->resolve(1128)['division'], $t->resolve(1128)['pdl']]);
        $this->assertSame('Bronze I', $t->resolve(999)['division']);
        $this->assertSame('Bronze II', $t->resolve(800)['division']);
        $this->assertSame('Ferro I', $t->resolve(799)['division']);
        $this->assertSame('Ferro II', $t->resolve(120)['division']);
        $this->assertSame(0, $t->resolve(120)['pdl']);
        $this->assertSame('Ouro II', $t->resolve(1200)['division']);
        $this->assertSame('Platina I', $t->resolve(1599)['division']);
        $this->assertSame('Diamante II', $t->resolve(1600)['division']);
        $m = $t->resolve(1875);
        $this->assertSame(['Mestre', 75, null], [$m['division'], $m['pdl'], $m['pdl_max']]);
    }
}
