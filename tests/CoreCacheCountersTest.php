<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Admin\Cache\RecordCache;
use Atrium\Monitoring\Buffer\Buffer;
use Atrium\Monitoring\Collector\CollectMetrics;
use Atrium\Monitoring\Collector\Recorder;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * The module next to the core's cache: its own hits and misses, read through
 * the `atrium.cache.stats` filter, are what a request line carries.
 */
final class CoreCacheCountersTest extends TestCase
{
    protected bool $coreCacheCounters = true;

    private function line(Recorder $recorder): array
    {
        return array_intersect_key($recorder->records(Request::create('/hello'), new Response)[0], ['ch' => 0, 'cm' => 0]);
    }

    public function test_the_request_line_carries_the_core_caches_hits_and_misses(): void
    {
        $recorder = new Recorder(new Buffer($this->bufferPath, PHP_INT_MAX));
        $recorder->start(Request::create('/hello'));
        $cache = app(RecordCache::class);

        $this->assertSame(['ch' => 0, 'cm' => 0], $this->line($recorder));

        $cache->remember([], 'demo', fn (): int => 1);
        $cache->remember([], 'demo', fn (): int => 1);
        $cache->remember([], 'demo', fn (): int => 1);

        $this->assertSame(['ch' => 2, 'cm' => 1], $this->line($recorder));
    }

    public function test_laravels_own_events_are_not_added_on_top(): void
    {
        $recorder = new Recorder(new Buffer($this->bufferPath, PHP_INT_MAX));
        $recorder->start(Request::create('/hello'));
        $recorder->hit();
        $recorder->hit();
        $recorder->miss();

        $this->assertSame(['ch' => 0, 'cm' => 0], $this->line($recorder));
    }

    /** @return array<string,array{0:bool}> */
    public static function whetherTheCoreClosedTheRequestFirst(): array
    {
        return ['the line is written before the core resets' => [false], 'the core has already reset when the line is written' => [true]];
    }

    #[DataProvider('whetherTheCoreClosedTheRequestFirst')]
    public function test_a_whole_request_through_the_middleware_writes_the_cores_counters(bool $coreResetsFirst): void
    {
        $middleware = new CollectMetrics(app(Recorder::class));
        $request = Request::create('/hello');

        $response = $middleware->handle($request, function (): Response {
            $cache = app(RecordCache::class);
            $cache->remember([], 'demo', fn (): int => 1);
            $cache->remember([], 'demo', fn (): int => 1);
            $cache->remember([], 'demo', fn (): int => 1);

            return new Response('ok');
        });

        if ($coreResetsFirst) {
            app(RecordCache::class)->persistStats();
            app(RecordCache::class)->reset();
        }

        $middleware->terminate($request, $response);

        $lines = file($this->bufferPath.'/buffer.ndjson', FILE_IGNORE_NEW_LINES) ?: [];
        $record = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['ch' => 2, 'cm' => 1], ['ch' => $record['ch'], 'cm' => $record['cm']]);
    }
}
