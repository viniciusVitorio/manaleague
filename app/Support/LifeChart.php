<?php

namespace App\Support;

/**
 * Geometria do gráfico de vida de um game (sem estado, sem banco).
 * O desenho usa um quadro 0..1000 nos dois eixos (o SVG estica com preserveAspectRatio="none" e a linha não engrossa);
 * rótulos, marcadores e avatares ficam em HTML, posicionados em %, para não deformar no celular.
 */
class LifeChart
{
    /** Acima disso o game vira só linha (marcador em cada lance embolaria o desenho). */
    public const MAX_MARKS = 80;

    /** Distância mínima, em % da altura, entre os avatares do fim das linhas. */
    public const END_GAP = 15;

    /**
     * @param  array{winner: int|null, points: list<array{int, int, int, int}>, out?: list<array{int, int}>}  $game
     * @param  list<string>  $names  nome de cada lugar, na ordem do contador
     * @return array{duration: int, ticks: list<array{value: int, y: float, start: bool}>, xTicks: list<array{label: string, x: float}>,
     *     series: list<array{seat: int, name: string, path: string, marks: list<array{x: float, y: float}>, end: array{x: float, y: float, value: int}, labelY: float, out: bool}>,
     *     steps: list<array{t: int, values: list<int>}>, rows: list<array{time: string, seat: int, name: string, life: int, delta: int, poison: int}>,
     *     winner: int|null}
     */
    public static function game(array $game, int $startingLife, array $names): array
    {
        $seats = count($names);
        $points = $game['points'];
        $outAt = [];
        foreach ($game['out'] ?? [] as [$t, $s]) {
            $outAt[$s] = $t;
        }
        $duration = max(1, (int) max([0, ...array_column($points, 0), ...array_values($outAt)]));

        // Linha do tempo: depois de cada ponto, a vida de todos (alimenta a leitura com o dedo e a tabela).
        $life = array_fill(0, $seats, $startingLife);
        $steps = [['t' => 0, 'values' => $life]];
        $rows = [];
        $history = array_fill(0, $seats, [[0, $startingLife]]);
        foreach ($points as [$t, $s, $l, $p]) {
            $rows[] = ['time' => self::clock($t), 'seat' => $s, 'name' => $names[$s], 'life' => $l, 'delta' => $l - $life[$s], 'poison' => $p];
            $life[$s] = $l;
            $history[$s][] = [$t, $l];
            if (end($steps)['t'] === $t) {
                array_pop($steps);
            }
            $steps[] = ['t' => $t, 'values' => $life];
        }

        // A vida inicial fica no meio do eixo: sobra espaço para ganhar vida, não só para perder.
        $all = array_merge(...array_map(fn ($h) => array_column($h, 1), $history));
        $low = min(0, ...$all);
        [$yMin, $yMax, $tick] = self::range($low, max($startingLife + ($startingLife - $low), ...$all));
        $x = fn (int $t) => round($t / $duration * 1000, 1);
        $y = fn (int $l) => round(($yMax - $l) / ($yMax - $yMin) * 1000, 1);
        $marked = count($points) <= self::MAX_MARKS;

        $series = [];
        foreach ($history as $seat => $line) {
            $endT = $outAt[$seat] ?? $duration;
            $path = 'M0 '.$y($line[0][1]);
            $marks = [];
            foreach (array_slice($line, 1) as [$t, $l]) {
                $path .= ' L'.$x($t).' '.$y($l);
                if ($marked) {
                    $marks[] = ['x' => $x($t) / 10, 'y' => $y($l) / 10];
                }
            }
            if (end($line)[0] < $endT) {
                $path .= ' H'.$x($endT);
            }
            $last = end($line)[1];
            $series[] = ['seat' => $seat, 'name' => $names[$seat], 'path' => $path, 'marks' => $marks, 'out' => isset($outAt[$seat]),
                'end' => ['x' => $x($endT) / 10, 'y' => $y($last) / 10, 'value' => $last], 'labelY' => $y($last) / 10];
        }
        self::spreadEnds($series);

        $ticks = [];
        for ($v = $yMin; $v <= $yMax; $v += $tick) {
            $ticks[] = ['value' => $v, 'y' => $y($v) / 10, 'start' => $v === $startingLife];
        }

        return [
            'duration' => $duration,
            'ticks' => $ticks,
            'xTicks' => [['label' => '0', 'x' => 0.0], ['label' => self::minutes(intdiv($duration, 2)), 'x' => 50.0], ['label' => self::minutes($duration), 'x' => 100.0]],
            'series' => $series,
            'steps' => $steps,
            'rows' => $rows,
            'winner' => $game['winner'],
        ];
    }

    /** Duração legível: "45 s", "12 min", "1 h 05". */
    public static function minutes(int $seconds): string
    {
        if ($seconds < 90) {
            return $seconds.' s';
        }
        $minutes = (int) round($seconds / 60);

        return $minutes < 60 ? $minutes.' min' : intdiv($minutes, 60).' h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }

    /** Momento dentro do game: "3:07" (ou "1:02:07"). */
    public static function clock(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);

        return $h ? $h.':'.str_pad((string) $m, 2, '0', STR_PAD_LEFT).':'.$s : $m.':'.$s;
    }

    /** Eixo de vida com números redondos (0, 10, 20...) e uns 4 a 6 intervalos. */
    private static function range(int $min, int $max): array
    {
        $span = max(1, $max - $min);
        $tick = collect([1, 2, 5, 10, 20, 25, 50, 100, 200])->first(fn ($step) => $span / $step <= 6) ?? 500;
        $low = (int) (floor($min / $tick) * $tick);
        $high = (int) (ceil($max / $tick) * $tick);

        return [$low, $high === $low ? $low + $tick : $high, $tick];
    }

    /**
     * Avatares do fim das linhas sem se sobrepor: quem terminou com vida parecida é afastado o mínimo (END_GAP).
     * Quem foi eliminado numa mesa fica no ponto onde saiu.
     */
    private static function spreadEnds(array &$series): void
    {
        $order = array_keys(array_filter($series, fn (array $line) => ! $line['out']));
        usort($order, fn ($a, $b) => $series[$a]['labelY'] <=> $series[$b]['labelY']);
        $previous = null;
        foreach ($order as $k) {
            if ($previous !== null && $series[$k]['labelY'] - $previous < self::END_GAP) {
                $series[$k]['labelY'] = $previous + self::END_GAP;
            }
            $previous = $series[$k]['labelY'];
        }
        // Passou do fundo: empurra o grupo de volta para cima.
        $overflow = $previous !== null ? $previous - 100 : 0;
        if ($overflow > 0) {
            foreach ($order as $k) {
                $series[$k]['labelY'] = max(0, $series[$k]['labelY'] - $overflow);
            }
        }
    }
}
