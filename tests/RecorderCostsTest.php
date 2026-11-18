<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Admin\Facades\Hook;
use Atrium\Admin\Models\Site;
use Atrium\Admin\Support\Sites;
use Atrium\Monitoring\Buffer\Buffer;
use Atrium\Monitoring\Buffer\FlushBuffer;
use Atrium\Monitoring\Buffer\Flusher;
use Atrium\Monitoring\Collector\Recorder;
use Atrium\Monitoring\Collector\SqlNormalizer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The recorder on its own: the clock, the dice and the buffer are replaced,
 * so every number of a request's line is known to the last digit.
 */
final class RecorderCostsTest extends TestCase
{
    /** The origin of the time to the first byte: public/index.php's LARAVEL_START when a runner defines it. */
    private function origin(): float
    {
        return defined('LARAVEL_START') ? (float) LARAVEL_START : 1000.0;
    }

    private function buffer(): Buffer
    {
        return new class('/nowhere', 1) extends Buffer
        {
            /** @var array<int,array<int,array<string,mixed>>> */
            public array $pushed = [];

            public bool $full = false;

            public function push(array $records): bool
            {
                $this->pushed[] = $records;

                return $this->full;
            }
        };
    }

    private function recorder(Buffer $buffer): Recorder
    {
        return new class($buffer) extends Recorder
        {
            public float $clock = 0.0;

            public float $dice = 0.0;

            protected function now(): float
            {
                return $this->clock;
            }

            protected function lottery(): float
            {
                return $this->dice;
            }
        };
    }

    private function event(float|int $ms, string $sql = 'select 1'): QueryExecuted
    {
        return new QueryExecuted($sql, [], $ms, DB::connection());
    }

    private function withoutServerSetting(string $key): void
    {
        $server = config('atrium-monitoring.server');
        unset($server[$key]);
        config(['atrium-monitoring.server' => $server]);
    }

    private function measured(Recorder $recorder, string $path = '/hello'): bool
    {
        return $recorder->start(Request::create($path));
    }

    public function test_a_switched_off_server_side_measures_nothing(): void
    {
        $recorder = $this->recorder($this->buffer());
        config(['atrium-monitoring.server.enabled' => false]);

        $this->assertFalse($this->measured($recorder));

        config(['atrium-monitoring.server.enabled' => true]);
        $this->assertTrue($this->measured($recorder));
    }

    public function test_the_sample_rate_is_compared_with_the_dice(): void
    {
        $recorder = $this->recorder($this->buffer());
        $cases = [
            // rate, dice, measured
            [0.5, 0.49, true],
            [0.5, 0.5, false],
            [0.5, 0.9, false],
            ['0.5', 0.4, true],
            ['0.5', 0.6, false],
            [1.0, 1.0, true],
            [2.5, 1.0, true],
            [0.0, -1.0, false],
            [-0.5, -1.0, false],
            ['not a number', 0.0, false],
        ];

        foreach ($cases as [$rate, $dice, $expected]) {
            config(['atrium-monitoring.server.sample_rate' => $rate]);
            $recorder->dice = $dice;

            $this->assertSame($expected, $this->measured($recorder), 'rate '.var_export($rate, true).', dice '.$dice);
        }
    }

    public function test_every_request_is_measured_when_no_rate_is_set(): void
    {
        $recorder = $this->recorder($this->buffer());
        $recorder->dice = 1.0;
        $this->withoutServerSetting('sample_rate');

        $this->assertTrue($this->measured($recorder));
    }

    public function test_the_real_dice_is_a_number_from_zero_to_one(): void
    {
        $dice = new class($this->buffer()) extends Recorder
        {
            public function roll(): float
            {
                return $this->lottery();
            }
        };

        mt_srand(11);
        $expected = mt_rand() / mt_getrandmax();
        mt_srand(11);

        $this->assertSame($expected, $dice->roll());
        $this->assertGreaterThanOrEqual(0.0, $expected);
        $this->assertLessThanOrEqual(1.0, $expected);
    }

    public function test_the_real_clock_is_the_wall_clock(): void
    {
        $clock = new class($this->buffer()) extends Recorder
        {
            public function read(): float
            {
                return $this->now();
            }
        };

        $before = microtime(true);
        $read = $clock->read();
        $after = microtime(true);

        $this->assertGreaterThanOrEqual($before, $read);
        $this->assertLessThanOrEqual($after, $read);
    }

    public function test_left_out_paths_are_not_measured(): void
    {
        $recorder = $this->recorder($this->buffer());

        config(['atrium-monitoring.server.except' => 'up']);
        $this->assertFalse($this->measured($recorder, '/up'), 'a single pattern written as a string');
        $this->assertTrue($this->measured($recorder, '/upper'));

        config(['atrium-monitoring.server.except' => ['health', 'status/*']]);
        $this->assertFalse($this->measured($recorder, '/health'));
        $this->assertFalse($this->measured($recorder, '/status/db'));
        $this->assertTrue($this->measured($recorder, '/status'));

        config(['atrium-monitoring.server.except' => []]);
        $this->assertTrue($this->measured($recorder, '/health'));

        config(['atrium-monitoring.server.except' => null]);
        $this->assertTrue($this->measured($recorder, '/health'));
    }

    public function test_a_new_request_starts_from_zero(): void
    {
        $recorder = $this->recorder($this->buffer());
        $this->measured($recorder);
        $recorder->query($this->event(150.0));
        $recorder->hit();
        $recorder->miss();
        $this->assertCount(2, $recorder->records(Request::create('/hello'), new Response));

        $this->measured($recorder);
        $records = $recorder->records(Request::create('/hello'), new Response);

        $this->assertCount(1, $records, 'the slow query of the last request is gone');
        $this->assertSame([0, 0.0, 0, 0], [$records[0]['q'], $records[0]['qm'], $records[0]['ch'], $records[0]['cm']]);
        $this->assertSame(0.0, $records[0]['d']);
    }

    public function test_only_a_measured_request_counts_queries_hits_and_misses(): void
    {
        $recorder = $this->recorder($this->buffer());
        $recorder->query($this->event(5.0));
        $recorder->hit();
        $recorder->miss();
        $before = $recorder->records(Request::create('/hello'), new Response)[0];
        $this->assertSame([0, 0.0, 0, 0], [$before['q'], $before['qm'], $before['ch'], $before['cm']], 'nothing is measured yet');

        $this->measured($recorder);
        $recorder->query($this->event(0.125));
        $recorder->query($this->event(0.25));
        $recorder->hit();
        $recorder->hit();
        $recorder->miss();
        $during = $recorder->records(Request::create('/hello'), new Response)[0];
        $this->assertSame([2, 0.375, 2, 1], [$during['q'], $during['qm'], $during['ch'], $during['cm']]);

        $recorder->finish(Request::create('/hello'), new Response);
        $recorder->query($this->event(7.0));
        $recorder->hit();
        $recorder->miss();
        $after = $recorder->records(Request::create('/hello'), new Response)[0];
        $this->assertSame([2, 0.375, 2, 1], [$after['q'], $after['qm'], $after['ch'], $after['cm']], 'a finished request counts no more');
    }

    public function test_the_time_to_the_first_byte_is_the_time_to_the_response(): void
    {
        $recorder = $this->recorder($this->buffer());
        $recorder->clock = $this->origin();
        $this->measured($recorder);

        $recorder->clock = $this->origin() + 0.0123456;
        $recorder->responded();
        $recorder->clock = $this->origin() + 0.5;
        $recorder->responded();

        $this->assertSame(12.346, $recorder->records(Request::create('/hello'), new Response)[0]['d'], 'the first answer counts, rounded to a microsecond');
    }

    public function test_a_recorder_that_measures_nothing_has_no_time(): void
    {
        $recorder = $this->recorder($this->buffer());
        $recorder->clock = $this->origin() + 5.0;
        $recorder->responded();

        $this->assertSame(0.0, $recorder->records(Request::create('/hello'), new Response)[0]['d']);
    }

    public function test_a_request_that_was_never_announced_as_answered_has_no_time(): void
    {
        $buffer = $this->buffer();
        $recorder = $this->recorder($buffer);
        $recorder->clock = $this->origin();
        $this->measured($recorder);

        $recorder->clock = $this->origin() + 0.25;
        $recorder->finish(Request::create('/hello'), new Response);

        $this->assertCount(1, $buffer->pushed);
        $this->assertSame(0.0, $buffer->pushed[0][0]['d'], 'the middleware announces the response; the end of the request invents no time');
    }

    public function test_a_finished_request_is_written_once(): void
    {
        $buffer = $this->buffer();
        $recorder = $this->recorder($buffer);
        $this->measured($recorder);

        $recorder->finish(Request::create('/hello'), new Response);
        $recorder->finish(Request::create('/hello'), new Response);

        $this->assertCount(1, $buffer->pushed);
    }

    public function test_a_request_that_was_not_measured_is_not_written(): void
    {
        $buffer = $this->buffer();
        $recorder = $this->recorder($buffer);

        $recorder->finish(Request::create('/hello'), new Response);

        $this->assertSame([], $buffer->pushed);
    }

    public function test_the_line_of_a_request(): void
    {
        $recorder = $this->recorder($this->buffer());
        $recorder->clock = $this->origin();
        $this->measured($recorder);
        $recorder->query($this->event(0.1234));
        $recorder->query($this->event(0.2222));
        $recorder->hit();
        $recorder->miss();
        $recorder->clock = $this->origin() + 0.1;
        $recorder->responded();

        $before = time();
        $records = $recorder->records(Request::create('/hello/world?x=1', 'POST'), new Response('', 201));
        $after = time();

        $this->assertCount(1, $records);
        $line = $records[0];
        $this->assertGreaterThanOrEqual($before, $line['at']);
        $this->assertLessThanOrEqual($after, $line['at']);
        $this->assertGreaterThanOrEqual(intdiv(memory_get_peak_usage(), 1024) - 1, $line['mem']);
        $this->assertLessThanOrEqual(intdiv(memory_get_peak_usage(), 1024), $line['mem']);
        unset($line['at'], $line['mem']);

        $this->assertSame([
            'k' => 'r',
            'site' => 0,
            'loc' => 'en',
            'route' => '(none)',
            'path' => '/hello/world',
            'm' => 'POST',
            's' => 201,
            'd' => 100.0,
            'q' => 2,
            'qm' => 0.346,
            'ch' => 1,
            'cm' => 1,
        ], $line);
    }

    public function test_the_memory_is_in_kilobytes(): void
    {
        $recorder = $this->recorder($this->buffer());
        $before = memory_get_peak_usage();
        $line = $recorder->records(Request::create('/hello'), new Response)[0];
        $after = memory_get_peak_usage();

        $this->assertGreaterThanOrEqual(intdiv($before, 1024), $line['mem']);
        $this->assertLessThanOrEqual(intdiv($after, 1024), $line['mem']);
    }

    public function test_paths_are_cut_to_255_characters_and_the_front_page_is_a_slash(): void
    {
        $recorder = $this->recorder($this->buffer());

        $this->assertSame('/', $recorder->records(Request::create('/'), new Response)[0]['path']);
        $this->assertSame('/'.str_repeat('a', 254), $recorder->records(Request::create('/'.str_repeat('a', 400)), new Response)[0]['path']);

        $path = $recorder->records(Request::create('/'.str_repeat('é', 400)), new Response)[0]['path'];
        $this->assertSame(255, mb_strlen($path), 'characters, not bytes');
        $this->assertTrue(mb_check_encoding($path, 'UTF-8'));
    }

    public function test_slow_queries_get_their_own_lines_without_values(): void
    {
        $recorder = $this->recorder($this->buffer());
        $this->measured($recorder);
        $recorder->query($this->event(100.12345, "select * from users where email = 'anna@example.com' and id in (1, 2, 3)"));
        $recorder->query($this->event(150, 'select 2'));

        $request = Request::create('/slow');
        $request->setRouteResolver(fn () => (new Route(['GET'], 'slow', fn () => null))->name('slow.page'));
        $records = $recorder->records($request, new Response);

        $this->assertCount(3, $records);
        $first = $records[1];
        $second = $records[2];
        $this->assertSame($records[0]['at'], $first['at']);
        $sql = 'select * from users where email = ? and id in (?, ...)';
        $this->assertSame([
            'k' => 'q',
            'at' => $records[0]['at'],
            'site' => 0,
            'route' => 'slow.page',
            'path' => '/slow',
            'h' => SqlNormalizer::hash($sql, DB::getDefaultConnection()),
            'sql' => $sql,
            'c' => DB::getDefaultConnection(),
            'd' => 100.123,
        ], $first);
        $this->assertSame(['select ?', 150.0], [$second['sql'], $second['d']], 'a query reported in whole milliseconds');
    }

    public function test_a_query_is_slow_from_a_hundred_milliseconds_unless_told_otherwise(): void
    {
        $recorder = $this->recorder($this->buffer());
        $this->withoutServerSetting('slow_query_ms');
        $this->measured($recorder);
        $recorder->query($this->event(99.5));
        $recorder->query($this->event(100.0, 'select 100'));

        $this->assertSame(['select ?'], array_column(array_slice($recorder->records(Request::create('/hello'), new Response), 1), 'sql'));
        $this->assertSame([100.0], array_column(array_slice($recorder->records(Request::create('/hello'), new Response), 1), 'd'));
    }

    public function test_the_slow_limit_may_come_from_the_environment_as_text(): void
    {
        $recorder = $this->recorder($this->buffer());
        config(['atrium-monitoring.server.slow_query_ms' => '50']);
        $this->measured($recorder);
        $recorder->query($this->event(49.9));
        $recorder->query($this->event(50.0));

        $this->assertCount(2, $recorder->records(Request::create('/hello'), new Response));
    }

    public function test_the_cache_module_counters_replace_the_events_and_are_never_negative(): void
    {
        $recorder = $this->recorder($this->buffer());
        $this->measured($recorder);
        $recorder->hit();
        $recorder->miss();
        $offered = [];
        $answer = [];
        Hook::filter('atrium.cache.stats', function (array $stats) use (&$offered, &$answer): array {
            $offered = $stats;

            return $answer;
        });
        $line = fn (): array => array_intersect_key($recorder->records(Request::create('/hello'), new Response)[0], ['ch' => 0, 'cm' => 0]);

        $answer = ['hits' => 5, 'misses' => 4];
        $this->assertSame(['ch' => 5, 'cm' => 4], $line());
        $this->assertSame(['hits' => 0, 'misses' => 0], $offered, 'the filter starts from zeros');

        $answer = ['hits' => -2, 'misses' => -9];
        $this->assertSame(['ch' => 0, 'cm' => 0], $line());

        $answer = ['hits' => 3];
        $this->assertSame(['ch' => 3, 'cm' => 0], $line(), 'a missing counter is zero');

        $answer = ['misses' => 6];
        $this->assertSame(['ch' => 0, 'cm' => 6], $line());

        $answer = [];
        $this->assertSame(['ch' => 0, 'cm' => 0], $line());

        $answer = ['hits' => '7', 'misses' => '8'];
        $this->assertSame(['ch' => 7, 'cm' => 8], $line(), 'counters written as text');
    }

    public function test_the_site_is_zero_until_one_is_chosen_and_then_its_id(): void
    {
        $recorder = $this->recorder($this->buffer());
        $this->assertSame(0, $recorder->records(Request::create('/hello'), new Response)[0]['site']);

        $site = Site::query()->create(['code' => 'shop', 'name' => 'Demo shop', 'domains' => ['shop.example.test'], 'active' => true]);
        app(Sites::class)->flush();
        app(Sites::class)->setCurrent($site);

        $this->assertSame((int) $site->getKey(), $recorder->records(Request::create('/hello'), new Response)[0]['site']);
    }

    public function test_an_installation_without_stored_sites_is_site_zero(): void
    {
        Site::query()->delete();
        app(Sites::class)->flush();
        app(Sites::class)->setCurrent(new Site);

        $this->assertSame(0, $this->recorder($this->buffer())->records(Request::create('/hello'), new Response)[0]['site']);
    }

    public function test_route_names_patterns_and_the_lack_of_a_route(): void
    {
        $named = Request::create('/a');
        $named->setRouteResolver(fn () => (new Route(['GET'], 'a', fn () => null))->name('a.page'));
        $this->assertSame('a.page', Recorder::routeName($named));

        $unnamed = Request::create('/a');
        $unnamed->setRouteResolver(fn () => new Route(['GET'], '/news/{slug}', fn () => null));
        $this->assertSame('/news/{slug}', Recorder::routeName($unnamed), 'one leading slash');

        $bare = Request::create('/a');
        $bare->setRouteResolver(fn () => new Route(['GET'], 'news/{slug}', fn () => null));
        $this->assertSame('/news/{slug}', Recorder::routeName($bare));

        $front = Request::create('/');
        $front->setRouteResolver(fn () => new Route(['GET'], '/', fn () => null));
        $this->assertSame('/', Recorder::routeName($front), 'the front page is one slash, not two');
    }

    public function test_route_names_are_cut_to_191_characters(): void
    {
        $long = Request::create('/a');
        $long->setRouteResolver(fn () => (new Route(['GET'], 'a', fn () => null))->name(str_repeat('é', 300)));

        $name = Recorder::routeName($long);
        $this->assertSame(str_repeat('é', 191), $name, 'characters, not bytes');

        $pattern = Request::create('/a');
        $pattern->setRouteResolver(fn () => new Route(['GET'], str_repeat('b', 300), fn () => null));
        $this->assertSame('/'.str_repeat('b', 190), Recorder::routeName($pattern));
    }

    public function test_a_full_buffer_is_flushed_now_unless_a_queue_is_named(): void
    {
        Bus::fake();
        $flusher = new class(app(Buffer::class)) extends Flusher
        {
            public int $flushes = 0;

            public function flush(): int
            {
                $this->flushes++;

                return 0;
            }
        };
        $this->app->instance(Flusher::class, $flusher);
        $buffer = $this->buffer();
        $buffer->full = true;
        $recorder = $this->recorder($buffer);

        foreach ([null, '', 7] as $queue) {
            config(['atrium-monitoring.buffer.queue' => $queue]);
            $this->measured($recorder);
            $recorder->finish(Request::create('/hello'), new Response);
        }

        $this->assertSame(3, $flusher->flushes);
        Bus::assertNotDispatched(FlushBuffer::class);

        config(['atrium-monitoring.buffer.queue' => 'redis']);
        $this->measured($recorder);
        $recorder->finish(Request::create('/hello'), new Response);

        $this->assertSame(3, $flusher->flushes, 'left to the queue');
        Bus::assertDispatchedTimes(FlushBuffer::class, 1);
        Bus::assertDispatched(FlushBuffer::class, fn (FlushBuffer $job): bool => $job->connection === 'redis');
    }

    public function test_a_buffer_with_room_is_left_alone(): void
    {
        Bus::fake();
        $flusher = new class(app(Buffer::class)) extends Flusher
        {
            public int $flushes = 0;

            public function flush(): int
            {
                $this->flushes++;

                return 0;
            }
        };
        $this->app->instance(Flusher::class, $flusher);
        $recorder = $this->recorder($this->buffer());
        $this->measured($recorder);

        $recorder->finish(Request::create('/hello'), new Response);

        $this->assertSame(0, $flusher->flushes);
        Bus::assertNothingDispatched();
    }
}
