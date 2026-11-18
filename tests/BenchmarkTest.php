<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Monitoring\Buffer\Buffer;
use Atrium\Monitoring\Collector\Benchmark;
use Atrium\Monitoring\Collector\Recorder;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The promise is under a millisecond per request. Xdebug slows everything
 * several times over, so the budget is only checked with it off; the
 * numbers on a quiet Linux box are in docs/monitoring.md.
 */
final class BenchmarkTest extends TestCase
{
    /** This test's own temporary directory: other test processes run benchmarks at the same time. */
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().'/atrium-monitoring-benchtest-'.getmypid().'-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->scratch);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    public function test_the_middleware_adds_less_than_a_millisecond(): void
    {
        $result = app(Benchmark::class)->run(1000, 20, 5);

        $this->assertSame(1000, $result['requests']);
        $this->assertSame(20, $result['queries']);
        $this->assertSame(5, $result['rounds']);
        $this->assertGreaterThan(0.0, $result['baseline_ms']);
        $this->assertGreaterThan($result['baseline_ms'], $result['measured_ms'], 'measuring is not free');
        $this->assertGreaterThanOrEqual(0.0, $result['overhead_ms']);

        // Xdebug in any mode slows PHP down several times: the budget is for production PHP.
        if (! function_exists('xdebug_info') || xdebug_info('mode') === []) {
            $this->assertLessThan(1.0, $result['overhead_ms']);
        }
    }

    public function test_it_leaves_the_app_as_it_found_it(): void
    {
        config(['atrium-monitoring.server.sample_rate' => 0.25, 'atrium-monitoring.server.enabled' => false]);
        $recorder = app(Recorder::class);
        $before = glob($this->scratch.'/atrium-monitoring-bench-*') ?: [];

        $result = (new Benchmark(app('events'), $this->scratch))->run(10, 0, 3);

        $this->assertSame(9, $result['requests'], 'three rounds of three');
        $this->assertSame(0.25, config('atrium-monitoring.server.sample_rate'));
        $this->assertFalse(config('atrium-monitoring.server.enabled'));
        $this->assertSame($recorder, app(Recorder::class));
        $this->assertSame($before, glob($this->scratch.'/atrium-monitoring-bench-*') ?: []);
        $this->assertSame(0, app(Buffer::class)->size(), 'the real buffer was not touched');
    }

    /** @return array<string,array{0:array<int,float>,1:float}> */
    public static function medians(): array
    {
        return [
            'none' => [[], 0.0],
            'one' => [[3.5], 3.5],
            'odd, unsorted' => [[9.0, 1.0, 5.0], 5.0],
            'even' => [[4.0, 1.0, 3.0, 2.0], 2.5],
            'an outlier does not move it' => [[1.0, 1.1, 1.2, 1.3, 90.0], 1.2],
        ];
    }

    /** @param array<int,float> $values */
    #[DataProvider('medians')]
    public function test_median(array $values, float $median): void
    {
        $this->assertEqualsWithDelta($median, Benchmark::median($values), 1e-9);
    }

    public function test_the_command_prints_the_numbers_and_passes_under_a_millisecond(): void
    {
        $this->app->instance(Benchmark::class, $this->fake(0.25));

        $this->artisan('atrium:monitoring:benchmark', ['--requests' => 300, '--queries' => 7, '--rounds' => 3])
            ->expectsTable(['Requests', 'Queries each', 'Rounds', 'Without, ms', 'With, ms', 'Added per request, ms'], [[300, 7, 3, '1.0000', '1.2500', '0.2500']])
            ->assertSuccessful();
    }

    public function test_the_command_fails_at_a_millisecond_or_more(): void
    {
        $this->app->instance(Benchmark::class, $this->fake(1.0));
        $this->artisan('atrium:monitoring:benchmark')->assertFailed();
    }

    public function test_the_command_clamps_nonsense_options(): void
    {
        $fake = $this->fake(0.1);
        $this->app->instance(Benchmark::class, $fake);

        $this->artisan('atrium:monitoring:benchmark', ['--requests' => -5, '--queries' => -1, '--rounds' => 0])->assertSuccessful();
        $this->assertSame([1, 0, 1], $fake->asked);

        $this->artisan('atrium:monitoring:benchmark')->assertSuccessful();
        $this->assertSame([2000, 20, 5], $fake->asked, 'the defaults');
    }

    /**
     * A Benchmark whose clock reads the given nanoseconds one by one, so every
     * figure of the result is known to the last digit; reading it more often
     * than the script allows is an error.
     *
     * @param  array<int,int>  $ticks
     */
    private function clocked(array $ticks): Benchmark
    {
        return new class(app('events'), $ticks, $this->scratch) extends Benchmark
        {
            public int $reads = 0;

            /** @param array<int,int> $ticks */
            public function __construct(Dispatcher $events, private readonly array $ticks, string $scratch)
            {
                parent::__construct($events, $scratch);
            }

            protected function nanoseconds(): int
            {
                return $this->ticks[$this->reads++] ?? throw new RuntimeException('the clock was read more often than expected');
            }
        };
    }

    public function test_the_figures_are_milliseconds_per_request_and_the_median_of_the_rounds(): void
    {
        $bench = $this->clocked([
            0, 6_000_000, 6_000_000, 15_000_000,
            100_000_000, 112_000_000, 112_000_000, 133_000_000,
            200_000_000, 209_000_000, 209_000_000, 254_000_000,
        ]);

        // Without: 2, 4, 3 ms; with: 3, 7, 15 ms; added: 1, 3, 12 ms.
        $this->assertSame([
            'requests' => 9,
            'queries' => 2,
            'rounds' => 3,
            'baseline_ms' => 3.0,
            'measured_ms' => 7.0,
            'overhead_ms' => 3.0,
        ], $bench->run(9, 2, 3));
        $this->assertSame(12, $bench->reads, 'two readings around each of the two loops of a round');
    }

    public function test_measuring_cheaper_than_not_measuring_adds_nothing(): void
    {
        $bench = $this->clocked([0, 6_000_000, 6_000_000, 8_000_000]);

        $result = $bench->run(3, 0, 1);

        $this->assertSame(2.0, $result['baseline_ms']);
        $this->assertSame(0.6666666666666666, $result['measured_ms']);
        $this->assertSame(0.0, $result['overhead_ms'], 'never negative');
    }

    /** @return array<string,array{0:int,1:int,2:int,3:int}> */
    public static function shapes(): array
    {
        return [
            // requests, rounds asked, requests run, rounds run
            'nothing asked' => [0, 0, 1, 1],
            'fewer requests than rounds' => [2, 3, 3, 3],
            'more rounds than requests' => [5, 10, 10, 10],
            'the rest is dropped' => [7, 2, 6, 2],
            'negative' => [-4, -2, 1, 1],
            'even' => [12, 4, 12, 4],
        ];
    }

    #[DataProvider('shapes')]
    public function test_the_loops_run_whole_rounds_of_at_least_one_request(int $requests, int $rounds, int $ran, int $roundsRan): void
    {
        $queries = 0;
        app('events')->listen(QueryExecuted::class, function () use (&$queries): void {
            $queries++;
        });
        $calls = 0;
        app('events')->listen(CacheHit::class, function () use (&$calls): void {
            $calls++;
        });

        $result = $this->clocked(array_fill(0, 4 * $roundsRan, 1_000_000))->run($requests, 0, $rounds);

        $this->assertSame($ran, $result['requests']);
        $this->assertSame($roundsRan, $result['rounds']);
        $this->assertSame(0, $queries);
        $this->assertSame(2 * 50 + 2 * $ran, $calls, 'fifty warm-up requests of each kind, then every request of every round');
    }

    public function test_every_request_runs_its_queries_then_the_cache_reads(): void
    {
        $events = [];
        $counts = [];
        $record = function (object $event) use (&$events, &$counts): void {
            $counts[$event::class] = ($counts[$event::class] ?? 0) + 1;

            if (count($events) < 5) {
                $events[] = $event;
            }
        };
        foreach ([QueryExecuted::class, CacheHit::class, CacheMissed::class] as $class) {
            app('events')->listen($class, $record);
        }

        $this->clocked(array_fill(0, 4, 0))->run(1, 3, 1);

        $this->assertSame([QueryExecuted::class => 3 * 102, CacheHit::class => 102, CacheMissed::class => 102], $counts, 'two requests on each of 50 warm-ups and the two loops of the round');
        [$q0, $q1, $q2, $hit, $miss] = $events;
        $this->assertInstanceOf(QueryExecuted::class, $q0);
        $this->assertInstanceOf(QueryExecuted::class, $q1);
        $this->assertInstanceOf(QueryExecuted::class, $q2);
        $this->assertSame('select * from "pages" where "id" = ? limit 1', $q0->sql);
        $this->assertSame([[0], [1], [2]], [$q0->bindings, $q1->bindings, $q2->bindings]);
        $this->assertSame(0.4, $q0->time);
        $this->assertSame(DB::connection(), $q0->connection);
        $this->assertInstanceOf(CacheHit::class, $hit);
        $this->assertSame(['array', 'settings', 'value'], [$hit->storeName, $hit->key, $hit->value]);
        $this->assertInstanceOf(CacheMissed::class, $miss);
        $this->assertSame(['array', 'menu'], [$miss->storeName, $miss->key]);
    }

    public function test_a_negative_number_of_queries_is_none(): void
    {
        $queries = 0;
        app('events')->listen(QueryExecuted::class, function () use (&$queries): void {
            $queries++;
        });

        $result = $this->clocked(array_fill(0, 4, 0))->run(1, -3, 1);

        $this->assertSame(0, $queries);
        $this->assertSame(-3, $result['queries'], 'reported as asked');
    }

    public function test_the_simulated_requests_are_measured_for_real_into_a_scratch_directory(): void
    {
        $seen = [];
        $before = glob($this->scratch.'/atrium-monitoring-bench-*') ?: [];
        app('events')->listen(CacheMissed::class, function () use (&$seen, $before): void {
            $directories = array_values(array_diff(glob($this->scratch.'/atrium-monitoring-bench-*') ?: [], $before));
            $seen = [
                'directories' => $directories,
                'sample_rate' => config('atrium-monitoring.server.sample_rate'),
                'enabled' => config('atrium-monitoring.server.enabled'),
                'recorder' => app(Recorder::class),
                'lines' => $directories === [] ? [] : (file($directories[0].'/buffer.ndjson', FILE_IGNORE_NEW_LINES) ?: []),
            ];
        });
        config(['atrium-monitoring.server.sample_rate' => 0.5, 'atrium-monitoring.server.enabled' => false]);
        $recorder = app(Recorder::class);

        $this->clocked(array_fill(0, 8, 0))->run(4, 3, 2);

        $this->assertCount(1, $seen['directories']);
        $this->assertMatchesRegularExpression('#/atrium-monitoring-bench-[0-9a-f]{8}$#', $seen['directories'][0]);
        $this->assertSame(1.0, $seen['sample_rate']);
        $this->assertTrue($seen['enabled']);
        $this->assertNotSame($recorder, $seen['recorder']);
        $this->assertInstanceOf(Recorder::class, $seen['recorder']);

        // Fifty warm-up requests and the first three of the four of the rounds had been written.
        $this->assertCount(53, $seen['lines']);
        foreach ($seen['lines'] as $line) {
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(['k', 'at', 'site', 'loc', 'route', 'path', 'm', 's', 'd', 'q', 'qm', 'ch', 'cm', 'mem'], array_keys($record));
            $this->assertSame(['r', 0, 'en', 'bench.page', '/bench/hello-world', 'GET', 200, 3, 1.2, 1, 1], [$record['k'], $record['site'], $record['loc'], $record['route'], $record['path'], $record['m'], $record['s'], $record['q'], $record['qm'], $record['ch'], $record['cm']]);
        }
    }

    public function test_a_clock_that_fails_still_leaves_the_app_as_it_was(): void
    {
        config(['atrium-monitoring.server.sample_rate' => 0.25, 'atrium-monitoring.server.enabled' => false]);
        $recorder = app(Recorder::class);
        $before = glob($this->scratch.'/atrium-monitoring-bench-*') ?: [];

        try {
            $this->clocked([0])->run(4, 1, 2);
            $this->fail('the clock should have run out');
        } catch (RuntimeException $e) {
            $this->assertSame('the clock was read more often than expected', $e->getMessage());
        }

        $this->assertSame(0.25, config('atrium-monitoring.server.sample_rate'));
        $this->assertFalse(config('atrium-monitoring.server.enabled'));
        $this->assertSame($recorder, app(Recorder::class));
        $this->assertSame($before, glob($this->scratch.'/atrium-monitoring-bench-*') ?: []);
    }

    public function test_the_real_clock_counts_nanoseconds_up(): void
    {
        $clock = new class(app('events')) extends Benchmark
        {
            public function read(): int
            {
                return $this->nanoseconds();
            }
        };

        $before = hrtime(true);
        $read = $clock->read();
        $after = hrtime(true);

        $this->assertGreaterThanOrEqual($before, $read);
        $this->assertLessThanOrEqual($after, $read);
    }

    private function fake(float $overhead): Benchmark
    {
        return new class($overhead) extends Benchmark
        {
            /** @var array<int,int> */
            public array $asked = [];

            public function __construct(private readonly float $overhead) {}

            public function run(int $requests = 2000, int $queries = 20, int $rounds = 5): array
            {
                $this->asked = [$requests, $queries, $rounds];

                return ['requests' => $requests, 'queries' => $queries, 'rounds' => $rounds, 'baseline_ms' => 1.0, 'measured_ms' => 1.0 + $this->overhead, 'overhead_ms' => $this->overhead];
            }
        };
    }
}
