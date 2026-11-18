<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Collector;

use Atrium\Admin\Facades\Hook;
use Atrium\Admin\Support\Sites;
use Atrium\Monitoring\Buffer\Buffer;
use Atrium\Monitoring\Buffer\FlushBuffer;
use Atrium\Monitoring\Buffer\Flusher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * What one request cost: time to the first byte, queries, cache, memory.
 *
 * Counting happens in listeners that do nothing outside a measured request;
 * at the end the request becomes one line of the buffer (plus a line per
 * slow query). Nothing is written to the database here.
 */
class Recorder
{
    /** Slow queries kept per request: a loop of slow queries is one problem. */
    private const MAX_SLOW = 20;

    private bool $active = false;

    private float $start = 0.0;

    private ?float $ttfb = null;

    private int $queries = 0;

    private float $dbMs = 0.0;

    /** @var array<int,array{sql:string,connection:string,ms:float}> */
    private array $slow = [];

    private int $hits = 0;

    private int $misses = 0;

    private float $slowMs = 100.0;

    public function __construct(private readonly Buffer $buffer) {}

    /**
     * Starts measuring unless the request is left out or not in the sample.
     */
    public function start(Request $request): bool
    {
        $this->active = false;

        if (! config('atrium-monitoring.server.enabled', true)) {
            return false;
        }

        $rate = (float) config('atrium-monitoring.server.sample_rate', 1.0);

        if ($rate <= 0.0 || ($rate < 1.0 && $this->lottery() >= $rate)) {
            return false;
        }

        $except = (array) config('atrium-monitoring.server.except', []);

        if ($except !== [] && $request->is(...$except)) {
            return false;
        }

        $this->active = true;
        $this->slowMs = (float) config('atrium-monitoring.server.slow_query_ms', 100);
        // public/index.php defines LARAVEL_START; a worker or a test does not.
        $this->start = defined('LARAVEL_START') ? LARAVEL_START : $this->now();
        $this->ttfb = null;
        $this->queries = 0;
        $this->dbMs = 0.0;
        $this->slow = [];
        $this->hits = 0;
        $this->misses = 0;

        return true;
    }

    public function query(QueryExecuted $event): void
    {
        if (! $this->active) {
            return;
        }

        $this->queries++;
        $this->dbMs += $event->time;

        if ($event->time >= $this->slowMs && count($this->slow) < self::MAX_SLOW) {
            $this->slow[] = ['sql' => $event->sql, 'connection' => $event->connectionName, 'ms' => $event->time];
        }
    }

    public function hit(): void
    {
        if ($this->active) {
            $this->hits++;
        }
    }

    public function miss(): void
    {
        if ($this->active) {
            $this->misses++;
        }
    }

    /** The response is ready: this is the time to the first byte. */
    public function responded(): void
    {
        if ($this->active && $this->ttfb === null) {
            $this->ttfb = ($this->now() - $this->start) * 1000;
        }
    }

    public function finish(Request $request, Response $response): void
    {
        if (! $this->active) {
            return;
        }

        $this->active = false;

        try {
            if ($this->buffer->push($this->records($request, $response))) {
                $this->flushEarly();
            }
        } catch (Throwable) {
            // A measurement is never worth an error page.
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function records(Request $request, Response $response): array
    {
        $at = time();
        $site = $this->siteId();
        $route = self::routeName($request);
        $path = mb_substr('/'.ltrim($request->path(), '/'), 0, 255);
        [$hits, $misses] = $this->cacheStats();

        $records = [[
            'k' => 'r',
            'at' => $at,
            'site' => $site,
            'loc' => app()->getLocale(),
            'route' => $route,
            'path' => $path,
            'm' => $request->getMethod(),
            's' => $response->getStatusCode(),
            'd' => round((float) $this->ttfb, 3),
            'q' => $this->queries,
            'qm' => round($this->dbMs, 3),
            'ch' => $hits,
            'cm' => $misses,
            'mem' => intdiv(memory_get_peak_usage(), 1024),
        ]];

        foreach ($this->slow as $query) {
            $sql = SqlNormalizer::normalize($query['sql']);
            $records[] = [
                'k' => 'q',
                'at' => $at,
                'site' => $site,
                'route' => $route,
                'path' => $path,
                'h' => SqlNormalizer::hash($sql, $query['connection']),
                'sql' => $sql,
                'c' => $query['connection'],
                'd' => round($query['ms'], 3),
            ];
        }

        return $records;
    }

    /**
     * The route's name, else its URI pattern, so /news/a and /news/b are one
     * line of the report; a request no route matched groups by its status.
     */
    public static function routeName(Request $request): string
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return '(none)';
        }

        return mb_substr($route->getName() ?? '/'.ltrim($route->uri(), '/'), 0, 191);
    }

    /**
     * The cache module reports its own hits and misses through the filter;
     * without it, Laravel's cache events are counted.
     *
     * @return array{0:int,1:int}
     */
    private function cacheStats(): array
    {
        if (Hook::has('atrium.cache.stats')) {
            $stats = Hook::apply('atrium.cache.stats', ['hits' => 0, 'misses' => 0]);

            if (is_array($stats)) {
                return [max(0, (int) ($stats['hits'] ?? null)), max(0, (int) ($stats['misses'] ?? null))];
            }
        }

        return [$this->hits, $this->misses];
    }

    /** The clock; a test sets it to know the time to the first byte to the last digit. */
    protected function now(): float
    {
        return microtime(true);
    }

    /** The dice of the sample: 0 to 1, a request is measured when it falls below the rate. */
    protected function lottery(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    private function siteId(): int
    {
        $sites = app(Sites::class);

        return $sites->isResolved() ? (int) $sites->current()->getKey() : 0;
    }

    private function flushEarly(): void
    {
        $queue = config('atrium-monitoring.buffer.queue');

        if (is_string($queue) && $queue !== '') {
            FlushBuffer::dispatch()->onConnection($queue);

            return;
        }

        app(Flusher::class)->flush();
    }
}
