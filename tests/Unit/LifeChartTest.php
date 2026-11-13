<?php

namespace Tests\Unit;

use App\Support\LifeChart;
use PHPUnit\Framework\TestCase;

/** Geometria do gráfico de vida: lances ligados, vida inicial no meio do eixo, avatares sem encostar e tempos legíveis. */
class LifeChartTest extends TestCase
{
    public function test_each_change_is_a_point_and_the_starting_life_sits_in_the_middle(): void
    {
        $chart = LifeChart::game(['winner' => 0, 'points' => [[50, 1, 15, 0], [100, 1, 0, 0]], 'out' => []], 20, ['Ana', 'Bia']);

        $this->assertSame(100, $chart['duration']);
        // Eixo de 0 a 40 de 10 em 10: os 20 do começo ficam no meio (50%), com espaço para ganhar vida.
        $this->assertSame([0, 10, 20, 30, 40], array_column($chart['ticks'], 'value'));
        $this->assertSame([100.0, 50.0, 0.0], [$chart['ticks'][0]['y'], $chart['ticks'][2]['y'], $chart['ticks'][4]['y']]);
        $this->assertSame([false, false, true, false, false], array_column($chart['ticks'], 'start'));
        $this->assertSame('M0 500 H1000', $chart['series'][0]['path']);
        $this->assertSame('M0 500 L500 625 L1000 1000', $chart['series'][1]['path']);
        $this->assertSame([['x' => 50.0, 'y' => 62.5], ['x' => 100.0, 'y' => 100.0]], $chart['series'][1]['marks']);
        $this->assertSame([], $chart['series'][0]['marks']);
        $this->assertSame([0, 20, 0], [$chart['series'][1]['end']['value'], $chart['series'][0]['end']['value'], $chart['winner']]);
        // Leitura com o dedo: depois de cada lance, a vida de todos.
        $this->assertSame([[20, 20], [20, 15], [20, 0]], array_column($chart['steps'], 'values'));
        $this->assertSame(['0:50', -5], [$chart['rows'][0]['time'], $chart['rows'][0]['delta']]);
    }

    public function test_negative_life_extends_the_axis_and_close_end_avatars_are_pushed_apart(): void
    {
        $chart = LifeChart::game(['winner' => 1, 'points' => [[30, 0, -3, 0], [31, 1, -2, 0]], 'out' => []], 20, ['Ana', 'Bia']);

        $this->assertSame([-10, 50], [$chart['ticks'][0]['value'], end($chart['ticks'])['value']]);
        [$one, $two] = [$chart['series'][0]['labelY'], $chart['series'][1]['labelY']];
        $this->assertGreaterThanOrEqual(LifeChart::END_GAP, abs($one - $two));
        $this->assertLessThanOrEqual(100, max($one, $two));
    }

    public function test_eliminated_player_line_stops_at_the_elimination(): void
    {
        $chart = LifeChart::game(['winner' => null, 'points' => [[60, 2, 0, 0], [120, 0, 30, 0]], 'out' => [[60, 2]]], 40, ['A', 'B', 'C']);

        $this->assertTrue($chart['series'][2]['out']);
        $this->assertSame(50.0, $chart['series'][2]['end']['x']);
        $this->assertStringEndsWith('L500 1000', $chart['series'][2]['path']);
        // Quem saiu fica no ponto da eliminação (não entra no afastamento dos avatares de quem terminou).
        $this->assertSame(100.0, $chart['series'][2]['labelY']);
        $this->assertSame('M0 500 H1000', $chart['series'][1]['path']);
    }

    public function test_durations_and_moments_are_readable(): void
    {
        $this->assertSame(['45 s', '12 min', '1 h 05'], [LifeChart::minutes(45), LifeChart::minutes(720), LifeChart::minutes(3900)]);
        $this->assertSame(['3:07', '1:02:07'], [LifeChart::clock(187), LifeChart::clock(3727)]);
    }
}
