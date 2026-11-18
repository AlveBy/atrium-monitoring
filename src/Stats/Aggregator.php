<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Stats;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Adds measurements to the daily histograms of monitoring_daily.
 *
 * A batch is first summed up in memory, then each touched row is read,
 * merged and written back in one upsert. The caller holds the flush lock,
 * so no two batches merge into the same row at once.
 *
 * Which totals each measurement goes to:
 *
 *   requests  site · site+language · site+route
 *   vitals    site · site+language · site+page · site+device
 *   queries   site · site+query
 *   uptime    site+check
 */
final class Aggregator
{
    public const TOTAL = '*';

    /** Request measures: record key => metric. */
    public const REQUEST_METRICS = [
        'd' => 'ttfb',
        'q' => 'db_count',
        'qm' => 'db_ms',
        'ch' => 'cache_hit',
        'cm' => 'cache_miss',
    ];

    public const VITALS = ['lcp', 'inp', 'cls', 'ttfb', 'fcp'];

    /** @var array<string,array{key:array<string,mixed>,histogram:Histogram}> */
    private array $pending = [];

    /**
     * @param  array<string,mixed>  $record
     */
    public function addRequest(array $record, Carbon $at): void
    {
        $site = (int) ($record['site'] ?? 0);
        $groups = [
            [$site, self::TOTAL, self::TOTAL],
            [$site, self::cut((string) ($record['loc'] ?? ''), 12), self::TOTAL],
            [$site, self::TOTAL, self::cut((string) ($record['route'] ?? ''), 191)],
        ];

        $values = [];

        foreach (self::REQUEST_METRICS as $field => $metric) {
            $values[$metric] = (float) ($record[$field] ?? 0.0);
        }

        $values['memory'] = (float) ($record['mem'] ?? 0.0) / 1024;
        $status = $record['s'] ?? null;
        $values['error'] = is_numeric($status) && $status >= 500 ? 1.0 : 0.0;

        foreach ($values as $metric => $value) {
            foreach ($groups as [$siteId, $locale, $subject]) {
                $this->put($at, 'request', $metric, $siteId, $locale, $subject, self::TOTAL, $value);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $record
     */
    public function addVitals(array $record, Carbon $at): void
    {
        $site = (int) ($record['site'] ?? 0);
        $groups = [
            [self::TOTAL, self::TOTAL, self::TOTAL],
            [self::cut((string) ($record['loc'] ?? ''), 12), self::TOTAL, self::TOTAL],
            [self::TOTAL, self::cut((string) ($record['path'] ?? '/'), 191), self::TOTAL],
            [self::TOTAL, self::TOTAL, self::cut((string) ($record['dev'] ?? ''), 8)],
        ];

        foreach (self::VITALS as $metric) {
            if (! isset($record[$metric]) || ! is_numeric($record[$metric])) {
                continue;
            }

            foreach ($groups as [$locale, $subject, $device]) {
                $this->put($at, 'vital', $metric, $site, $locale, $subject, $device, (float) $record[$metric]);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $record
     */
    public function addQuery(array $record, Carbon $at): void
    {
        $site = (int) ($record['site'] ?? 0);
        $value = (float) ($record['d'] ?? 0.0);

        $this->put($at, 'query', 'time', $site, self::TOTAL, self::TOTAL, self::TOTAL, $value);
        $this->put($at, 'query', 'time', $site, self::TOTAL, (string) ($record['h'] ?? ''), self::TOTAL, $value);
    }

    public function addUptime(int $siteId, int $checkId, bool $ok, ?float $responseMs, Carbon $at): void
    {
        $this->put($at, 'uptime', 'up', $siteId, self::TOTAL, (string) $checkId, self::TOTAL, $ok ? 1.0 : 0.0);

        if ($responseMs !== null) {
            $this->put($at, 'uptime', 'response', $siteId, self::TOTAL, (string) $checkId, self::TOTAL, $responseMs);
        }
    }

    /**
     * Rows touched by this batch, by their key.
     *
     * @return array<string,array{key:array<string,mixed>,histogram:Histogram}>
     */
    public function pending(): array
    {
        return $this->pending;
    }

    /**
     * Merges the batch into the table.
     */
    public function save(): void
    {
        $byDaySource = [];

        foreach ($this->pending as $id => $entry) {
            $byDaySource[$entry['key']['day']][$entry['key']['source']][$id] = $entry;
        }

        foreach ($byDaySource as $sources) {
            foreach ($sources as $entries) {
                foreach (array_chunk($entries, 200, true) as $chunk) {
                    $this->saveChunk($chunk);
                }
            }
        }

        $this->pending = [];
    }

    /**
     * @param  array<string,array{key:array<string,mixed>,histogram:Histogram}>  $chunk
     */
    private function saveChunk(array $chunk): void
    {
        $first = reset($chunk)['key'];
        $subjects = array_unique(array_map(static fn (array $entry): string => $entry['key']['subject'], $chunk));

        $existing = DB::table('monitoring_daily')
            ->where('day', $first['day'])
            ->where('source', $first['source'])
            ->whereIn('subject', $subjects)
            ->get();

        foreach ($existing as $row) {
            $id = self::id((array) $row);

            if (isset($chunk[$id])) {
                $chunk[$id]['histogram']->merge(Histogram::fromRow($row));
            }
        }

        $rows = [];

        foreach ($chunk as $entry) {
            $histogram = $entry['histogram'];
            $rows[] = $entry['key'] + [
                'count' => $histogram->count(),
                'sum' => $histogram->sum(),
                'min' => (float) $histogram->min(),
                'max' => (float) $histogram->max(),
                'buckets' => $histogram->toJson(),
            ];
        }

        DB::table('monitoring_daily')->upsert(
            $rows,
            ['day', 'source', 'metric', 'site_id', 'locale', 'subject', 'device'],
            ['count', 'sum', 'min', 'max', 'buckets'],
        );
    }

    private function put(Carbon $at, string $source, string $metric, int $site, string $locale, string $subject, string $device, float $value): void
    {
        $key = [
            'day' => $at->toDateString(),
            'source' => $source,
            'metric' => $metric,
            'site_id' => $site,
            'locale' => $locale === '' ? self::TOTAL : $locale,
            'subject' => $subject === '' ? self::TOTAL : $subject,
            'device' => $device === '' ? self::TOTAL : $device,
        ];
        $id = self::id($key);

        $this->pending[$id] ??= ['key' => $key, 'histogram' => new Histogram];
        $this->pending[$id]['histogram']->add($value);
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private static function id(array $row): string
    {
        return implode("\0", [$row['day'], $row['source'], $row['metric'], $row['site_id'], $row['locale'], $row['subject'], $row['device']]);
    }

    private static function cut(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }
}
