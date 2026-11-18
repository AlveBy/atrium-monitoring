<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Monitoring\Admin\SvgChart;
use PHPUnit\Framework\Attributes\DataProvider;

final class SvgChartTest extends TestCase
{
    /** @return array<string,array{0:float,1:float}> */
    public static function tops(): array
    {
        return [
            'zero' => [0.0, 1.0],
            'negative' => [-5.0, 1.0],
            'exactly a power of ten' => [100.0, 100.0],
            'just over' => [101.0, 200.0],
            'two' => [180.0, 200.0],
            'two and a half' => [240.0, 250.0],
            'five' => [260.0, 500.0],
            'ten' => [501.0, 1000.0],
            'just under the next power' => [999.0, 1000.0],
            'small' => [0.07, 0.1],
            'a CLS score' => [0.23, 0.25],
        ];
    }

    #[DataProvider('tops')]
    public function test_the_axis_ends_on_a_round_number(float $value, float $top): void
    {
        $this->assertEqualsWithDelta($top, SvgChart::niceMax($value), 1e-9);
    }

    public function test_each_line_is_told_apart_without_colour(): void
    {
        $points = [
            ['label' => 'Mon', 'p50' => 100.0, 'p75' => 150.0, 'p95' => 400.0],
            ['label' => 'Tue', 'p50' => 120.0, 'p75' => 180.0, 'p95' => 380.0],
            ['label' => 'Wed'],
            ['label' => 'Thu', 'p50' => 90.0, 'p75' => 140.0, 'p95' => 300.0],
        ];

        $svg = SvgChart::render('Response <time>', $points, fn (float $v): string => round($v).' ms');

        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 720 260" role="img"', $svg);
        $this->assertStringEndsWith('</svg>', $svg);
        $this->assertStringContainsString('<title id="', $svg);
        $this->assertStringContainsString('Response &lt;time&gt;', $svg, 'escaped');
        $this->assertStringContainsString('Mon: p50 100 ms, p75 150 ms, p95 400 ms; Tue: p50 120 ms', $svg);
        $this->assertSame(3, substr_count($svg, '<circle '), 'p50: circles');
        $this->assertSame(3, substr_count($svg, '<rect '), 'p75: squares');
        $this->assertSame(3, substr_count($svg, '<polygon '), 'p95: triangles');
        $this->assertStringContainsString('stroke-dasharray="7 4"', $svg);
        $this->assertStringContainsString('stroke-dasharray="2 4"', $svg);
        $this->assertSame(1, substr_count($svg, 'stroke-dasharray="'.'7 4"'));
        foreach (['p50', 'p75', 'p95'] as $name) {
            $this->assertMatchesRegularExpression('~<text class="label" x="[\d.]+" y="[\d.]+">'.$name.'</text>~', $svg);
        }
        // The gap on Wednesday breaks the line instead of joining over it.
        $this->assertSame(2, preg_match_all('~ M~', ' '.preg_replace('~.*<g class="series p50">.*?d="([^"]+)".*~s', '$1', $svg)));
        $this->assertStringContainsString('<title>p95 Thu: 300 ms</title>', $svg);
        // The axis: 0 to 500 in four steps, the days below.
        foreach (['0 ms', '125 ms', '250 ms', '375 ms', '500 ms', '>Mon<', '>Thu<'] as $text) {
            $this->assertStringContainsString($text, $svg);
        }
    }

    public function test_the_points_are_placed_by_value(): void
    {
        $svg = SvgChart::render('One', [['label' => 'A', 'p50' => 0.0], ['label' => 'B', 'p50' => 10.0]], fn (float $v): string => (string) $v, ['p50']);

        // Plot from x 64 to 664 and y 220 (zero) to 16 (top).
        $this->assertStringContainsString('d="M64 220 L664 16"', $svg);
        $this->assertStringContainsString('<circle cx="664" cy="16" r="4">', $svg);
        $this->assertStringNotContainsString('p75', $svg);
    }

    public function test_one_point_sits_in_the_middle(): void
    {
        $svg = SvgChart::render('One', [['label' => 'Today', 'p50' => 5.0]], fn (float $v): string => (string) $v, ['p50']);

        $this->assertStringContainsString('<circle cx="364" cy="16" r="4">', $svg);
    }

    public function test_long_periods_label_every_few_points_and_the_last(): void
    {
        $points = array_map(fn (int $i): array => ['label' => 'd'.$i, 'p50' => 1.0], range(1, 30));
        $svg = SvgChart::render('Month', $points, fn (float $v): string => (string) $v, ['p50']);

        $labels = [];
        preg_match_all('~text-anchor="middle">(d\d+)</text>~', $svg, $labels);
        $this->assertSame(['d1', 'd5', 'd9', 'd13', 'd17', 'd21', 'd25', 'd30'], $labels[1]);
    }

    public function test_no_data_says_so(): void
    {
        $svg = SvgChart::render('Empty', [['label' => 'Mon'], ['label' => 'Tue']], fn (float $v): string => (string) $v);

        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 720 260" role="img"', $svg);
        $this->assertStringEndsWith('<text class="empty" x="360" y="130" text-anchor="middle">No data yet</text></svg>', $svg);
        $this->assertStringNotContainsString('<g class="series', $svg);

        $this->assertStringEndsWith('<text class="empty" x="360" y="130" text-anchor="middle">No data yet</text></svg>', SvgChart::render('Nothing', [], fn (float $v): string => (string) $v));
    }

    public function test_an_unknown_series_gets_circles_and_a_solid_line(): void
    {
        $svg = SvgChart::render('Other', [['label' => 'A', 'p99' => 3.0], ['label' => 'B', 'p99' => 4.0]], fn (float $v): string => (string) $v, ['p99']);

        $this->assertSame(2, substr_count($svg, '<circle '));
        $this->assertStringNotContainsString('stroke-dasharray', $svg);
    }

    /** @return array<string,array{0:int,1:list<int>}> points => the ones labelled, by position */
    public static function axisLabels(): array
    {
        return [
            'one' => [1, [0]],
            'two' => [2, [0, 1]],
            'eight, every one' => [8, [0, 1, 2, 3, 4, 5, 6, 7]],
            'nine, every second, the last falls on the step' => [9, [0, 2, 4, 6, 8]],
            'ten, the one before the last gives way' => [10, [0, 2, 4, 6, 9]],
            'sixteen' => [16, [0, 2, 4, 6, 8, 10, 12, 15]],
            'seventeen, every third' => [17, [0, 3, 6, 9, 12, 16]],
            'twenty-four' => [24, [0, 3, 6, 9, 12, 15, 18, 23]],
            'twenty-five, every fourth' => [25, [0, 4, 8, 12, 16, 20, 24]],
        ];
    }

    /** @param list<int> $shown */
    #[DataProvider('axisLabels')]
    public function test_the_time_axis_has_at_most_eight_labels(int $count, array $shown): void
    {
        $points = array_map(fn (int $i): array => ['label' => 'd'.$i, 'p50' => 1.0], range(0, $count - 1));
        $svg = SvgChart::render('Axis', $points, fn (float $v): string => (string) $v, ['p50']);

        $labels = [];
        preg_match_all('~<text class="axis" x="[\d.]+" y="246" text-anchor="middle">(d\d+)</text>~', $svg, $labels);
        $this->assertSame(array_map(fn (int $i): string => 'd'.$i, $shown), $labels[1]);
    }

    public function test_the_whole_chart_is_drawn_as_the_panel_expects(): void
    {
        $points = [
            ['label' => 'Mon', 'p50' => 100.0, 'p75' => 150.0, 'p95' => 400.0],
            ['label' => 'Tue<br>', 'p50' => 120.5, 'p75' => 180.25, 'p95' => 380.0],
            ['label' => 'Wed', 'p50' => null],
            ['label' => 'Thu', 'p50' => 90.0, 'p75' => 140.0, 'p95' => 333.0],
            ['p50' => 95.0],
        ];

        $svg = SvgChart::render('Time & "load"', $points, fn (float $v): string => round($v, 1).' ms');

        $this->assertSame(implode('', [
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 720 260" role="img" aria-labelledby="ID-t ID-d" class="atrium-monitoring-chart" width="100%">',
            '<title id="ID-t">Time &amp; &quot;load&quot;</title>',
            '<desc id="ID-d">Mon: p50 100 ms, p75 150 ms, p95 400 ms; Tue&lt;br&gt;: p50 120.5 ms, p75 180.3 ms, p95 380 ms; Thu: p50 90 ms, p75 140 ms, p95 333 ms; : p50 95 ms</desc>',
            '<line class="grid" x1="64" x2="664" y1="220" y2="220"/><text class="axis" x="56" y="224" text-anchor="end">0 ms</text>',
            '<line class="grid" x1="64" x2="664" y1="169" y2="169"/><text class="axis" x="56" y="173" text-anchor="end">125 ms</text>',
            '<line class="grid" x1="64" x2="664" y1="118" y2="118"/><text class="axis" x="56" y="122" text-anchor="end">250 ms</text>',
            '<line class="grid" x1="64" x2="664" y1="67" y2="67"/><text class="axis" x="56" y="71" text-anchor="end">375 ms</text>',
            '<line class="grid" x1="64" x2="664" y1="16" y2="16"/><text class="axis" x="56" y="20" text-anchor="end">500 ms</text>',
            '<text class="axis" x="64" y="246" text-anchor="middle">Mon</text>',
            '<text class="axis" x="214" y="246" text-anchor="middle">Tue&lt;br&gt;</text>',
            '<text class="axis" x="364" y="246" text-anchor="middle">Wed</text>',
            '<text class="axis" x="514" y="246" text-anchor="middle">Thu</text>',
            '<text class="axis" x="664" y="246" text-anchor="middle"></text>',
            '<g class="series p50"><path d="M64 179.2 L214 170.8 M514 183.3 L664 181.2" fill="none"/>',
            '<circle cx="64" cy="179.2" r="4"><title>p50 Mon: 100 ms</title></circle>',
            '<circle cx="214" cy="170.8" r="4"><title>p50 Tue&lt;br&gt;: 120.5 ms</title></circle>',
            '<circle cx="514" cy="183.3" r="4"><title>p50 Thu: 90 ms</title></circle>',
            '<circle cx="664" cy="181.2" r="4"><title>p50 : 95 ms</title></circle>',
            '<text class="label" x="674" y="185.2">p50</text></g>',
            '<g class="series p75"><path d="M64 158.8 L214 146.5 M514 162.9" fill="none" stroke-dasharray="7 4"/>',
            '<rect x="60" y="154.8" width="8" height="8"><title>p75 Mon: 150 ms</title></rect>',
            '<rect x="210" y="142.5" width="8" height="8"><title>p75 Tue&lt;br&gt;: 180.3 ms</title></rect>',
            '<rect x="510" y="158.9" width="8" height="8"><title>p75 Thu: 140 ms</title></rect>',
            '<text class="label" x="524" y="166.9">p75</text></g>',
            '<g class="series p95"><path d="M64 56.8 L214 65 M514 84.1" fill="none" stroke-dasharray="2 4"/>',
            '<polygon points="64,51.8 69,60.8 59,60.8"><title>p95 Mon: 400 ms</title></polygon>',
            '<polygon points="214,60 219,69 209,69"><title>p95 Tue&lt;br&gt;: 380 ms</title></polygon>',
            '<polygon points="514,79.1 519,88.1 509,88.1"><title>p95 Thu: 333 ms</title></polygon>',
            '<text class="label" x="524" y="88.1">p95</text></g>',
            '</svg>',
        ]), str_replace('cID', 'ID', preg_replace('~c[0-9a-f]{8}-~', 'ID-', $svg)));
    }

    public function test_the_ids_tie_the_chart_to_its_title_and_data(): void
    {
        $points = [['label' => 'A', 'p50' => 1.0]];
        $svg = SvgChart::render('Response', $points, fn (float $v): string => (string) $v, ['p50']);

        $this->assertSame(1, preg_match('~aria-labelledby="(c[0-9a-f]{8})-t \\1-d"~', $svg, $m));
        $this->assertSame('c'.substr(md5('Response[{"label":"A","p50":1}]'), 0, 8), $m[1]);
        $this->assertStringContainsString('<title id="'.$m[1].'-t">Response</title><desc id="'.$m[1].'-d">A: p50 1</desc>', $svg);
        $this->assertNotSame($m[1], $this->idOf(SvgChart::render('Other', $points, fn (float $v): string => (string) $v, ['p50'])));
        $this->assertNotSame($m[1], $this->idOf(SvgChart::render('Response', [['label' => 'A', 'p50' => 2.0]], fn (float $v): string => (string) $v, ['p50'])));
    }

    private function idOf(string $svg): string
    {
        preg_match('~aria-labelledby="(c[0-9a-f]{8})-t~', $svg, $m);

        return $m[1];
    }

    public function test_series_without_values_get_a_group_but_no_name(): void
    {
        $svg = SvgChart::render('Gaps', [['label' => 'A', 'p50' => 4.0]], fn (float $v): string => (string) $v, ['p50', 'p75']);

        $this->assertStringContainsString('<g class="series p75"><path d="" fill="none" stroke-dasharray="7 4"/></g>', $svg);
        $this->assertSame(1, substr_count($svg, '<text class="label"'));
    }

    public function test_the_default_series_are_p50_p75_and_p95(): void
    {
        $svg = SvgChart::render('Default', [['label' => 'A', 'p50' => 4.0, 'p75' => 5.0, 'p95' => 6.0, 'p99' => 7.0]], fn (float $v): string => (string) $v);

        preg_match_all('~<g class="series (p\d+)">~', $svg, $groups);
        $this->assertSame(['p50', 'p75', 'p95'], $groups[1]);
        $this->assertStringNotContainsString('p99', $svg);
    }

    public function test_the_axis_follows_the_top_of_small_values(): void
    {
        $svg = SvgChart::render('Small', [['label' => 'A', 'p50' => 0.23]], fn (float $v): string => (string) $v, ['p50']);

        foreach (['0', '0.0625', '0.125', '0.1875', '0.25'] as $label) {
            $this->assertStringContainsString('text-anchor="end">'.$label.'</text>', $svg);
        }

        // 0.23 of 0.25 of a 204 high plot, from the bottom at 220.
        $this->assertStringContainsString('<circle cx="364" cy="32.3" r="4">', $svg);
    }

    public function test_values_that_arrive_as_text_are_read_as_numbers(): void
    {
        $numbers = SvgChart::render('Text', [['label' => 'A', 'p50' => 100.0], ['label' => 'B', 'p50' => 250.5]], fn (float $v): string => (string) $v, ['p50']);
        $text = SvgChart::render('Text', [['label' => 'A', 'p50' => '100'], ['label' => 'B', 'p50' => '250.5']], fn (float $v): string => (string) $v, ['p50']);

        $this->assertSame(preg_replace('~c[0-9a-f]{8}~', 'ID', $numbers), preg_replace('~c[0-9a-f]{8}~', 'ID', $text));
    }

    public function test_a_flat_zero_line_sits_on_the_bottom_of_the_plot(): void
    {
        $svg = SvgChart::render('Zero', [['label' => 'A', 'p50' => 0.0], ['label' => 'B', 'p50' => 0.0]], fn (float $v): string => (string) $v, ['p50']);

        $this->assertStringContainsString('d="M64 220 L664 220"', $svg);
        $this->assertStringContainsString('text-anchor="end">1</text>', $svg);
    }

    /** @return array<string,array{0:string,1:string}> series => [x, y] of its name at the end of the line */
    private function names(string $svg): array
    {
        preg_match_all('~<text class="label" x="([\d.]+)" y="([\d.]+)">(p\d+)</text>~', $svg, $found, PREG_SET_ORDER);

        return array_column(array_map(fn (array $f): array => [$f[3], [$f[1], $f[2]]], $found), 1, 0);
    }

    public function test_names_at_the_line_ends_are_stacked_when_the_lines_end_together(): void
    {
        $svg = SvgChart::render('Equal', [['label' => 'A', 'p50' => 5.0, 'p75' => 5.0, 'p95' => 5.0]], fn (float $v): string => (string) $v);

        // One line of text (12) apart, in the order of the series.
        $this->assertSame(['p50' => ['374', '20'], 'p75' => ['374', '32'], 'p95' => ['374', '44']], $this->names($svg));
        $this->assertSame(1, preg_match('~<g class="series p50">.*?y="20">p50</text></g><g class="series p75">.*?y="32">p75</text></g><g class="series p95">.*?y="44">p95</text></g></svg>$~', $svg));
    }

    public function test_names_of_close_lines_keep_the_order_of_the_lines_not_of_the_series(): void
    {
        // Ends at 16 (p95), 18 (p50) and 20.1 (p75): the highest value is on top.
        $svg = SvgChart::render('Close', [['label' => 'A', 'p50' => 495.0, 'p75' => 490.0, 'p95' => 500.0]], fn (float $v): string => (string) $v);

        $this->assertStringContainsString('<circle cx="364" cy="18" r="4">', $svg);
        $this->assertStringContainsString('<rect x="360" y="16.1" width="8" height="8">', $svg);
        $this->assertStringContainsString('<polygon points="364,11 369,20 359,20">', $svg);

        $this->assertSame(['p50' => ['374', '32'], 'p75' => ['374', '44'], 'p95' => ['374', '20']], $this->names($svg));
    }

    public function test_names_of_lines_far_enough_apart_stay_where_they_are(): void
    {
        $svg = SvgChart::render('Apart', [['label' => 'A', 'p50' => 100.0, 'p75' => 470.6, 'p95' => 500.0]], fn (float $v): string => (string) $v);

        // 28 and 16: exactly a line of text apart; 179.2 is far below.
        $this->assertSame(['p50' => ['374', '183.2'], 'p75' => ['374', '32'], 'p95' => ['374', '20']], $this->names($svg));
    }

    public function test_only_lines_that_end_near_each_other_are_moved(): void
    {
        $svg = SvgChart::render('Mixed', [['label' => 'A', 'p50' => 500.0, 'p75' => 500.0, 'p95' => 100.0]], fn (float $v): string => (string) $v);

        $this->assertSame(['p50' => ['374', '20'], 'p75' => ['374', '32'], 'p95' => ['374', '183.2']], $this->names($svg));
    }

    public function test_a_line_without_values_takes_no_room_for_its_name(): void
    {
        $svg = SvgChart::render('Gap', [['label' => 'A', 'p50' => 5.0, 'p95' => 5.0]], fn (float $v): string => (string) $v);

        $this->assertSame(['p50' => ['374', '20'], 'p95' => ['374', '32']], $this->names($svg));
    }

    public function test_no_two_time_labels_run_together(): void
    {
        $points = array_map(fn (int $i): array => ['label' => 'd'.$i, 'p50' => 1.0], range(1, 30));
        $svg = SvgChart::render('Month', $points, fn (float $v): string => (string) $v, ['p50']);

        preg_match_all('~<text class="axis" x="([\d.]+)" y="246" text-anchor="middle">~', $svg, $found);
        $xs = array_map('floatval', $found[1]);
        $gaps = array_map(fn (float $a, float $b): float => $b - $a, array_slice($xs, 0, -1), array_slice($xs, 1));

        // Every fourth of 29 steps of 600/29 apart, the last 5 steps after the one before.
        $this->assertSame([64.0, 146.8, 229.5, 312.3, 395.0, 477.8, 560.6, 664.0], $xs);
        $this->assertStringContainsString('<circle cx="84.7" cy="16" r="4">', $svg);
        $this->assertStringContainsString('<circle cx="105.4" cy="16" r="4">', $svg);
        $this->assertGreaterThanOrEqual(4 * 600 / 29 - 0.1, min($gaps));
    }
}
