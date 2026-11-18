<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Admin;

/**
 * A line chart of p50, p75 and p95, drawn on the server as SVG.
 *
 * Readable without colour: each line has its own marker shape (p50 circle,
 * p75 square, p95 triangle), its own stroke (solid, dashed, dotted) and its
 * name written at its right end. The colours are a blue, an orange and a
 * near-black, told apart by colour-blind readers too, each at least 4.5:1
 * against the panel's light and night backgrounds (docs/monitoring.md has
 * the numbers). A <title> and <desc> carry the data for screen readers, and
 * the page shows the same numbers as a table under the chart.
 */
final class SvgChart
{
    public const WIDTH = 720;

    public const HEIGHT = 260;

    private const LEFT = 64;

    private const RIGHT = 56;

    private const TOP = 16;

    private const BOTTOM = 40;

    /** The size of the labels' text, as the panel's style sheet sets it. */
    private const FONT_SIZE = 12;

    /** series => [marker, dash] */
    public const STYLES = [
        'p50' => ['circle', ''],
        'p75' => ['square', '7 4'],
        'p95' => ['triangle', '2 4'],
    ];

    /**
     * @param  array<int,array<string,mixed>>  $points  each with 'label' and the series keys
     * @param  callable(float):string  $format  axis and title values
     */
    public static function render(string $title, array $points, callable $format, array $series = ['p50', 'p75', 'p95']): string
    {
        $values = [];

        foreach ($points as $point) {
            foreach ($series as $name) {
                if (isset($point[$name])) {
                    $values[] = (float) $point[$name];
                }
            }
        }

        $id = 'c'.substr(md5($title.json_encode($points)), 0, 8);
        $plotWidth = self::WIDTH - self::LEFT - self::RIGHT;
        $plotHeight = self::HEIGHT - self::TOP - self::BOTTOM;
        $max = self::niceMax(max([0.0, ...$values]));
        $count = count($points);
        $x = static fn (int $i): float => self::LEFT + ($count === 1 ? $plotWidth / 2 : $i * $plotWidth / ($count - 1));
        $y = static fn (float $v): float => self::TOP + $plotHeight - $v / $max * $plotHeight;

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" role="img" aria-labelledby="%s-t %s-d" class="atrium-monitoring-chart" width="100%%">',
            self::WIDTH, self::HEIGHT, $id, $id,
        );
        $svg .= '<title id="'.$id.'-t">'.e($title).'</title>';
        $svg .= '<desc id="'.$id.'-d">'.e(self::describe($points, $series, $format)).'</desc>';

        // Grid and the value axis: four steps.
        for ($step = 0; $step <= 4; $step++) {
            $value = $max * $step / 4;
            $lineY = self::TOP + $plotHeight * (4 - $step) / 4;
            $svg .= '<line class="grid" x1="'.self::LEFT.'" x2="'.(self::WIDTH - self::RIGHT).'" y1="'.$lineY.'" y2="'.$lineY.'"/>';
            $svg .= '<text class="axis" x="'.(self::LEFT - 8).'" y="'.($lineY + 4).'" text-anchor="end">'.e($format($value)).'</text>';
        }

        // Time axis: at most eight labels; the last one always, and no other
        // closer to it than the regular step, so the two do not run together.
        $every = intdiv($count + 7, 8);

        foreach ($points as $i => $point) {
            if ($i === $count - 1 || ($i % $every === 0 && $count - 1 - $i >= $every)) {
                $svg .= '<text class="axis" x="'.round($x($i), 1).'" y="'.(self::HEIGHT - 14).'" text-anchor="middle">'.e((string) ($point['label'] ?? '')).'</text>';
            }
        }

        if ($values === []) {
            $svg .= '<text class="empty" x="'.(self::WIDTH / 2).'" y="'.(self::HEIGHT / 2).'" text-anchor="middle">'.e(__('atrium-monitoring::monitoring.no_data')).'</text>';

            return $svg.'</svg>';
        }

        $groups = [];
        $lastX = [];
        $lastY = [];

        foreach ($series as $n => $name) {
            [$marker, $dash] = self::STYLES[$name] ?? ['circle', ''];
            $path = '';
            $markers = '';
            $pen = 'M';

            foreach ($points as $i => $point) {
                if (! isset($point[$name])) {
                    $pen = 'M';

                    continue;
                }

                $px = round($x($i), 1);
                $py = round($y((float) $point[$name]), 1);
                $path .= $pen.$px.' '.$py.' ';
                $pen = 'L';
                $markers .= self::marker($marker, $px, $py, $name.' '.($point['label'] ?? '').': '.$format((float) $point[$name]));
                $lastX[$n] = $px;
                $lastY[$n] = $py;
            }

            $groups[$n] = '<g class="series '.$name.'">'
                .'<path d="'.trim($path).'" fill="none"'.($dash !== '' ? ' stroke-dasharray="'.$dash.'"' : '').'/>'
                .$markers;
        }

        $labelY = self::stack($lastY);

        foreach ($groups as $n => $group) {
            $svg .= $group;

            if (isset($lastX[$n])) {
                $svg .= '<text class="label" x="'.($lastX[$n] + 10).'" y="'.($labelY[$n] + 4).'">'.$series[$n].'</text>';
            }

            $svg .= '</g>';
        }

        return $svg.'</svg>';
    }

    /** A round top for the value axis: 1, 2, 2.5 or 5 times a power of ten. */
    public static function niceMax(float $value): float
    {
        if ($value <= 0) {
            return 1.0;
        }

        $power = 10 ** floor(log10($value));

        foreach ([1, 2, 2.5, 5] as $step) {
            if ($value <= $step * $power) {
                return $step * $power;
            }
        }

        return 10 * $power;
    }

    /**
     * Keeps the names at the ends of the lines apart: from the top down, each
     * one at its own height or, when that would be closer than a line of text
     * to the one above, just below it. Equal heights keep the series' order.
     *
     * @param  array<int,float>  $heights  series => the end of its line
     * @return array<int,float> series => where its name goes
     */
    private static function stack(array $heights): array
    {
        asort($heights);

        $stacked = [];
        $floor = -INF;

        foreach ($heights as $n => $height) {
            $floor = max($height, $floor + self::FONT_SIZE);
            $stacked[$n] = $floor;
        }

        return $stacked;
    }

    private static function marker(string $shape, float $x, float $y, string $title): string
    {
        $tip = '<title>'.e($title).'</title>';

        return match ($shape) {
            'square' => '<rect x="'.($x - 4).'" y="'.($y - 4).'" width="8" height="8">'.$tip.'</rect>',
            'triangle' => '<polygon points="'.$x.','.($y - 5).' '.($x + 5).','.($y + 4).' '.($x - 5).','.($y + 4).'">'.$tip.'</polygon>',
            default => '<circle cx="'.$x.'" cy="'.$y.'" r="4">'.$tip.'</circle>',
        };
    }

    /**
     * @param  array<int,array<string,mixed>>  $points
     * @param  array<int,string>  $series
     */
    private static function describe(array $points, array $series, callable $format): string
    {
        $parts = [];

        foreach ($points as $point) {
            $values = [];

            foreach ($series as $name) {
                if (isset($point[$name])) {
                    $values[] = $name.' '.$format((float) $point[$name]);
                }
            }

            if ($values !== []) {
                $parts[] = ($point['label'] ?? '').': '.implode(', ', $values);
            }
        }

        return implode('; ', $parts);
    }
}
