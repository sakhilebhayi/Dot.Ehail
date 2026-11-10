<?php

namespace App\Domain\Ops\Insights;

/**
 * Geometry for a single-series column chart drawn as inline SVG on the server (dataviz method: one hue on the
 * data, hairline gridlines at round steps with the top tick above the maximum, zero baseline, columns at most 24 px
 * with a 4 px rounded data end and square base, a transparent hit area per column for the tooltip, values in a
 * table beside it). Values are integers (counts, cents, seconds) so no float reaches a label.
 */
final class ColumnChart
{
    /**
     * @param  list<array{label: string, value: int|null, display: string}>  $points
     * @return array{width: int, height: int, plot: array{x: int, y: int, w: int, h: int}, ticks: list<array{y: float, label: string}>, bars: list<array<string, mixed>>, empty: bool}
     */
    public static function build(array $points, callable $tickLabel, int $width = 720, int $height = 200): array
    {
        $plot = ['x' => 56, 'y' => 8, 'w' => $width - 64, 'h' => $height - 36];
        $values = array_map(fn ($p) => (int) ($p['value'] ?? 0), $points);
        $max = $values === [] ? 0 : max($values);
        $min = $values === [] ? 0 : min(0, min($values));
        [$top, $step] = self::niceScale($max);

        $ticks = [];
        for ($v = 0; $v <= $top; $v += $step) {
            $ticks[] = ['y' => $plot['y'] + $plot['h'] - ($top > 0 ? $plot['h'] * $v / $top : 0), 'label' => $tickLabel($v)];
        }

        $n = max(1, count($points));
        $maxLabels = max(2, intdiv($width, 64));
        $labelEvery = (int) ceil($n / $maxLabels);
        $band = $plot['w'] / $n;
        $barW = min(24.0, max(2.0, $band - 2));
        $bars = [];
        foreach ($points as $i => $p) {
            $v = max(0, (int) ($p['value'] ?? 0));
            $h = $top > 0 ? $plot['h'] * $v / $top : 0;
            $x = $plot['x'] + $band * $i + ($band - $barW) / 2;
            $yBase = $plot['y'] + $plot['h'];
            $r = min(4.0, $h, $barW / 2);
            $path = $h <= 0 ? null : sprintf(
                'M%.2f %.2f V%.2f Q%.2f %.2f %.2f %.2f H%.2f Q%.2f %.2f %.2f %.2f V%.2f Z',
                $x, $yBase, $yBase - $h + $r, $x, $yBase - $h, $x + $r, $yBase - $h,
                $x + $barW - $r, $x + $barW, $yBase - $h, $x + $barW, $yBase - $h + $r, $yBase
            );
            $bars[] = [
                'path' => $path,
                'hit' => ['x' => $plot['x'] + $band * $i, 'y' => $plot['y'], 'w' => $band, 'h' => $plot['h']],
                'label' => $p['label'],
                'display' => $p['display'],
                'null' => $p['value'] === null,
                'axis' => $i % $labelEvery === 0 ? $p['label'] : null,
                'cx' => $x + $barW / 2,
            ];
        }

        return ['width' => $width, 'height' => $height, 'plot' => $plot, 'ticks' => $ticks, 'bars' => $bars, 'empty' => $max <= 0 && $min >= 0, 'min' => $min];
    }

    /** @return array{0: int, 1: int} top tick (strictly above the maximum unless it is 0) and the step, 4 or 5 steps */
    public static function niceScale(int $max): array
    {
        if ($max <= 0) {
            return [4, 1];
        }

        $raw = $max / 4;
        $magnitude = 10 ** (int) floor(log10($raw));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            $step = (int) max(1, ceil($m * $magnitude));
            if ($step * 4 > $max) {
                return [$step * 4, $step];
            }
            if ($step * 5 > $max) {
                return [$step * 5, $step];
            }
        }

        $step = (int) (10 * $magnitude);

        return [$step * (int) ceil(($max + 1) / $step), $step];
    }
}
