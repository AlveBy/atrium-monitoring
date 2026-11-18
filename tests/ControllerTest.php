<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Admin\Models\Site;
use Atrium\Monitoring\Buffer\Flusher;
use Atrium\Monitoring\Models\Incident;
use Atrium\Monitoring\Models\UptimeCheck;
use Atrium\Monitoring\Uptime\UptimeChecker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The monitoring page's props, value by value: two sites, so that a filter
 * that is dropped shows up as a number that is too big.
 */
final class ControllerTest extends TestCase
{
    private const HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-17 12:00:00');
        // Id 2 by hand: MySQL and PostgreSQL do not take the counter back with
        // the rolled-back test, and the records below name their site by number.
        (new Site)->forceFill(['id' => 2, 'code' => 'shop', 'name' => 'Demo shop', 'domains' => ['shop.example.test'], 'active' => true])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function props(string $url): array
    {
        $response = $this->actingAs($this->admin(), 'admin')->get($url)->assertOk();

        return self::plain($response->original->getData()['page']['props']);
    }

    /** Collections to arrays, scalars untouched: 25.0 stays a float, which JSON would make 25. */
    private static function plain(mixed $value): mixed
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        return is_array($value) ? array_map(self::plain(...), $value) : $value;
    }

    /** @param array<string,mixed> $overrides */
    private function request(int $site, string $route, string $path, array $overrides = []): array
    {
        return $overrides + [
            'k' => 'r', 'at' => Carbon::now()->subHours(3)->getTimestamp(), 'site' => $site, 'loc' => 'en', 'route' => $route,
            'path' => $path, 'm' => 'GET', 's' => 200, 'd' => 100.0, 'q' => 1, 'qm' => 1.0, 'ch' => 0, 'cm' => 0, 'mem' => 1024,
        ];
    }

    /** @param array<string,mixed> $overrides */
    private function vital(int $site, string $path, string $device, string $locale, array $overrides = []): array
    {
        return $overrides + [
            'k' => 'v', 'at' => Carbon::now()->subHours(3)->getTimestamp(), 'site' => $site, 'loc' => $locale, 'path' => $path,
            'dev' => $device, 'con' => '4g', 'lcp' => 2000, 'inp' => 100, 'cls' => 0.05, 'ttfb' => 500, 'fcp' => 1000,
        ];
    }

    private function seedData(): void
    {
        $records = [];

        // Site 1: seven requests, one failed; 2 cache hits and 1 miss; 32 queries.
        foreach ([[3, 1, 1, 19456], [4, 1, 0, 20480], [4, 0, 0, 21504], [5, 0, 0, 22528], [5, 0, 0, 23552], [5, 0, 0, 24576], [6, 0, 0, 25600]] as $n => [$q, $ch, $cm, $mem]) {
            $records[] = $this->request(1, 'home', '/', ['d' => 100.0 + $n * 20, 'q' => $q, 'ch' => $ch, 'cm' => $cm, 'mem' => $mem, 's' => $n === 6 ? 500 : 200, 'loc' => $n < 4 ? 'en' : 'de']);
        }

        // Site 2: two requests, 1 hit and 3 misses.
        $records[] = $this->request(2, 'shop.index', '/shop', ['d' => 900.0, 'q' => 10, 'ch' => 1, 'cm' => 1, 'loc' => 'de']);
        $records[] = $this->request(2, 'shop.index', '/shop', ['d' => 1000.0, 'q' => 10, 'ch' => 0, 'cm' => 2, 'loc' => 'de']);

        foreach ([100.0, 200.0, 300.0] as $ms) {
            $records[] = ['k' => 'q', 'at' => Carbon::now()->subHours(3)->getTimestamp(), 'site' => 1, 'route' => 'home', 'path' => '/', 'h' => self::HASH, 'sql' => 'select '.str_repeat('x', 250), 'c' => 'testing', 'd' => $ms];
        }

        $records[] = ['k' => 'q', 'at' => Carbon::now()->subHours(3)->getTimestamp(), 'site' => 2, 'route' => 'shop.index', 'path' => '/shop', 'h' => str_repeat('b', 40), 'sql' => 'select 2', 'c' => 'testing', 'd' => 50.0];

        $records[] = $this->vital(1, '/', 'mobile', 'en', ['lcp' => 1800, 'inp' => 150, 'cls' => 0.04, 'ttfb' => 500, 'fcp' => 1200]);
        $records[] = $this->vital(1, '/catalog', 'mobile', 'en', ['lcp' => 4600, 'inp' => 250, 'cls' => 0.3, 'ttfb' => 900, 'fcp' => 3500]);
        $records[] = $this->vital(1, '/catalog', 'desktop', 'de', ['lcp' => 2900, 'inp' => 80, 'cls' => 0.12, 'ttfb' => 1900, 'fcp' => 2000]);
        $records[] = $this->vital(2, '/', 'mobile', 'de', ['lcp' => 3000, 'inp' => 600, 'cls' => 0.2, 'ttfb' => 100, 'fcp' => 300]);
        $records[] = $this->vital(2, '/shop', 'desktop', 'de', ['lcp' => 100, 'inp' => 10, 'cls' => 0.0, 'ttfb' => 50, 'fcp' => 60]);
        app(Flusher::class)->write($records);

        config(['atrium-monitoring.uptime.failures' => 1]);
        $home = new UptimeCheck;
        $home->forceFill(['site_id' => 1, 'name' => 'Demo home', 'url' => 'https://demo.example.test/', 'is_home' => true])->save();
        $idle = new UptimeCheck;
        $idle->forceFill(['site_id' => 1, 'name' => 'Idle', 'url' => 'https://idle.example.test/'])->save();
        $other = new UptimeCheck;
        $other->forceFill(['site_id' => 2, 'name' => 'Shop home', 'url' => 'https://shop.example.test/'])->save();
        $checker = app(UptimeChecker::class);
        $checker->record($home, true, 200, 100.0, null, Carbon::now()->subMinutes(50));
        $checker->record($home, true, 200, 110.0, null, Carbon::now()->subMinutes(40));
        $checker->record($home, false, 503, 40.0, 'HTTP 503', Carbon::now()->subMinutes(30));
        $checker->record($other, true, 200, 80.0, null, Carbon::now()->subMinutes(5));
    }

    /**
     * Checks and incidents numbered from 1 in the order they were made: the
     * database's own ids go on from earlier tests on MySQL and PostgreSQL.
     *
     * @param  array{checks:array<int,array<string,mixed>>,incidents:array<int,array<string,mixed>>}  $uptime
     * @return array{checks:array<int,array<string,mixed>>,incidents:array<int,array<string,mixed>>}
     */
    private static function numbered(array $uptime): array
    {
        $checks = (int) UptimeCheck::query()->withoutGlobalScopes()->min('id') - 1;
        $incidents = (int) Incident::query()->withoutGlobalScopes()->min('id') - 1;

        $uptime['checks'] = array_map(static fn (array $c): array => ['id' => $c['id'] - $checks] + $c, $uptime['checks']);
        $uptime['incidents'] = array_map(static fn (array $i): array => ['id' => $i['id'] - $incidents] + $i, $uptime['incidents']);

        return $uptime;
    }

    /** @param array<string,mixed> $props */
    private static function digest(array $props): array
    {
        return [
            'filters' => $props['filters'],
            'range' => $props['range'],
            'charts' => array_map(static fn (array $c): array => [$c['key'], count($c['points']), $c['points'][0]['label'], end($c['points'])], $props['server']['charts']),
            'summary' => $props['server']['summary'],
            'routes' => $props['server']['routes'],
            'queries' => array_map(static fn (array $q): array => [$q['hash'][0], $q['count'], $q['p50']], $props['server']['queries']),
            'vitals' => array_map(static fn (array $m): array => [$m['key'], $m['p75'], $m['raw'], $m['rating'], $m['count']], $props['vitals']['metrics']),
            'pages' => $props['vitals']['pages'],
            'uptime' => self::numbered($props['uptime']),
        ];
    }

    public function test_each_filter_narrows_the_page(): void
    {
        $this->seedData();

        foreach (self::expected() as $query => $expected) {
            $this->assertSame($expected, array_intersect_key(self::digest($this->props('/admin/monitoring'.$query)), $expected), 'filter "'.$query.'"');
        }
    }

    public function test_the_page_describes_its_sites_charts_and_thresholds(): void
    {
        $this->seedData();
        $props = $this->props('/admin/monitoring');

        $this->assertSame(['t', 'filters', 'options', 'range', 'server', 'vitals', 'uptime'], array_slice(array_keys($props), -7));
        $this->assertSame('Monitoring', $props['t']['title']);
        $this->assertSame([['value' => 1, 'label' => 'Demo site'], ['value' => 2, 'label' => 'Demo shop']], $props['options']['sites']);
        $this->assertSame(['en'], $props['options']['locales']);
        $this->assertSame(['summary', 'charts', 'routes', 'queries'], array_keys($props['server']));
        $this->assertSame(['metrics', 'pages'], array_keys($props['vitals']));
        $this->assertSame(['checks', 'incidents'], array_keys($props['uptime']));

        $ttfb = $props['server']['charts'][0];
        $this->assertSame(['key', 'svg', 'points'], array_keys($ttfb));
        $this->assertSame(['label' => '19.10', 'count' => 0, 'p50' => '—', 'p75' => '—', 'p95' => '—'], $ttfb['points'][0]);
        $this->assertSame(['label' => '17.11', 'count' => 9, 'p50' => '181 ms', 'p75' => '219 ms', 'p95' => '903 ms'], $ttfb['points'][29]);
        $this->assertStringContainsString('>Time to first byte</title>', $ttfb['svg']);
        $this->assertStringContainsString('>Database time per request</title>', $props['server']['charts'][1]['svg']);

        $titles = ['lcp' => 'Largest Contentful Paint', 'inp' => 'Interaction to Next Paint', 'cls' => 'Cumulative Layout Shift', 'ttfb' => 'Time to First Byte', 'fcp' => 'First Contentful Paint'];
        $thresholds = ['lcp' => ['2.50 s', '4.00 s'], 'inp' => ['200 ms', '500 ms'], 'cls' => ['0.100', '0.250'], 'ttfb' => ['800 ms', '1.80 s'], 'fcp' => ['1.80 s', '3.00 s']];

        foreach ($props['vitals']['metrics'] as $metric) {
            $this->assertSame(['key', 'p75', 'raw', 'rating', 'count', 'thresholds', 'svg', 'points'], array_keys($metric));
            $this->assertSame($thresholds[$metric['key']], $metric['thresholds'], $metric['key']);
            $this->assertStringContainsString('>'.$titles[$metric['key']].'</title>', $metric['svg']);
            $this->assertCount(30, $metric['points']);
            $this->assertSame(['label' => '19.10', 'count' => 0, 'p50' => '—', 'p75' => '—', 'p95' => '—'], $metric['points'][0]);
            $this->assertSame(['label', 'count', 'p50', 'p75', 'p95'], array_keys($metric['points'][29]));
            $this->assertSame(5, $metric['points'][29]['count']);
            $this->assertSame($metric['p75'], $metric['points'][29]['p75']);
        }
    }

    public function test_a_page_filter_keeps_the_site(): void
    {
        $this->seedData();

        $this->assertSame([2, 1, 1], array_map(
            fn (string $query): int => $this->props('/admin/monitoring?'.$query)['vitals']['metrics'][0]['count'],
            ['page=%2F', 'page=%2F&site=1', 'site=2&page=%2F'],
        ));
    }

    public function test_the_page_asked_for_by_month_labels_its_points_by_month(): void
    {
        $this->seedData();
        $props = $this->props('/admin/monitoring?period=month');

        $this->assertSame('Nov 2025', $props['vitals']['metrics'][0]['points'][0]['label']);
        $this->assertSame('Nov 2026', $props['vitals']['metrics'][0]['points'][12]['label']);
    }

    public function test_filters_are_cut_to_their_length_and_junk_is_dropped(): void
    {
        $filters = fn (string $query): array => $this->props('/admin/monitoring?'.$query)['filters'];

        $this->assertSame(str_repeat('é', 12), $filters('locale='.str_repeat('é', 20))['locale']);
        $this->assertSame(str_repeat('é', 12), $filters('locale='.str_repeat('é', 12))['locale']);
        $this->assertSame(str_repeat('r', 191), $filters('route='.str_repeat('r', 200))['route']);
        $this->assertSame(str_repeat('é', 191), $filters('route='.str_repeat('é', 191))['route']);
        $this->assertSame(str_repeat('p', 191), $filters('page='.str_repeat('p', 200))['page']);
        $this->assertSame(str_repeat('é', 191), $filters('page='.str_repeat('é', 191))['page']);

        $this->assertSame('*', $filters('locale[]=de')['locale']);
        $this->assertSame('*', $filters('route[]=home')['route']);
        $this->assertSame('*', $filters('page[]=x')['page']);
        $this->assertSame('*', $filters('device[]=mobile')['device']);
        $this->assertSame('day', $filters('period[]=week')['period']);
        $this->assertSame(['day', 'week', 'month'], array_map(fn (string $p): string => $filters('period='.$p)['period'], ['day', 'week', 'month']));
        $this->assertSame(['mobile', 'tablet', 'desktop', '*'], array_map(fn (string $d): string => $filters('device='.$d)['device'], ['mobile', 'tablet', 'desktop', 'phone']));
        $this->assertSame([2, null, null, 1], array_map(fn (string $s): ?int => $filters('site='.$s)['site'], ['2', 'abc', '99', '1.5']));
        $this->assertNull($filters('site[]=1')['site']);
        $this->assertSame('de', $filters('locale=de')['locale']);
    }

    public function test_a_long_query_text_is_cut_and_a_missing_one_is_blank(): void
    {
        $hashes = [str_repeat('1', 40) => 160, str_repeat('2', 40) => 161, str_repeat('3', 40) => 5];
        $records = [];

        foreach ($hashes as $hash => $length) {
            $records[] = ['k' => 'q', 'at' => Carbon::now()->subHour()->getTimestamp(), 'site' => 1, 'route' => 'home', 'path' => '/', 'h' => $hash, 'sql' => 'é'.str_repeat('x', $length - 1), 'c' => 'testing', 'd' => 10.0];
        }

        app(Flusher::class)->write($records);
        DB::table('monitoring_query_texts')->where('hash', str_repeat('3', 40))->delete();

        $sql = [];

        foreach ($this->props('/admin/monitoring')['server']['queries'] as $query) {
            $sql[$query['hash'][0]] = $query['sql'];
        }

        $this->assertSame(['1' => 'é'.str_repeat('x', 159), '2' => 'é'.str_repeat('x', 158).'…', '3' => ''], $sql);
    }

    public function test_a_route_links_to_its_latest_good_page(): void
    {
        $at = Carbon::now()->subHour()->getTimestamp();
        $records = [];

        foreach ([['/older', 'GET', 200], ['/newest', 'GET', 399], ['/boom', 'GET', 500], ['/posted', 'POST', 200], ['/missing', 'GET', 400]] as [$path, $method, $status]) {
            $records[] = $this->request(1, 'home', $path, ['m' => $method, 's' => $status, 'at' => $at]);
        }

        $records[] = $this->request(1, 'broken', '/broken', ['s' => 500, 'at' => $at]);
        $records[] = $this->request(1, 'broken', '/broken', ['s' => 500, 'at' => $at]);
        app(Flusher::class)->write($records);

        $samples = [];

        foreach ($this->props('/admin/monitoring')['server']['routes'] as $route) {
            $samples[$route['route']] = $route['sample'];
        }

        $this->assertSame(['home' => '/newest', 'broken' => null], $samples);
    }

    public function test_a_query_page_lists_the_twenty_routes_that_run_it(): void
    {
        $at = Carbon::now()->subHour()->getTimestamp();
        $records = [];

        foreach (range(1, 21) as $i) {
            foreach (range(1, $i) as $n) {
                $records[] = ['k' => 'q', 'at' => $at, 'site' => 1, 'route' => sprintf('r%02d', $i), 'path' => '/p'.$i, 'h' => self::HASH, 'sql' => 'select 1', 'c' => 'testing', 'd' => 10.0 * $n];
            }
        }

        app(Flusher::class)->write($records);
        $props = $this->props('/admin/monitoring/queries/'.self::HASH);

        $this->assertSame(array_map(fn (int $i): string => sprintf('r%02d', $i), range(21, 2)), array_column($props['routes'], 'route'));
        $this->assertSame(['route' => 'r21', 'path' => '/p21', 'errors_url' => null, 'times' => 21, 'slowest' => '210 ms', 'average' => '110 ms'], $props['routes'][0]);
        $this->assertSame(['route' => 'r02', 'path' => '/p2', 'errors_url' => null, 'times' => 2, 'slowest' => '20 ms', 'average' => '15 ms'], $props['routes'][19]);
    }

    /** @return array<string,array<string,mixed>> */
    private static function expected(): array
    {
        return [
            '' => [
                'filters' => ['period' => 'day', 'site' => null, 'locale' => '*', 'route' => '*', 'page' => '*', 'device' => '*'],
                'range' => ['from' => '2026-10-19', 'to' => '2026-11-17'],
                'charts' => [
                    [
                        'ttfb',
                        30,
                        '19.10',
                        ['label' => '17.11', 'count' => 9, 'p50' => '181 ms', 'p75' => '219 ms', 'p95' => '903 ms'],
                    ],
                    ['db_ms', 30, '19.10', ['label' => '17.11', 'count' => 9, 'p50' => '1.0 ms', 'p75' => '1.0 ms', 'p95' => '1.0 ms']],
                ],
                'summary' => [
                    'requests' => 9,
                    'ttfb' => ['count' => 9, 'p50' => '181 ms', 'p75' => '219 ms', 'p95' => '903 ms'],
                    'errors' => 11.11,
                    'cache_ratio' => 42.9,
                    'queries' => 5.8,
                    'memory_p95' => 24.4,
                ],
                'routes' => [
                    [
                        'route' => 'shop.index',
                        'sample' => '/shop',
                        'count' => 2,
                        'p50' => '903 ms',
                        'p75' => '903 ms',
                        'p95' => '903 ms',
                    ],
                    ['route' => 'home', 'sample' => '/', 'count' => 7, 'p50' => '164 ms', 'p75' => '181 ms', 'p95' => '199 ms'],
                ],
                'queries' => [['a', 3, '199 ms'], ['b', 1, '50 ms']],
                'vitals' => [
                    ['lcp', '3.06 s', 3058.514, 'needs_improvement', 5],
                    ['inp', '254 ms', 254.013, 'needs_improvement', 5],
                    ['cls', '0.205', 0.205, 'needs_improvement', 5],
                    ['ttfb', '903 ms', 903.188, 'needs_improvement', 5],
                    ['fcp', '1.97 s', 1971.545, 'needs_improvement', 5],
                ],
                'pages' => [
                    [
                        'page' => '/catalog',
                        'rating' => 'needs_improvement',
                        'errors_url' => null,
                        'count' => 2,
                        'p50' => '2.91 s',
                        'p75' => '2.91 s',
                        'p95' => '2.91 s',
                    ],
                    [
                        'page' => '/',
                        'rating' => 'good',
                        'errors_url' => null,
                        'count' => 2,
                        'p50' => '1.80 s',
                        'p75' => '1.80 s',
                        'p95' => '1.80 s',
                    ],
                    [
                        'page' => '/shop',
                        'rating' => 'good',
                        'errors_url' => null,
                        'count' => 1,
                        'p50' => '100 ms',
                        'p75' => '100 ms',
                        'p95' => '100 ms',
                    ],
                ],
                'uptime' => [
                    'checks' => [
                        [
                            'id' => 1,
                            'name' => 'Demo home',
                            'url' => 'https://demo.example.test/',
                            'active' => true,
                            'status' => 'down',
                            'uptime' => 66.67,
                            'response' => '40 ms',
                            'checked_at' => '2026-11-17 11:30',
                            'error' => 'HTTP 503',
                        ],
                        [
                            'id' => 2,
                            'name' => 'Idle',
                            'url' => 'https://idle.example.test/',
                            'active' => true,
                            'status' => 'unknown',
                            'uptime' => null,
                            'response' => null,
                            'checked_at' => null,
                            'error' => null,
                        ],
                        [
                            'id' => 3,
                            'name' => 'Shop home',
                            'url' => 'https://shop.example.test/',
                            'active' => true,
                            'status' => 'up',
                            'uptime' => 100.0,
                            'response' => '80 ms',
                            'checked_at' => '2026-11-17 11:55',
                            'error' => null,
                        ],
                    ],
                    'incidents' => [
                        [
                            'id' => 1,
                            'check' => 'Demo home',
                            'started_at' => '2026-11-17 11:30',
                            'resolved_at' => null,
                            'duration' => '30 minutes',
                            'error' => 'HTTP 503',
                        ],
                    ],
                ],
            ],
            '?site=1' => [
                'filters' => ['period' => 'day', 'site' => 1, 'locale' => '*', 'route' => '*', 'page' => '*', 'device' => '*'],
                'charts' => [
                    [
                        'ttfb',
                        30,
                        '19.10',
                        ['label' => '17.11', 'count' => 7, 'p50' => '164 ms', 'p75' => '181 ms', 'p95' => '199 ms'],
                    ],
                    ['db_ms', 30, '19.10', ['label' => '17.11', 'count' => 7, 'p50' => '1.0 ms', 'p75' => '1.0 ms', 'p95' => '1.0 ms']],
                ],
                'summary' => [
                    'requests' => 7,
                    'ttfb' => ['count' => 7, 'p50' => '164 ms', 'p75' => '181 ms', 'p95' => '199 ms'],
                    'errors' => 14.29,
                    'cache_ratio' => 66.7,
                    'queries' => 4.6,
                    'memory_p95' => 24.4,
                ],
                'routes' => [['route' => 'home', 'sample' => '/', 'count' => 7, 'p50' => '164 ms', 'p75' => '181 ms', 'p95' => '199 ms']],
                'queries' => [['a', 3, '199 ms']],
                'vitals' => [
                    ['lcp', '2.91 s', 2912.871, 'needs_improvement', 3],
                    ['inp', '149 ms', 148.516, 'good', 3],
                    ['cls', '0.120', 0.12, 'needs_improvement', 3],
                    ['ttfb', '903 ms', 903.188, 'needs_improvement', 3],
                    ['fcp', '1.97 s', 1971.545, 'needs_improvement', 3],
                ],
                'pages' => [
                    [
                        'page' => '/catalog',
                        'rating' => 'needs_improvement',
                        'errors_url' => null,
                        'count' => 2,
                        'p50' => '2.91 s',
                        'p75' => '2.91 s',
                        'p95' => '2.91 s',
                    ],
                    [
                        'page' => '/',
                        'rating' => 'good',
                        'errors_url' => null,
                        'count' => 1,
                        'p50' => '1.80 s',
                        'p75' => '1.80 s',
                        'p95' => '1.80 s',
                    ],
                ],
                'uptime' => [
                    'checks' => [
                        [
                            'id' => 1,
                            'name' => 'Demo home',
                            'url' => 'https://demo.example.test/',
                            'active' => true,
                            'status' => 'down',
                            'uptime' => 66.67,
                            'response' => '40 ms',
                            'checked_at' => '2026-11-17 11:30',
                            'error' => 'HTTP 503',
                        ],
                        [
                            'id' => 2,
                            'name' => 'Idle',
                            'url' => 'https://idle.example.test/',
                            'active' => true,
                            'status' => 'unknown',
                            'uptime' => null,
                            'response' => null,
                            'checked_at' => null,
                            'error' => null,
                        ],
                    ],
                    'incidents' => [
                        [
                            'id' => 1,
                            'check' => 'Demo home',
                            'started_at' => '2026-11-17 11:30',
                            'resolved_at' => null,
                            'duration' => '30 minutes',
                            'error' => 'HTTP 503',
                        ],
                    ],
                ],
            ],
            '?site=2' => [
                'filters' => ['period' => 'day', 'site' => 2, 'locale' => '*', 'route' => '*', 'page' => '*', 'device' => '*'],
                'charts' => [
                    [
                        'ttfb',
                        30,
                        '19.10',
                        ['label' => '17.11', 'count' => 2, 'p50' => '903 ms', 'p75' => '903 ms', 'p95' => '903 ms'],
                    ],
                    ['db_ms', 30, '19.10', ['label' => '17.11', 'count' => 2, 'p50' => '1.0 ms', 'p75' => '1.0 ms', 'p95' => '1.0 ms']],
                ],
                'summary' => [
                    'requests' => 2,
                    'ttfb' => ['count' => 2, 'p50' => '903 ms', 'p75' => '903 ms', 'p95' => '903 ms'],
                    'errors' => 0.0,
                    'cache_ratio' => 25.0,
                    'queries' => 10.0,
                    'memory_p95' => 1.0,
                ],
                'routes' => [
                    [
                        'route' => 'shop.index',
                        'sample' => '/shop',
                        'count' => 2,
                        'p50' => '903 ms',
                        'p75' => '903 ms',
                        'p95' => '903 ms',
                    ],
                ],
                'queries' => [['b', 1, '50 ms']],
                'vitals' => [
                    ['lcp', '101 ms', 100.522, 'good', 2],
                    ['inp', '10 ms', 10.148, 'good', 2],
                    ['cls', '0.000', 0.0, 'good', 2],
                    ['ttfb', '51 ms', 50.77, 'good', 2],
                    ['fcp', '60 ms', 60.0, 'good', 2],
                ],
                'pages' => [
                    [
                        'page' => '/',
                        'rating' => 'needs_improvement',
                        'errors_url' => null,
                        'count' => 1,
                        'p50' => '3.00 s',
                        'p75' => '3.00 s',
                        'p95' => '3.00 s',
                    ],
                    [
                        'page' => '/shop',
                        'rating' => 'good',
                        'errors_url' => null,
                        'count' => 1,
                        'p50' => '100 ms',
                        'p75' => '100 ms',
                        'p95' => '100 ms',
                    ],
                ],
                'uptime' => [
                    'checks' => [
                        [
                            'id' => 3,
                            'name' => 'Shop home',
                            'url' => 'https://shop.example.test/',
                            'active' => true,
                            'status' => 'up',
                            'uptime' => 100.0,
                            'response' => '80 ms',
                            'checked_at' => '2026-11-17 11:55',
                            'error' => null,
                        ],
                    ],
                    'incidents' => [],
                ],
            ],
            '?route=ghost' => [
                'filters' => ['period' => 'day', 'site' => null, 'locale' => '*', 'route' => 'ghost', 'page' => '*', 'device' => '*'],
                'charts' => [
                    ['ttfb', 30, '19.10', ['label' => '17.11', 'count' => 0, 'p50' => '—', 'p75' => '—', 'p95' => '—']],
                    ['db_ms', 30, '19.10', ['label' => '17.11', 'count' => 0, 'p50' => '—', 'p75' => '—', 'p95' => '—']],
                ],
                'summary' => [
                    'requests' => 0,
                    'ttfb' => ['count' => 0, 'p50' => '—', 'p75' => '—', 'p95' => '—'],
                    'errors' => null,
                    'cache_ratio' => null,
                    'queries' => null,
                    'memory_p95' => null,
                ],
                'routes' => [
                    [
                        'route' => 'shop.index',
                        'sample' => '/shop',
                        'count' => 2,
                        'p50' => '903 ms',
                        'p75' => '903 ms',
                        'p95' => '903 ms',
                    ],
                    ['route' => 'home', 'sample' => '/', 'count' => 7, 'p50' => '164 ms', 'p75' => '181 ms', 'p95' => '199 ms'],
                ],
                'queries' => [['a', 3, '199 ms'], ['b', 1, '50 ms']],
                'vitals' => [
                    ['lcp', '3.06 s', 3058.514, 'needs_improvement', 5],
                    ['inp', '254 ms', 254.013, 'needs_improvement', 5],
                    ['cls', '0.205', 0.205, 'needs_improvement', 5],
                    ['ttfb', '903 ms', 903.188, 'needs_improvement', 5],
                    ['fcp', '1.97 s', 1971.545, 'needs_improvement', 5],
                ],
                'pages' => [
                    [
                        'page' => '/catalog',
                        'rating' => 'needs_improvement',
                        'errors_url' => null,
                        'count' => 2,
                        'p50' => '2.91 s',
                        'p75' => '2.91 s',
                        'p95' => '2.91 s',
                    ],
                    [
                        'page' => '/',
                        'rating' => 'good',
                        'errors_url' => null,
                        'count' => 2,
                        'p50' => '1.80 s',
                        'p75' => '1.80 s',
                        'p95' => '1.80 s',
                    ],
                    [
                        'page' => '/shop',
                        'rating' => 'good',
                        'errors_url' => null,
                        'count' => 1,
                        'p50' => '100 ms',
                        'p75' => '100 ms',
                        'p95' => '100 ms',
                    ],
                ],
            ],
            '?site=1&device=mobile' => [
                'filters' => ['period' => 'day', 'site' => 1, 'locale' => '*', 'route' => '*', 'page' => '*', 'device' => 'mobile'],
                'charts' => [
                    [
                        'ttfb',
                        30,
                        '19.10',
                        ['label' => '17.11', 'count' => 7, 'p50' => '164 ms', 'p75' => '181 ms', 'p95' => '199 ms'],
                    ],
                    ['db_ms', 30, '19.10', ['label' => '17.11', 'count' => 7, 'p50' => '1.0 ms', 'p75' => '1.0 ms', 'p95' => '1.0 ms']],
                ],
                'summary' => [
                    'requests' => 7,
                    'ttfb' => ['count' => 7, 'p50' => '164 ms', 'p75' => '181 ms', 'p95' => '199 ms'],
                    'errors' => 14.29,
                    'cache_ratio' => 66.7,
                    'queries' => 4.6,
                    'memory_p95' => 24.4,
                ],
                'routes' => [['route' => 'home', 'sample' => '/', 'count' => 7, 'p50' => '164 ms', 'p75' => '181 ms', 'p95' => '199 ms']],
                'queries' => [['a', 3, '199 ms']],
                'vitals' => [
                    ['lcp', '1.80 s', 1800.0, 'good', 2],
                    ['inp', '150 ms', 150.0, 'good', 2],
                    ['cls', '0.041', 0.041, 'good', 2],
                    ['ttfb', '503 ms', 502.929, 'good', 2],
                    ['fcp', '1.21 s', 1210.358, 'good', 2],
                ],
                'pages' => [],
            ],
            '?site=1&locale=de' => [
                'filters' => ['period' => 'day', 'site' => 1, 'locale' => 'de', 'route' => '*', 'page' => '*', 'device' => '*'],
                'charts' => [
                    [
                        'ttfb',
                        30,
                        '19.10',
                        ['label' => '17.11', 'count' => 3, 'p50' => '199 ms', 'p75' => '199 ms', 'p95' => '199 ms'],
                    ],
                    ['db_ms', 30, '19.10', ['label' => '17.11', 'count' => 3, 'p50' => '1.0 ms', 'p75' => '1.0 ms', 'p95' => '1.0 ms']],
                ],
                'summary' => [
                    'requests' => 3,
                    'ttfb' => ['count' => 3, 'p50' => '199 ms', 'p75' => '199 ms', 'p95' => '199 ms'],
                    'errors' => 33.33,
                    'cache_ratio' => null,
                    'queries' => 5.3,
                    'memory_p95' => 24.4,
                ],
                'routes' => [['route' => 'home', 'sample' => '/', 'count' => 7, 'p50' => '164 ms', 'p75' => '181 ms', 'p95' => '199 ms']],
                'queries' => [['a', 3, '199 ms']],
                'vitals' => [
                    ['lcp', '2.90 s', 2900.0, 'needs_improvement', 1],
                    ['inp', '80 ms', 80.0, 'good', 1],
                    ['cls', '0.120', 0.12, 'needs_improvement', 1],
                    ['ttfb', '1.90 s', 1900.0, 'poor', 1],
                    ['fcp', '2.00 s', 2000.0, 'needs_improvement', 1],
                ],
                'pages' => [],
            ],
            '?route=home&locale=de' => [
                'filters' => ['period' => 'day', 'site' => null, 'locale' => 'de', 'route' => 'home', 'page' => '*', 'device' => '*'],
                'charts' => [
                    [
                        'ttfb',
                        30,
                        '19.10',
                        ['label' => '17.11', 'count' => 7, 'p50' => '164 ms', 'p75' => '181 ms', 'p95' => '199 ms'],
                    ],
                    ['db_ms', 30, '19.10', ['label' => '17.11', 'count' => 7, 'p50' => '1.0 ms', 'p75' => '1.0 ms', 'p95' => '1.0 ms']],
                ],
                'summary' => [
                    'requests' => 7,
                    'ttfb' => ['count' => 7, 'p50' => '164 ms', 'p75' => '181 ms', 'p95' => '199 ms'],
                    'errors' => 14.29,
                    'cache_ratio' => 66.7,
                    'queries' => 4.6,
                    'memory_p95' => 24.4,
                ],
                'routes' => [
                    [
                        'route' => 'shop.index',
                        'sample' => '/shop',
                        'count' => 2,
                        'p50' => '903 ms',
                        'p75' => '903 ms',
                        'p95' => '903 ms',
                    ],
                    ['route' => 'home', 'sample' => '/', 'count' => 7, 'p50' => '164 ms', 'p75' => '181 ms', 'p95' => '199 ms'],
                ],
                'queries' => [['a', 3, '199 ms'], ['b', 1, '50 ms']],
                'vitals' => [
                    ['lcp', '2.91 s', 2912.871, 'needs_improvement', 3],
                    ['inp', '79 ms', 78.761, 'good', 3],
                    ['cls', '0.120', 0.12, 'needs_improvement', 3],
                    ['ttfb', '101 ms', 100.522, 'good', 3],
                    ['fcp', '294 ms', 294.052, 'good', 3],
                ],
                'pages' => [],
            ],
            '?site=1&route=home' => [
                'filters' => ['period' => 'day', 'site' => 1, 'locale' => '*', 'route' => 'home', 'page' => '*', 'device' => '*'],
                'charts' => [
                    [
                        'ttfb',
                        30,
                        '19.10',
                        ['label' => '17.11', 'count' => 7, 'p50' => '164 ms', 'p75' => '181 ms', 'p95' => '199 ms'],
                    ],
                    ['db_ms', 30, '19.10', ['label' => '17.11', 'count' => 7, 'p50' => '1.0 ms', 'p75' => '1.0 ms', 'p95' => '1.0 ms']],
                ],
                'summary' => [
                    'requests' => 7,
                    'ttfb' => ['count' => 7, 'p50' => '164 ms', 'p75' => '181 ms', 'p95' => '199 ms'],
                    'errors' => 14.29,
                    'cache_ratio' => 66.7,
                    'queries' => 4.6,
                    'memory_p95' => 24.4,
                ],
                'routes' => [['route' => 'home', 'sample' => '/', 'count' => 7, 'p50' => '164 ms', 'p75' => '181 ms', 'p95' => '199 ms']],
                'queries' => [['a', 3, '199 ms']],
                'vitals' => [
                    ['lcp', '2.91 s', 2912.871, 'needs_improvement', 3],
                    ['inp', '149 ms', 148.516, 'good', 3],
                    ['cls', '0.120', 0.12, 'needs_improvement', 3],
                    ['ttfb', '903 ms', 903.188, 'needs_improvement', 3],
                    ['fcp', '1.97 s', 1971.545, 'needs_improvement', 3],
                ],
                'pages' => [
                    [
                        'page' => '/catalog',
                        'rating' => 'needs_improvement',
                        'errors_url' => null,
                        'count' => 2,
                        'p50' => '2.91 s',
                        'p75' => '2.91 s',
                        'p95' => '2.91 s',
                    ],
                    [
                        'page' => '/',
                        'rating' => 'good',
                        'errors_url' => null,
                        'count' => 1,
                        'p50' => '1.80 s',
                        'p75' => '1.80 s',
                        'p95' => '1.80 s',
                    ],
                ],
            ],
            '?page=%2Fcatalog&device=mobile&locale=de&site=1' => [
                'filters' => ['period' => 'day', 'site' => 1, 'locale' => 'de', 'route' => '*', 'page' => '/catalog', 'device' => 'mobile'],
                'charts' => [
                    [
                        'ttfb',
                        30,
                        '19.10',
                        ['label' => '17.11', 'count' => 3, 'p50' => '199 ms', 'p75' => '199 ms', 'p95' => '199 ms'],
                    ],
                    ['db_ms', 30, '19.10', ['label' => '17.11', 'count' => 3, 'p50' => '1.0 ms', 'p75' => '1.0 ms', 'p95' => '1.0 ms']],
                ],
                'summary' => [
                    'requests' => 3,
                    'ttfb' => ['count' => 3, 'p50' => '199 ms', 'p75' => '199 ms', 'p95' => '199 ms'],
                    'errors' => 33.33,
                    'cache_ratio' => null,
                    'queries' => 5.3,
                    'memory_p95' => 24.4,
                ],
                'routes' => [['route' => 'home', 'sample' => '/', 'count' => 7, 'p50' => '164 ms', 'p75' => '181 ms', 'p95' => '199 ms']],
                'queries' => [['a', 3, '199 ms']],
                'vitals' => [
                    ['lcp', '2.91 s', 2912.871, 'needs_improvement', 2],
                    ['inp', '80 ms', 80.0, 'good', 2],
                    ['cls', '0.120', 0.12, 'needs_improvement', 2],
                    ['ttfb', '903 ms', 903.188, 'needs_improvement', 2],
                    ['fcp', '2.00 s', 2000.0, 'needs_improvement', 2],
                ],
                'pages' => [],
            ],
            '?device=mobile&locale=de&site=1' => [
                'filters' => ['period' => 'day', 'site' => 1, 'locale' => 'de', 'route' => '*', 'page' => '*', 'device' => 'mobile'],
                'charts' => [
                    [
                        'ttfb',
                        30,
                        '19.10',
                        ['label' => '17.11', 'count' => 3, 'p50' => '199 ms', 'p75' => '199 ms', 'p95' => '199 ms'],
                    ],
                    ['db_ms', 30, '19.10', ['label' => '17.11', 'count' => 3, 'p50' => '1.0 ms', 'p75' => '1.0 ms', 'p95' => '1.0 ms']],
                ],
                'summary' => [
                    'requests' => 3,
                    'ttfb' => ['count' => 3, 'p50' => '199 ms', 'p75' => '199 ms', 'p95' => '199 ms'],
                    'errors' => 33.33,
                    'cache_ratio' => null,
                    'queries' => 5.3,
                    'memory_p95' => 24.4,
                ],
                'routes' => [['route' => 'home', 'sample' => '/', 'count' => 7, 'p50' => '164 ms', 'p75' => '181 ms', 'p95' => '199 ms']],
                'queries' => [['a', 3, '199 ms']],
                'vitals' => [
                    ['lcp', '1.80 s', 1800.0, 'good', 2],
                    ['inp', '150 ms', 150.0, 'good', 2],
                    ['cls', '0.041', 0.041, 'good', 2],
                    ['ttfb', '503 ms', 502.929, 'good', 2],
                    ['fcp', '1.21 s', 1210.358, 'good', 2],
                ],
                'pages' => [],
            ],
            '?locale=de&site=2' => [
                'filters' => ['period' => 'day', 'site' => 2, 'locale' => 'de', 'route' => '*', 'page' => '*', 'device' => '*'],
                'charts' => [
                    [
                        'ttfb',
                        30,
                        '19.10',
                        ['label' => '17.11', 'count' => 2, 'p50' => '903 ms', 'p75' => '903 ms', 'p95' => '903 ms'],
                    ],
                    ['db_ms', 30, '19.10', ['label' => '17.11', 'count' => 2, 'p50' => '1.0 ms', 'p75' => '1.0 ms', 'p95' => '1.0 ms']],
                ],
                'summary' => [
                    'requests' => 2,
                    'ttfb' => ['count' => 2, 'p50' => '903 ms', 'p75' => '903 ms', 'p95' => '903 ms'],
                    'errors' => 0.0,
                    'cache_ratio' => 25.0,
                    'queries' => 10.0,
                    'memory_p95' => 1.0,
                ],
                'routes' => [
                    [
                        'route' => 'shop.index',
                        'sample' => '/shop',
                        'count' => 2,
                        'p50' => '903 ms',
                        'p75' => '903 ms',
                        'p95' => '903 ms',
                    ],
                ],
                'queries' => [['b', 1, '50 ms']],
                'vitals' => [
                    ['lcp', '101 ms', 100.522, 'good', 2],
                    ['inp', '10 ms', 10.148, 'good', 2],
                    ['cls', '0.000', 0.0, 'good', 2],
                    ['ttfb', '51 ms', 50.77, 'good', 2],
                    ['fcp', '60 ms', 60.0, 'good', 2],
                ],
                'pages' => [],
            ],
            '?locale=de' => [
                'filters' => ['period' => 'day', 'site' => null, 'locale' => 'de', 'route' => '*', 'page' => '*', 'device' => '*'],
                'charts' => [
                    [
                        'ttfb',
                        30,
                        '19.10',
                        ['label' => '17.11', 'count' => 5, 'p50' => '219 ms', 'p75' => '903 ms', 'p95' => '903 ms'],
                    ],
                    ['db_ms', 30, '19.10', ['label' => '17.11', 'count' => 5, 'p50' => '1.0 ms', 'p75' => '1.0 ms', 'p95' => '1.0 ms']],
                ],
                'summary' => [
                    'requests' => 5,
                    'ttfb' => ['count' => 5, 'p50' => '219 ms', 'p75' => '903 ms', 'p95' => '903 ms'],
                    'errors' => 20.0,
                    'cache_ratio' => 25.0,
                    'queries' => 7.2,
                    'memory_p95' => 24.4,
                ],
                'routes' => [
                    [
                        'route' => 'shop.index',
                        'sample' => '/shop',
                        'count' => 2,
                        'p50' => '903 ms',
                        'p75' => '903 ms',
                        'p95' => '903 ms',
                    ],
                    ['route' => 'home', 'sample' => '/', 'count' => 7, 'p50' => '164 ms', 'p75' => '181 ms', 'p95' => '199 ms'],
                ],
                'queries' => [['a', 3, '199 ms'], ['b', 1, '50 ms']],
                'vitals' => [
                    ['lcp', '2.91 s', 2912.871, 'needs_improvement', 3],
                    ['inp', '79 ms', 78.761, 'good', 3],
                    ['cls', '0.120', 0.12, 'needs_improvement', 3],
                    ['ttfb', '101 ms', 100.522, 'good', 3],
                    ['fcp', '294 ms', 294.052, 'good', 3],
                ],
                'pages' => [],
            ],
            '?page=%2Fcatalog' => [
                'filters' => ['period' => 'day', 'site' => null, 'locale' => '*', 'route' => '*', 'page' => '/catalog', 'device' => '*'],
                'charts' => [
                    [
                        'ttfb',
                        30,
                        '19.10',
                        ['label' => '17.11', 'count' => 9, 'p50' => '181 ms', 'p75' => '219 ms', 'p95' => '903 ms'],
                    ],
                    ['db_ms', 30, '19.10', ['label' => '17.11', 'count' => 9, 'p50' => '1.0 ms', 'p75' => '1.0 ms', 'p95' => '1.0 ms']],
                ],
                'summary' => [
                    'requests' => 9,
                    'ttfb' => ['count' => 9, 'p50' => '181 ms', 'p75' => '219 ms', 'p95' => '903 ms'],
                    'errors' => 11.11,
                    'cache_ratio' => 42.9,
                    'queries' => 5.8,
                    'memory_p95' => 24.4,
                ],
                'routes' => [
                    [
                        'route' => 'shop.index',
                        'sample' => '/shop',
                        'count' => 2,
                        'p50' => '903 ms',
                        'p75' => '903 ms',
                        'p95' => '903 ms',
                    ],
                    ['route' => 'home', 'sample' => '/', 'count' => 7, 'p50' => '164 ms', 'p75' => '181 ms', 'p95' => '199 ms'],
                ],
                'queries' => [['a', 3, '199 ms'], ['b', 1, '50 ms']],
                'vitals' => [
                    ['lcp', '2.91 s', 2912.871, 'needs_improvement', 2],
                    ['inp', '80 ms', 80.0, 'good', 2],
                    ['cls', '0.120', 0.12, 'needs_improvement', 2],
                    ['ttfb', '903 ms', 903.188, 'needs_improvement', 2],
                    ['fcp', '2.00 s', 2000.0, 'needs_improvement', 2],
                ],
                'pages' => [],
            ],
            '?period=week' => [
                'filters' => ['period' => 'week', 'site' => null, 'locale' => '*', 'route' => '*', 'page' => '*', 'device' => '*'],
                'range' => ['from' => '2026-05-25', 'to' => '2026-11-22'],
                'charts' => [
                    [
                        'ttfb',
                        26,
                        '25.05',
                        ['label' => '16.11', 'count' => 9, 'p50' => '181 ms', 'p75' => '219 ms', 'p95' => '903 ms'],
                    ],
                    ['db_ms', 26, '25.05', ['label' => '16.11', 'count' => 9, 'p50' => '1.0 ms', 'p75' => '1.0 ms', 'p95' => '1.0 ms']],
                ],
            ],
            '?period=month' => [
                'filters' => ['period' => 'month', 'site' => null, 'locale' => '*', 'route' => '*', 'page' => '*', 'device' => '*'],
                'range' => ['from' => '2025-11-01', 'to' => '2026-11-30'],
                'charts' => [
                    [
                        'ttfb',
                        13,
                        'Nov 2025',
                        ['label' => 'Nov 2026', 'count' => 9, 'p50' => '181 ms', 'p75' => '219 ms', 'p95' => '903 ms'],
                    ],
                    [
                        'db_ms',
                        13,
                        'Nov 2025',
                        ['label' => 'Nov 2026', 'count' => 9, 'p50' => '1.0 ms', 'p75' => '1.0 ms', 'p95' => '1.0 ms'],
                    ],
                ],
            ],
        ];
    }

    public function test_the_query_page_shows_the_text_and_its_chart(): void
    {
        $this->seedData();
        $props = $this->props('/admin/monitoring/queries/'.self::HASH);

        $this->assertSame(['t', 'query', 'chart', 'points', 'routes'], array_slice(array_keys($props), -5));
        $this->assertSame('Monitoring', $props['t']['title']);
        $this->assertSame(['hash' => self::HASH, 'sql' => 'select '.str_repeat('x', 250), 'connection' => 'testing', 'last_seen' => '2026-11-17 09:00:00'], $props['query']);
        $this->assertStringContainsString('>Query time</title>', $props['chart']);
        $this->assertCount(30, $props['points']);
        $this->assertSame(['label' => '19.10', 'count' => 0, 'p50' => '—', 'p75' => '—', 'p95' => '—'], $props['points'][0]);
        $this->assertSame(['label' => '17.11', 'count' => 3, 'p50' => '199 ms', 'p75' => '199 ms', 'p95' => '199 ms'], $props['points'][29]);
        $this->assertSame([['route' => 'home', 'path' => '/', 'errors_url' => null, 'times' => 3, 'slowest' => '300 ms', 'average' => '200 ms']], $props['routes']);
    }

    public function test_only_a_lowercase_hex_hash_of_forty_characters_opens_a_query(): void
    {
        $bad = [str_repeat('A', 40), str_repeat('a', 39), str_repeat('a', 41), 'x'.str_repeat('a', 40), str_repeat('a', 40).'x', str_repeat('a', 39).'g', str_repeat('a', 39).'-'];

        // A row for every refused address, so that only the check of the address
        // can refuse it. SQLite holds them all; on MySQL "AAA…" is the same key
        // as the lower-case hash (which is there) and PostgreSQL's char(40) takes
        // no 41st character, so there the address check has the hash row alone.
        $rows = DB::getDriverName() === 'sqlite' ? [self::HASH, ...$bad] : [self::HASH];

        foreach ($rows as $hash) {
            DB::table('monitoring_query_texts')->insert(['hash' => $hash, 'sql' => 'select 1', 'connection' => 'testing', 'last_seen_at' => Carbon::now()]);
        }

        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->get('/admin/monitoring/queries/'.self::HASH)->assertOk();
        $this->actingAs($admin, 'admin')->get('/admin/monitoring/queries/'.str_repeat('0123456789', 4))->assertNotFound();

        foreach ($bad as $hash) {
            $this->actingAs($admin, 'admin')->get('/admin/monitoring/queries/'.$hash)->assertNotFound();
        }

        DB::table('monitoring_query_texts')->insert(['hash' => str_repeat('0123456789', 4), 'sql' => 'select 1', 'connection' => 'testing', 'last_seen_at' => Carbon::now()]);
        $this->actingAs($admin, 'admin')->get('/admin/monitoring/queries/'.str_repeat('0123456789', 4))->assertOk();
    }

    /** @param array<string,mixed> $attributes */
    private function check(int $site, string $name, array $attributes = []): UptimeCheck
    {
        $check = new UptimeCheck;
        $check->forceFill(['site_id' => $site, 'name' => $name, 'url' => 'https://'.strtolower($name).'.example.test/'] + $attributes)->save();

        return $check;
    }

    public function test_uptime_lists_the_home_check_first_and_the_ten_latest_incidents(): void
    {
        $alpha = $this->check(1, 'Alpha', ['active' => false]);
        $this->check(1, 'Bravo', ['is_home' => true]);
        $charlie = $this->check(2, 'Charlie');

        $made = [];

        foreach (range(1, 12) as $n) {
            $started = Carbon::now()->subHours($n);
            $made[$n] = Incident::query()->create(['check_id' => $alpha->id, 'started_at' => $started, 'resolved_at' => $n === 1 ? null : $started->copy()->addMinutes(10), 'failures' => 3, 'last_error' => 'Error '.$n]);
        }

        $shop = Incident::query()->create(['check_id' => $charlie->id, 'started_at' => Carbon::now()->subMinutes(30), 'resolved_at' => Carbon::now()->subMinutes(5), 'failures' => 3, 'last_error' => 'Shop error']);

        $all = $this->props('/admin/monitoring')['uptime'];
        $this->assertSame(['Bravo', 'Alpha', 'Charlie'], array_column($all['checks'], 'name'));
        $this->assertSame(['id' => $alpha->id, 'name' => 'Alpha', 'url' => 'https://alpha.example.test/', 'active' => false, 'status' => 'unknown', 'uptime' => null, 'response' => null, 'checked_at' => null, 'error' => null], $all['checks'][1]);
        $this->assertSame(['Shop error', 'Error 1', 'Error 2', 'Error 3', 'Error 4', 'Error 5', 'Error 6', 'Error 7', 'Error 8', 'Error 9'], array_column($all['incidents'], 'error'));
        // The second site's check keeps its name though the panel stands on the first.
        $this->assertSame(['id' => $shop->id, 'check' => 'Charlie', 'started_at' => '2026-11-17 11:30', 'resolved_at' => '2026-11-17 11:55', 'duration' => '25 minutes', 'error' => 'Shop error'], $all['incidents'][0]);
        $this->assertSame(['id' => $made[1]->id, 'check' => 'Alpha', 'started_at' => '2026-11-17 11:00', 'resolved_at' => null, 'duration' => '60 minutes', 'error' => 'Error 1'], $all['incidents'][1]);
        $this->assertSame(['id' => $made[2]->id, 'check' => 'Alpha', 'started_at' => '2026-11-17 10:00', 'resolved_at' => '2026-11-17 10:10', 'duration' => '10 minutes', 'error' => 'Error 2'], $all['incidents'][2]);

        $first = $this->props('/admin/monitoring?site=1')['uptime'];
        $this->assertSame(['Bravo', 'Alpha'], array_column($first['checks'], 'name'));
        $this->assertSame(array_map(fn (int $n): string => 'Error '.$n, range(1, 10)), array_column($first['incidents'], 'error'));

        $second = $this->props('/admin/monitoring?site=2')['uptime'];
        $this->assertSame(['Charlie'], array_column($second['checks'], 'name'));
        $this->assertSame(['Shop error'], array_column($second['incidents'], 'error'));
    }

    public function test_an_incident_whose_check_is_gone_has_a_blank_name_and_shows_for_all_sites_only(): void
    {
        Schema::disableForeignKeyConstraints();
        $orphan = Incident::query()->create(['check_id' => 999, 'started_at' => Carbon::now()->subMinutes(5), 'failures' => 1, 'last_error' => 'Orphan']);

        $this->assertSame([['id' => $orphan->id, 'check' => '', 'started_at' => '2026-11-17 11:55', 'resolved_at' => null, 'duration' => '5 minutes', 'error' => 'Orphan']], $this->props('/admin/monitoring')['uptime']['incidents']);
        $this->assertSame([], $this->props('/admin/monitoring?site=1')['uptime']['incidents']);
    }
}
