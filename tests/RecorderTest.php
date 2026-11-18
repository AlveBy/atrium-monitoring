<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Admin\Facades\Hook;
use Atrium\Monitoring\Buffer\Buffer;
use Atrium\Monitoring\Buffer\FlushBuffer;
use Atrium\Monitoring\Collector\Recorder;
use Atrium\Monitoring\Collector\SqlNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;

final class RecorderTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::middleware('web')->group(function (): void {
            Route::get('/hello/{slug}', function (string $slug) {
                DB::select('select ? as slug', [$slug]);
                DB::select("select 1 where 'secret@example.com' = 'secret@example.com' and 42 in (1, 2, 3)");
                Cache::put('greeting', 'hi');
                Cache::get('greeting');
                Cache::get('missing');

                return 'Hello '.$slug;
            })->name('hello');
            Route::get('/plain/{id}', fn () => 'plain');
            Route::get('/broken', fn () => abort(500));
            Route::get('/up', fn () => 'up');
        });
    }

    /** @return array<int,array<string,mixed>> */
    private function buffered(): array
    {
        $file = app(Buffer::class)->path();

        return is_file($file) ? array_map(static fn (string $line): array => json_decode($line, true), file($file, FILE_IGNORE_NEW_LINES)) : [];
    }

    public function test_a_request_becomes_one_line_with_its_costs(): void
    {
        $this->get('/hello/world')->assertOk()->assertSee('Hello world');

        $records = $this->buffered();
        $this->assertCount(1, $records, 'no query was slow');
        $line = $records[0];
        $this->assertSame('r', $line['k']);
        $this->assertSame('hello', $line['route']);
        $this->assertSame('/hello/world', $line['path']);
        $this->assertSame('GET', $line['m']);
        $this->assertSame(200, $line['s']);
        $this->assertSame('en', $line['loc']);
        $this->assertSame(2, $line['q']);
        $this->assertSame(1, $line['ch']);
        $this->assertSame(1, $line['cm']);
        $this->assertGreaterThan(0, $line['d']);
        $this->assertGreaterThan(1024, $line['mem']);
        $this->assertArrayNotHasKey('ip', $line);
    }

    public function test_routes_without_a_name_group_by_their_pattern_and_unmatched_ones_together(): void
    {
        $this->get('/plain/7');
        $this->get('/plain/8');
        $this->get('/no-such-page')->assertNotFound();
        $this->get('/broken')->assertStatus(500);

        $this->assertSame(['/plain/{id}', '/plain/{id}', '/broken'], array_column($this->buffered(), 'route'));
        $this->assertSame([200, 200, 500], array_column($this->buffered(), 's'));
    }

    public function test_slow_queries_are_kept_without_their_values(): void
    {
        config(['atrium-monitoring.server.slow_query_ms' => 0]);

        $this->get('/hello/world');

        $queries = array_values(array_filter($this->buffered(), static fn (array $r): bool => $r['k'] === 'q'));
        $this->assertCount(2, $queries);
        $this->assertSame('select ? as slug', $queries[0]['sql']);
        $this->assertSame('select ? where ? = ? and ? in (?, ...)', $queries[1]['sql']);
        $this->assertStringNotContainsString('secret', json_encode($this->buffered()));
        $this->assertSame(SqlNormalizer::hash($queries[1]['sql'], DB::getDefaultConnection()), $queries[1]['h']);
        $this->assertSame('hello', $queries[0]['route']);
    }

    public function test_at_most_twenty_slow_queries_per_request(): void
    {
        config(['atrium-monitoring.server.slow_query_ms' => 0]);
        Route::middleware('web')->get('/loop', function () {
            for ($i = 0; $i < 30; $i++) {
                DB::select('select '.$i);
            }

            return 'ok';
        });

        $this->get('/loop');

        $this->assertCount(20, array_filter($this->buffered(), static fn (array $r): bool => $r['k'] === 'q'));
        $this->assertSame(30, $this->buffered()[0]['q'], 'every query is still counted');
    }

    public function test_requests_left_out_by_sampling_exceptions_or_switch(): void
    {
        $this->get('/up')->assertOk();
        $this->assertSame([], $this->buffered(), 'the health check is excepted');

        config(['atrium-monitoring.server.sample_rate' => 0.0]);
        $this->get('/hello/a');
        $this->assertSame([], $this->buffered());

        config(['atrium-monitoring.server.sample_rate' => 1.0, 'atrium-monitoring.server.enabled' => false]);
        $this->get('/hello/b');
        $this->assertSame([], $this->buffered());

        // Half the requests, give or take.
        config(['atrium-monitoring.server.enabled' => true, 'atrium-monitoring.server.sample_rate' => 0.5]);
        mt_srand(3);

        for ($i = 0; $i < 40; $i++) {
            $this->get('/plain/'.$i);
        }

        $this->assertGreaterThan(8, count($this->buffered()));
        $this->assertLessThan(32, count($this->buffered()));
    }

    public function test_the_cache_module_reports_its_own_counters(): void
    {
        Hook::filter('atrium.cache.stats', fn (array $stats): array => ['hits' => 17, 'misses' => -3]);

        $this->get('/hello/world');

        $this->assertSame([17, 0], [$this->buffered()[0]['ch'], $this->buffered()[0]['cm']]);
    }

    public function test_a_cache_hook_that_returns_garbage_falls_back_to_the_events(): void
    {
        Hook::filter('atrium.cache.stats', fn (): string => 'nonsense');

        $this->get('/hello/world');

        $this->assertSame([1, 1], [$this->buffered()[0]['ch'], $this->buffered()[0]['cm']]);
    }

    public function test_a_full_buffer_is_flushed_at_once_or_handed_to_the_queue(): void
    {
        config(['atrium-monitoring.buffer.max_bytes' => 1024]);
        $this->app->forgetInstance(Buffer::class);
        $this->app->forgetScopedInstances();
        app(Buffer::class)->push(array_fill(0, 20, ['k' => 'r', 'at' => time(), 'route' => 'old', 'd' => 1.0]));

        $this->get('/plain/1');
        $this->assertSame(21, DB::table('monitoring_requests')->count());
        $this->assertSame(0, app(Buffer::class)->size());

        Bus::fake();
        config(['atrium-monitoring.buffer.queue' => 'redis']);
        app(Buffer::class)->push(array_fill(0, 20, ['k' => 'r', 'at' => time(), 'route' => 'old', 'd' => 1.0]));
        $this->get('/plain/2');

        Bus::assertDispatched(FlushBuffer::class, fn (FlushBuffer $job): bool => $job->connection === 'redis');
        $this->assertSame(21, DB::table('monitoring_requests')->count(), 'left to the queue');
    }

    public function test_a_failing_buffer_never_breaks_the_page(): void
    {
        $buffer = new class('/nowhere', 1) extends Buffer
        {
            public int $tries = 0;

            public function push(array $records): bool
            {
                $this->tries++;

                throw new RuntimeException('disk full');
            }
        };
        $this->app->instance(Buffer::class, $buffer);
        $this->app->forgetScopedInstances();

        $this->get('/hello/world')->assertOk()->assertSee('Hello world');
        $this->assertSame(1, $buffer->tries);
    }

    public function test_a_request_without_a_route_is_named_none(): void
    {
        $this->assertSame('(none)', Recorder::routeName(Request::create('/anything')));

        $request = Request::create('/anything');
        $request->setRouteResolver(fn () => 'closure-route');
        $this->assertSame('(none)', Recorder::routeName($request));
    }
}
