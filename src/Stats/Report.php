<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Stats;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reads the daily histograms back as percentiles: a chart's points, the
 * total of a period, the slowest routes, pages and queries.
 *
 * $site null means every site together.
 */
final class Report
{
    public const GRANULARITIES = ['day' => 30, 'week' => 26, 'month' => 13];

    public const QUANTILES = ['p50' => 0.5, 'p75' => 0.75, 'p95' => 0.95];

    public function __construct(private readonly ?CarbonImmutable $today = null) {}

    /**
     * One point per day (the last 30), week (26) or month (13), oldest first;
     * a point without measurements has null percentiles.
     *
     * @param  array{site?:int|null,locale?:string,subject?:string,device?:string}  $filter
     * @return array<int,array{from:string,to:string,count:int,p50:float|null,p75:float|null,p95:float|null}>
     */
    public function series(string $source, string $metric, string $granularity, array $filter = []): array
    {
        $periods = $this->periods($granularity);
        $from = $periods[0]['from'];
        $to = $periods[count($periods) - 1]['to'];

        $histograms = [];
        $i = 0;

        // The rows come oldest day first, and so do the periods.
        foreach ($this->rows($source, $metric, $from, $to, $filter) as $row) {
            while ($row->day > $periods[$i]['to']) {
                $i++;
            }

            ($histograms[$i] ??= new Histogram)->merge(Histogram::fromRow($row));
        }

        $points = [];

        foreach ($periods as $i => $period) {
            $points[] = $period + self::percentiles($histograms[$i] ?? new Histogram);
        }

        return $points;
    }

    /**
     * Everything measured between the two days, inclusive.
     *
     * @param  array{site?:int|null,locale?:string,subject?:string,device?:string}  $filter
     */
    public function total(string $source, string $metric, string $from, string $to, array $filter = []): Histogram
    {
        $histogram = new Histogram;

        foreach ($this->rows($source, $metric, $from, $to, $filter) as $row) {
            $histogram->merge(Histogram::fromRow($row));
        }

        return $histogram;
    }

    /**
     * The slowest subjects (routes, pages, queries, checks) by a percentile.
     *
     * @param  array{site?:int|null,locale?:string,device?:string}  $filter
     * @return array<int,array{subject:string,count:int,p50:float|null,p75:float|null,p95:float|null}>
     */
    public function top(string $source, string $metric, string $from, string $to, array $filter = [], int $limit = 10, string $by = 'p75'): array
    {
        $histograms = [];

        foreach ($this->rows($source, $metric, $from, $to, $filter + ['subject' => null]) as $row) {
            ($histograms[$row->subject] ??= new Histogram)->merge(Histogram::fromRow($row));
        }

        $top = [];

        foreach ($histograms as $subject => $histogram) {
            $top[] = ['subject' => (string) $subject] + self::percentiles($histogram);
        }

        $by = isset(self::QUANTILES[$by]) ? $by : 'p75';
        usort($top, static fn (array $a, array $b): int => [$b[$by], $b['count']] <=> [$a[$by], $a['count']]);

        return array_slice($top, 0, max(1, $limit));
    }

    /**
     * Share of good checks per check: check id => ratio 0…1.
     *
     * @return array<int,array{checks:int,up:int,ratio:float}>
     */
    public function uptime(string $from, string $to, ?int $site = null): array
    {
        $uptime = [];

        foreach ($this->rows('uptime', 'up', $from, $to, ['site' => $site, 'subject' => null]) as $row) {
            $entry = $uptime[$row->subject] ?? ['checks' => 0, 'up' => 0];
            $checks = $entry['checks'] + $row->count;
            $up = $entry['up'] + (int) round((float) $row->sum);
            $uptime[$row->subject] = ['checks' => $checks, 'up' => $up, 'ratio' => $checks === 0 ? 0.0 : $up / $checks];
        }

        return $uptime;
    }

    /**
     * @return array{count:int,p50:float|null,p75:float|null,p95:float|null}
     */
    public static function percentiles(Histogram $histogram): array
    {
        $result = ['count' => $histogram->count()];

        foreach (self::QUANTILES as $name => $q) {
            $value = $histogram->quantile($q);
            $result[$name] = $value === null ? null : round($value, 3);
        }

        return $result;
    }

    /**
     * The periods of a chart, oldest first, each with its first and last day.
     *
     * @return array<int,array{from:string,to:string}>
     */
    public function periods(string $granularity): array
    {
        $count = self::GRANULARITIES[$granularity] ?? self::GRANULARITIES['day'];
        $today = $this->today();
        $periods = [];

        for ($i = $count - 1; $i >= 0; $i--) {
            [$from, $to] = match ($granularity) {
                'week' => [$today->subWeeks($i)->startOfWeek(), $today->subWeeks($i)->endOfWeek()],
                'month' => [$today->startOfMonth()->subMonths($i), $today->startOfMonth()->subMonths($i)->endOfMonth()],
                default => [$today->subDays($i), $today->subDays($i)],
            };

            $periods[] = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        }

        return $periods;
    }

    private function today(): CarbonImmutable
    {
        return $this->today ?? CarbonImmutable::now((string) config('app.timezone', 'UTC'))->startOfDay();
    }

    /**
     * @param  array{site?:int|null,locale?:string,subject?:string|null,device?:string}  $filter
     * @return iterable<object>
     */
    private function rows(string $source, string $metric, string $from, string $to, array $filter): iterable
    {
        $query = DB::table('monitoring_daily')
            ->where('source', $source)
            ->where('metric', $metric)
            ->whereBetween('day', [$from, $to])
            ->where('locale', $filter['locale'] ?? Aggregator::TOTAL)
            ->where('device', $filter['device'] ?? Aggregator::TOTAL);

        if (array_key_exists('subject', $filter) && $filter['subject'] === null) {
            $query->where('subject', '!=', Aggregator::TOTAL);
        } else {
            $query->where('subject', $filter['subject'] ?? Aggregator::TOTAL);
        }

        if (isset($filter['site'])) {
            $query->where('site_id', (int) $filter['site']);
        }

        return $query->orderBy('day')->select(['day', 'subject', 'count', 'sum', 'min', 'max', 'buckets'])->cursor();
    }

    /**
     * The last N days up to today, as the first and the last date.
     *
     * @return array{0:string,1:string}
     */
    public function lastDays(int $days): array
    {
        $today = $this->today();

        return [$today->subDays(max(1, $days) - 1)->toDateString(), $today->toDateString()];
    }
}
