<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Monitoring\Buffer\Flusher;
use Atrium\Monitoring\Stats\Aggregator;
use Atrium\Monitoring\Stats\Histogram;
use Atrium\Monitoring\Stats\Report;
use Atrium\Monitoring\Stats\Vitals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ReportTest extends TestCase
{
    private function request(Carbon $at, array $overrides = []): array
    {
        return $overrides + [
            'k' => 'r', 'at' => $at->getTimestamp(), 'site' => 1, 'loc' => 'en', 'route' => 'pages.show',
            'path' => '/about', 'm' => 'GET', 's' => 200, 'd' => 100.0, 'q' => 4, 'qm' => 3.5,
            'ch' => 2, 'cm' => 1, 'mem' => 20480,
        ];
    }

    public function test_requests_go_to_the_site_language_and_route_totals(): void
    {
        $at = Carbon::parse('2026-11-10 12:00:00');
        $aggregator = new Aggregator;
        $aggregator->addRequest($this->request($at), $at);
        $aggregator->addRequest($this->request($at, ['loc' => 'ru', 'route' => 'news.show', 'd' => 300.0, 's' => 503]), $at);
        $aggregator->save();

        $ttfb = DB::table('monitoring_daily')->where('metric', 'ttfb')->orderBy('locale')->orderBy('subject')->get();
        $keys = $ttfb->map(fn ($row) => [$row->site_id, $row->locale, $row->subject, (int) $row->count])->all();
        $this->assertEqualsCanonicalizing([
            [1, '*', '*', 2],
            [1, 'en', '*', 1],
            [1, 'ru', '*', 1],
            [1, '*', 'pages.show', 1],
            [1, '*', 'news.show', 1],
        ], $keys);

        $error = DB::table('monitoring_daily')->where('metric', 'error')->where('locale', '*')->where('subject', '*')->first();
        $this->assertSame(2, (int) $error->count);
        $this->assertEqualsWithDelta(1.0, (float) $error->sum, 1e-9, 'one request of two failed');

        $memory = Histogram::fromRow(DB::table('monitoring_daily')->where('metric', 'memory')->where('subject', '*')->where('locale', '*')->first());
        $this->assertEqualsWithDelta(20.0, $memory->max(), 1e-9, 'kilobytes become megabytes');

        // Saving again merges, it does not overwrite.
        $aggregator->addRequest($this->request($at, ['d' => 500.0]), $at);
        $aggregator->save();
        $this->assertSame(3, (int) DB::table('monitoring_daily')->where('metric', 'ttfb')->where('locale', '*')->where('subject', '*')->value('count'));
        $this->assertSame([], $aggregator->pending());
    }

    public function test_vitals_go_to_the_site_language_page_and_device_totals(): void
    {
        $at = Carbon::parse('2026-11-10 12:00:00');
        $aggregator = new Aggregator;
        $aggregator->addVitals(['site' => 2, 'loc' => 'de', 'path' => '/kontakt', 'dev' => 'mobile', 'lcp' => 2400, 'cls' => 0.02, 'inp' => 'slow'], $at);
        $aggregator->save();

        $rows = DB::table('monitoring_daily')->where('source', 'vital')->get();
        $this->assertEqualsCanonicalizing(['lcp', 'cls'], $rows->pluck('metric')->unique()->values()->all(), 'not a number, not stored');
        $this->assertEqualsCanonicalizing([
            ['*', '*', '*'], ['de', '*', '*'], ['*', '/kontakt', '*'], ['*', '*', 'mobile'],
        ], $rows->where('metric', 'lcp')->map(fn ($row) => [$row->locale, $row->subject, $row->device])->values()->all());
    }

    public function test_series_by_day_week_and_month(): void
    {
        $today = CarbonImmutable::parse('2026-11-17');
        $records = [];

        // 30 days, each day's times ten times the day number: the series must climb.
        for ($day = 0; $day < 400; $day += 1) {
            $at = Carbon::parse('2026-11-17 10:00:00')->subDays($day);

            foreach ([1, 2, 3, 4] as $n) {
                $records[] = $this->request($at, ['d' => (float) (($day + 1) * 10 * $n)]);
            }
        }

        app(Flusher::class)->write($records);
        $report = new Report($today);

        $days = $report->series('request', 'ttfb', 'day', ['site' => 1]);
        $this->assertCount(30, $days);
        $this->assertSame(['from' => '2026-10-19', 'to' => '2026-10-19'], array_intersect_key($days[0], ['from' => 1, 'to' => 1]));
        $this->assertSame('2026-11-17', $days[29]['to']);
        $this->assertSame(4, $days[29]['count']);
        // Today: 10, 20, 30, 40 → p50 at rank 1.5 is 20, p95 at rank 2.85 is 30.
        $this->assertEqualsWithDelta(20, $days[29]['p50'], 0.5);
        $this->assertEqualsWithDelta(30, $days[29]['p95'], 0.75);
        $this->assertGreaterThan($days[29]['p75'], $days[0]['p75']);

        $weeks = $report->series('request', 'ttfb', 'week');
        $this->assertCount(26, $weeks);
        $this->assertSame('2026-11-16', $weeks[25]['from'], 'weeks start on Monday');
        $this->assertSame('2026-11-22', $weeks[25]['to']);
        $this->assertSame(8, $weeks[25]['count'], 'Monday and Tuesday so far');
        $this->assertSame(28, $weeks[24]['count']);

        $months = $report->series('request', 'ttfb', 'month');
        $this->assertCount(13, $months);
        $this->assertSame(['2025-11-01', '2025-11-30'], [$months[0]['from'], $months[0]['to']]);
        $this->assertSame(17 * 4, $months[12]['count']);
        $this->assertSame(31 * 4, $months[11]['count']);

        // Another site has nothing; all sites together equals site 1 here.
        $this->assertSame(0, $report->series('request', 'ttfb', 'day', ['site' => 9])[29]['count']);
        $this->assertNull($report->series('request', 'ttfb', 'day', ['site' => 9])[29]['p50']);
        $this->assertSame($days, $report->series('request', 'ttfb', 'day', ['site' => null]));
        // An unknown granularity falls back to days.
        $this->assertCount(30, $report->periods('fortnight'));
    }

    public function test_top_total_and_uptime(): void
    {
        $today = CarbonImmutable::parse('2026-11-17');
        $at = Carbon::parse('2026-11-16 09:00:00');
        $records = [];

        foreach (['home' => 50.0, 'catalog.show' => 900.0, 'news.index' => 300.0] as $route => $ms) {
            foreach (range(1, 5) as $n) {
                $records[] = $this->request($at, ['route' => $route, 'd' => $ms + $n]);
            }
        }

        app(Flusher::class)->write($records);
        $report = new Report($today);
        [$from, $to] = $report->lastDays(7);
        $this->assertSame(['2026-11-11', '2026-11-17'], [$from, $to]);

        $top = $report->top('request', 'ttfb', $from, $to, ['site' => 1], 2);
        $this->assertSame(['catalog.show', 'news.index'], array_column($top, 'subject'));
        $this->assertSame(5, $top[0]['count']);
        $this->assertSame('home', $report->top('request', 'ttfb', $from, $to, [], 10, 'p50')[2]['subject']);
        $this->assertSame('catalog.show', $report->top('request', 'ttfb', $from, $to, [], 1, 'bogus')[0]['subject']);

        $total = $report->total('request', 'ttfb', $from, $to, ['site' => 1]);
        $this->assertSame(15, $total->count());
        $this->assertSame(0, $report->total('request', 'ttfb', '2026-01-01', '2026-01-31')->count());

        $aggregator = new Aggregator;

        foreach ([true, true, true, false] as $ok) {
            $aggregator->addUptime(1, 7, $ok, $ok ? 120.0 : null, $at);
        }

        $aggregator->addUptime(2, 8, true, 80.0, $at);
        $aggregator->save();

        $this->assertSame(['checks' => 4, 'up' => 3, 'ratio' => 0.75], $report->uptime($from, $to)['7']);
        $this->assertSame([8], array_keys($report->uptime($from, $to, 2)), 'check ids as keys');
        $this->assertSame(3, $report->total('uptime', 'response', $from, $to, ['subject' => '7'])->count());
    }

    public function test_saving_an_empty_batch_writes_nothing(): void
    {
        (new Aggregator)->save();

        $this->assertSame(0, DB::table('monitoring_daily')->count());
    }

    public function test_a_missing_value_is_shown_as_a_dash(): void
    {
        $this->assertSame('—', Vitals::format('lcp', null));
        $this->assertSame('—', Vitals::format('cls', null));
        $this->assertSame('0.120', Vitals::format('cls', 0.12));
        $this->assertSame('999 ms', Vitals::format('lcp', 999.4));
        $this->assertSame('1.00 s', Vitals::format('lcp', 1000.0));
    }

    /** @param array<string,mixed> $attributes */
    private function daily(string $day, string $source, string $metric, string $subject, int $count, float $sum, array $attributes = []): void
    {
        DB::table('monitoring_daily')->insert($attributes + [
            'day' => $day, 'source' => $source, 'metric' => $metric, 'site_id' => 1, 'locale' => '*', 'subject' => $subject, 'device' => '*',
            'count' => $count, 'sum' => $sum, 'min' => 0, 'max' => 1, 'buckets' => '{}',
        ]);
    }

    public function test_the_top_is_ten_by_default_and_never_fewer_than_one(): void
    {
        $report = new Report(CarbonImmutable::parse('2026-11-17'));
        $at = Carbon::parse('2026-11-16 09:00:00');
        $records = [];

        foreach (range(1, 11) as $n) {
            $records[] = $this->request($at, ['route' => 'route.'.$n, 'd' => 100.0 * $n]);
        }

        app(Flusher::class)->write($records);

        $top = $report->top('request', 'ttfb', '2026-11-11', '2026-11-17');
        $this->assertSame(array_map(fn (int $n): string => 'route.'.$n, range(11, 2)), array_column($top, 'subject'));

        foreach ([1, 0, -5] as $limit) {
            $this->assertSame(['route.11'], array_column($report->top('request', 'ttfb', '2026-11-11', '2026-11-17', [], $limit), 'subject'), 'limit '.$limit);
        }

        $this->assertSame(['route.11', 'route.10'], array_column($report->top('request', 'ttfb', '2026-11-11', '2026-11-17', [], 2), 'subject'));
    }

    public function test_a_subject_that_looks_like_a_number_stays_text(): void
    {
        $report = new Report(CarbonImmutable::parse('2026-11-17'));
        $this->daily('2026-11-16', 'uptime', 'response', '7', 3, 360.0);

        $top = $report->top('uptime', 'response', '2026-11-11', '2026-11-17');
        $this->assertSame('7', $top[0]['subject']);
        $this->assertSame(3, $top[0]['count']);
    }

    public function test_uptime_rounds_each_days_sum_and_survives_a_day_without_checks(): void
    {
        $report = new Report(CarbonImmutable::parse('2026-11-17'));
        $this->daily('2026-11-14', 'uptime', 'up', '5', 4, 2.6);
        $this->daily('2026-11-15', 'uptime', 'up', '5', 4, 2.4);
        $this->daily('2026-11-15', 'uptime', 'up', '6', 0, 0.0);
        $this->daily('2026-11-15', 'uptime', 'up', '8', 3, 2.0, ['site_id' => 2]);

        $this->assertSame([
            5 => ['checks' => 8, 'up' => 5, 'ratio' => 0.625],
            6 => ['checks' => 0, 'up' => 0, 'ratio' => 0.0],
            8 => ['checks' => 3, 'up' => 2, 'ratio' => 2 / 3],
        ], $report->uptime('2026-11-11', '2026-11-17'));
        $this->assertSame([8], array_keys($report->uptime('2026-11-11', '2026-11-17', 2)));
        $this->assertSame([5, 6], array_keys($report->uptime('2026-11-11', '2026-11-17', 1)));
    }

    public function test_the_last_days_always_include_today(): void
    {
        $report = new Report(CarbonImmutable::parse('2026-11-17'));

        $this->assertSame(['2026-11-17', '2026-11-17'], $report->lastDays(1));
        $this->assertSame(['2026-11-17', '2026-11-17'], $report->lastDays(0));
        $this->assertSame(['2026-11-17', '2026-11-17'], $report->lastDays(-3));
        $this->assertSame(['2026-11-15', '2026-11-17'], $report->lastDays(3));
    }
}
