<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Buffer;

use Atrium\Monitoring\Stats\Aggregator;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Moves the buffer into the database: the raw rows of the week and the
 * daily histograms, in batches. One flush at a time, under a lock, so the
 * daily rows are added to, never overwritten by a parallel flush.
 */
class Flusher
{
    private const CHUNK = 500;

    public function __construct(private readonly Buffer $buffer) {}

    /**
     * @return int the records written; 0 also when another flush holds the lock
     */
    public function flush(): int
    {
        $store = config('atrium-monitoring.buffer.lock_store');
        /** @var Repository $cache */
        $cache = Cache::store(is_string($store) && $store !== '' ? $store : null);
        /** @var LockProvider $locks */
        $locks = $cache->getStore();
        $lock = $locks->lock('atrium-monitoring:flush', 300);

        if (! $lock->get()) {
            return 0;
        }

        try {
            $written = 0;

            foreach ($this->buffer->claim() as $file) {
                $chunk = [];

                foreach ($this->buffer->read($file) as $record) {
                    $chunk[] = $record;

                    if (count($chunk) >= self::CHUNK) {
                        $written += $this->write($chunk);
                        $chunk = [];
                    }
                }

                $written += $this->write($chunk);
                @unlink($file);
            }

            return $written;
        } finally {
            $lock->release();
        }
    }

    /**
     * Writes records straight away, past the file: the uptime checks and
     * the tests use it.
     *
     * @param  array<int,array<string,mixed>>  $records
     */
    public function write(array $records): int
    {
        if ($records === []) {
            return 0;
        }

        $requests = $queries = $texts = $vitals = [];
        $aggregator = new Aggregator;

        foreach ($records as $record) {
            $at = Carbon::createFromTimestamp((int) ($record['at'] ?? time()), (string) config('app.timezone', 'UTC'));

            switch ($record['k'] ?? null) {
                case 'r':
                    $requests[] = [
                        'created_at' => $at,
                        'site_id' => (int) ($record['site'] ?? 0),
                        'locale' => mb_substr((string) ($record['loc'] ?? ''), 0, 12),
                        'route' => mb_substr((string) ($record['route'] ?? ''), 0, 191),
                        'path' => mb_substr((string) ($record['path'] ?? '/'), 0, 255),
                        'method' => mb_substr((string) ($record['m'] ?? 'GET'), 0, 8),
                        'status' => (int) ($record['s'] ?? 0),
                        'duration_ms' => (float) ($record['d'] ?? 0),
                        'db_queries' => (int) ($record['q'] ?? 0),
                        'db_ms' => (float) ($record['qm'] ?? 0),
                        'cache_hits' => (int) ($record['ch'] ?? 0),
                        'cache_misses' => (int) ($record['cm'] ?? 0),
                        'memory_kb' => (int) ($record['mem'] ?? 0),
                    ];
                    $aggregator->addRequest($record, $at);
                    break;

                case 'q':
                    $hash = (string) ($record['h'] ?? '');

                    if (strlen($hash) !== 40) {
                        break;
                    }

                    $queries[] = [
                        'created_at' => $at,
                        'site_id' => (int) ($record['site'] ?? 0),
                        'route' => mb_substr((string) ($record['route'] ?? ''), 0, 191),
                        'path' => mb_substr((string) ($record['path'] ?? '/'), 0, 255),
                        'sql_hash' => $hash,
                        'duration_ms' => (float) ($record['d'] ?? 0),
                    ];
                    $texts[$hash] = [
                        'hash' => $hash,
                        'sql' => $record['sql'] ?? '',
                        'connection' => mb_substr((string) ($record['c'] ?? ''), 0, 64),
                        'last_seen_at' => $at,
                    ];
                    $aggregator->addQuery($record, $at);
                    break;

                case 'v':
                    $vitals[] = [
                        'created_at' => $at,
                        'site_id' => (int) ($record['site'] ?? 0),
                        'locale' => mb_substr((string) ($record['loc'] ?? ''), 0, 12),
                        'path' => mb_substr((string) ($record['path'] ?? '/'), 0, 255),
                        'device' => mb_substr((string) ($record['dev'] ?? ''), 0, 8),
                        'connection' => mb_substr((string) ($record['con'] ?? ''), 0, 8),
                        'lcp' => self::number($record, 'lcp'),
                        'inp' => self::number($record, 'inp'),
                        'cls' => self::number($record, 'cls'),
                        'ttfb' => self::number($record, 'ttfb'),
                        'fcp' => self::number($record, 'fcp'),
                    ];
                    $aggregator->addVitals($record, $at);
                    break;
            }
        }

        DB::transaction(function () use ($requests, $queries, $texts, $vitals, $aggregator): void {
            foreach (array_chunk($requests, 200) as $rows) {
                DB::table('monitoring_requests')->insert($rows);
            }

            foreach (array_chunk($queries, 200) as $rows) {
                DB::table('monitoring_queries')->insert($rows);
            }

            if ($texts !== []) {
                DB::table('monitoring_query_texts')->upsert($texts, ['hash'], ['sql', 'connection', 'last_seen_at']);
            }

            foreach (array_chunk($vitals, 200) as $rows) {
                DB::table('monitoring_vitals')->insert($rows);
            }

            $aggregator->save();
        });

        return count($requests) + count($queries) + count($vitals);
    }

    /**
     * @param  array<string,mixed>  $record
     */
    private static function number(array $record, string $key): ?float
    {
        return isset($record[$key]) && is_numeric($record[$key]) ? (float) $record[$key] : null;
    }
}
