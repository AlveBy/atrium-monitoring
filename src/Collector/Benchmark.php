<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Collector;

use Atrium\Monitoring\Buffer\Buffer;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * What the middleware adds to a request, measured the honest way: the same
 * simulated request (N queries, a few cache reads, through the real event
 * dispatcher and listeners) is run without and with it, the buffer writing
 * to a real file, every request measured (sample rate 1), and the two
 * timings compared, round by round. The flush is not included: it runs once a minute in the
 * scheduler, not in a request.
 */
class Benchmark
{
    /** @param  string|null  $scratch  where the throwaway buffer goes: the system's temporary directory unless given */
    public function __construct(private readonly Dispatcher $events, private readonly ?string $scratch = null) {}

    /**
     * The two loops take turns in rounds, so a busy machine slows both
     * alike; the result is the median round.
     *
     * @return array{requests:int,queries:int,rounds:int,baseline_ms:float,measured_ms:float,overhead_ms:float}
     */
    public function run(int $requests, int $queries, int $rounds): array
    {
        $rounds = max(1, $rounds);
        $perRound = max(1, intdiv($requests, $rounds));
        $directory = ($this->scratch ?? sys_get_temp_dir()).'/atrium-monitoring-bench-'.bin2hex(random_bytes(4));
        $previous = app(Recorder::class);
        $recorder = new Recorder(new Buffer($directory, PHP_INT_MAX));
        app()->instance(Recorder::class, $recorder);

        $sample = config('atrium-monitoring.server.sample_rate');
        $enabled = config('atrium-monitoring.server.enabled');
        config(['atrium-monitoring.server.sample_rate' => 1.0, 'atrium-monitoring.server.enabled' => true]);

        $baseline = $measured = [];

        try {
            $middleware = new CollectMetrics($recorder);
            $request = Request::create('/bench/hello-world', 'GET');
            $route = (new Route('GET', 'bench/{slug}', fn () => null))->name('bench.page');
            $request->setRouteResolver(fn () => $route);
            $response = new Response('<html><body>ok</body></html>');
            /** @var Connection $connection */
            $connection = DB::connection();
            $app = fn (): Response => $this->simulate($response, $connection, $queries);

            // Warm up both paths: autoloading and the first file open are not the per-request cost.
            for ($i = 0; $i < 50; $i++) {
                $app();
                $middleware->terminate($request, $middleware->handle($request, $app));
            }

            for ($round = 0; $round < $rounds; $round++) {
                $start = $this->nanoseconds();
                for ($i = 0; $i < $perRound; $i++) {
                    $app();
                }
                $baseline[] = ($this->nanoseconds() - $start) / 1e6 / $perRound;

                $start = $this->nanoseconds();
                for ($i = 0; $i < $perRound; $i++) {
                    $middleware->terminate($request, $middleware->handle($request, $app));
                }
                $measured[] = ($this->nanoseconds() - $start) / 1e6 / $perRound;
            }
        } finally {
            config(['atrium-monitoring.server.sample_rate' => $sample, 'atrium-monitoring.server.enabled' => $enabled]);
            app()->instance(Recorder::class, $previous);
            File::deleteDirectory($directory);
        }

        $overhead = array_map(static fn (float $with, float $without): float => $with - $without, $measured, $baseline);

        return [
            'requests' => $perRound * $rounds,
            'queries' => $queries,
            'rounds' => $rounds,
            'baseline_ms' => self::median($baseline),
            'measured_ms' => self::median($measured),
            'overhead_ms' => max(0.0, self::median($overhead)),
        ];
    }

    /** @param array<int,float> $values */
    public static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /** The clock of the measurement: the one thing a test replaces to know the exact numbers. */
    protected function nanoseconds(): int
    {
        return hrtime(true);
    }

    private function simulate(Response $response, Connection $connection, int $queries): Response
    {
        foreach (array_keys(array_fill(0, max(0, $queries), null)) as $q) {
            $this->events->dispatch(new QueryExecuted('select * from "pages" where "id" = ? limit 1', [$q], 0.4, $connection));
        }

        $this->events->dispatch(new CacheHit('array', 'settings', 'value'));
        $this->events->dispatch(new CacheMissed('array', 'menu'));

        return $response;
    }
}
