<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Admin;

use Atrium\Admin\Models\AdminUser;
use Atrium\Admin\Support\ContentLanguages;
use Atrium\Admin\Support\Sites;
use Atrium\Monitoring\Models\Incident;
use Atrium\Monitoring\Models\UptimeCheck;
use Atrium\Monitoring\Stats\Aggregator;
use Atrium\Monitoring\Stats\Report;
use Atrium\Monitoring\Stats\Vitals;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The monitoring page: server timings, visitors' Web Vitals and uptime, by
 * day, week or month, for one site or all, one language, route or page.
 *
 * Open to whoever has `monitoring.view` (declared in the manifest; every
 * role that may see the uptime checks gets it).
 */
final class MonitoringController
{
    public const PERMISSION = 'monitoring.view';

    public function __construct(private readonly Report $report) {}

    public function index(Request $request, Sites $sites, ContentLanguages $languages): Response
    {
        $this->authorize($request);

        $granularity = $this->choice($request, 'period', array_keys(Report::GRANULARITIES), 'day');
        $site = $this->siteFilter($request, $sites);
        $locale = $this->stringFilter($request, 'locale', 12);
        $route = $this->stringFilter($request, 'route', 191);
        $page = $this->stringFilter($request, 'page', 191);
        $device = $this->choice($request, 'device', ['mobile', 'tablet', 'desktop'], Aggregator::TOTAL);
        $periods = $this->report->periods($granularity);
        [$from, $to] = [$periods[0]['from'], $periods[count($periods) - 1]['to']];

        // Route and language are separate totals (see Aggregator): with a route, the language is all.
        $server = ['site' => $site, 'locale' => $route === Aggregator::TOTAL ? $locale : Aggregator::TOTAL, 'subject' => $route];
        $visitors = $this->vitalsFilter($site, $locale, $page, $device);

        return Inertia::render('Admin/Monitoring/Index', [
            't' => trans('atrium-monitoring::monitoring'),
            'filters' => [
                'period' => $granularity,
                'site' => $site,
                'locale' => $locale,
                'route' => $route,
                'page' => $page,
                'device' => $device,
            ],
            'options' => [
                'sites' => $sites->all()->map(fn ($s): array => ['value' => $s->getKey(), 'label' => $s->name])->values(),
                'locales' => $languages->codes(),
            ],
            'range' => ['from' => $from, 'to' => $to],
            'server' => $this->server($granularity, $server, $from, $to, $site),
            // Pages are summed per site only: the table would ignore a page, language or device filter.
            'vitals' => $this->vitals($granularity, $visitors, $from, $to, $site, $visitors === $this->vitalsFilter($site, Aggregator::TOTAL, Aggregator::TOTAL, Aggregator::TOTAL), $this->errorsLink($request)),
            'uptime' => $this->uptime($from, $to, $site),
        ]);
    }

    public function query(Request $request, string $hash): Response
    {
        $this->authorize($request);
        abort_unless(preg_match('/\A[a-f0-9]{40}\z/', $hash) === 1, 404);

        $text = DB::table('monitoring_query_texts')->where('hash', $hash)->first();
        abort_if($text === null, 404);

        $points = self::labelled($this->report->series('query', 'time', 'day', ['subject' => $hash]), 'day');
        $errors = $this->errorsLink($request);
        $routes = DB::table('monitoring_queries')
            ->where('sql_hash', $hash)
            ->selectRaw('route, max(path) as path, count(*) as times, max(duration_ms) as slowest, avg(duration_ms) as average')
            ->groupBy('route')
            ->orderByDesc('times')
            ->limit(20)
            ->get()
            ->map(fn (object $row): array => [
                'route' => $row->route,
                'path' => $row->path,
                'errors_url' => $errors === null ? null : $errors($row->path, null),
                'times' => $row->times,
                'slowest' => self::ms((float) $row->slowest),
                'average' => self::ms((float) $row->average),
            ]);

        return Inertia::render('Admin/Monitoring/Query', [
            't' => trans('atrium-monitoring::monitoring'),
            'query' => ['hash' => $hash, 'sql' => $text->sql, 'connection' => $text->connection, 'last_seen' => $text->last_seen_at],
            'chart' => SvgChart::render(__('atrium-monitoring::monitoring.charts.query_time'), $points, self::ms(...)),
            'points' => self::formatted($points, self::ms(...)),
            'routes' => $routes,
        ]);
    }

    /**
     * @param  array{site:int|null,locale:string,subject:string}  $filter
     * @return array<string,mixed>
     */
    private function server(string $granularity, array $filter, string $from, string $to, ?int $site): array
    {
        $ttfb = self::labelled($this->report->series('request', 'ttfb', $granularity, $filter), $granularity);
        $db = self::labelled($this->report->series('request', 'db_ms', $granularity, $filter), $granularity);
        $total = $this->report->total('request', 'ttfb', $from, $to, $filter);
        $errors = $this->report->total('request', 'error', $from, $to, $filter);
        $hits = $this->report->total('request', 'cache_hit', $from, $to, $filter)->sum();
        $misses = $this->report->total('request', 'cache_miss', $from, $to, $filter)->sum();
        $queries = $this->report->total('request', 'db_count', $from, $to, $filter);
        $memory = $this->report->total('request', 'memory', $from, $to, $filter);

        return [
            'summary' => [
                'requests' => $total->count(),
                'ttfb' => self::formattedPercentiles(Report::percentiles($total), self::ms(...)),
                'errors' => $errors->count() === 0 ? null : round($errors->sum() / $errors->count() * 100, 2),
                'cache_ratio' => $hits + $misses <= 0 ? null : round($hits / ($hits + $misses) * 100, 1),
                'queries' => $queries->mean() === null ? null : round($queries->mean(), 1),
                'memory_p95' => $memory->quantile(0.95) === null ? null : round((float) $memory->quantile(0.95), 1),
            ],
            'charts' => [
                ['key' => 'ttfb', 'svg' => SvgChart::render(__('atrium-monitoring::monitoring.charts.ttfb'), $ttfb, self::ms(...)), 'points' => self::formatted($ttfb, self::ms(...))],
                ['key' => 'db_ms', 'svg' => SvgChart::render(__('atrium-monitoring::monitoring.charts.db_ms'), $db, self::ms(...)), 'points' => self::formatted($db, self::ms(...))],
            ],
            'routes' => array_map(fn (array $row): array => [
                'route' => $row['subject'],
                'sample' => $this->samplePath($row['subject']),
            ] + self::formattedPercentiles($row, self::ms(...)), $this->report->top('request', 'ttfb', $from, $to, ['site' => $site])),
            'queries' => array_map(fn (array $row): array => [
                'hash' => $row['subject'],
                'sql' => mb_strimwidth((string) DB::table('monitoring_query_texts')->where('hash', $row['subject'])->value('sql'), 0, 160, '…'),
            ] + self::formattedPercentiles($row, self::ms(...)), $this->report->top('query', 'time', $from, $to, ['site' => $site])),
        ];
    }

    /**
     * @param  array{site:int|null,locale:string,subject:string,device:string}  $filter
     * @return array<string,mixed>
     */
    /**
     * Links to the error log's list for one address, when that module is
     * installed and the administrator may open it.
     *
     * @return (\Closure(string,int|null):string)|null
     */
    private function errorsLink(Request $request): ?\Closure
    {
        $user = $request->user((string) config('atrium.auth.guard', 'admin'));

        if (! Route::has('admin.errors.index') || ! $user instanceof AdminUser || ! $user->hasAdminPermission('errors.view')) {
            return null;
        }

        return static fn (string $path, ?int $site): string => route('admin.errors.index', ['status' => 'all', 'path' => $path] + ($site === null ? [] : ['site' => $site]));
    }

    private function vitals(string $granularity, array $filter, string $from, string $to, ?int $site, bool $withPages, ?\Closure $errors): array
    {
        $metrics = [];

        foreach (Aggregator::VITALS as $metric) {
            $points = self::labelled($this->report->series('vital', $metric, $granularity, $filter), $granularity);
            $total = Report::percentiles($this->report->total('vital', $metric, $from, $to, $filter));
            $format = fn (float $v): string => Vitals::format($metric, $v);

            $metrics[] = [
                'key' => $metric,
                'p75' => $total['p75'] === null ? '—' : $format((float) $total['p75']),
                'raw' => $total['p75'],
                'rating' => Vitals::rating($metric, $total['p75']),
                'count' => $total['count'],
                'thresholds' => [Vitals::format($metric, (float) Vitals::THRESHOLDS[$metric][0]), Vitals::format($metric, (float) Vitals::THRESHOLDS[$metric][1])],
                'svg' => SvgChart::render(__('atrium-monitoring::monitoring.vitals.'.$metric), $points, $format),
                'points' => self::formatted($points, $format),
            ];
        }

        $pages = ! $withPages ? [] : array_map(fn (array $row): array => [
            'page' => $row['subject'],
            'rating' => Vitals::rating('lcp', $row['p75']),
            'errors_url' => $errors === null ? null : $errors($row['subject'], $site),
        ] + self::formattedPercentiles($row, fn (float $v): string => Vitals::format('lcp', $v)), $this->report->top('vital', 'lcp', $from, $to, ['site' => $site]));

        return ['metrics' => $metrics, 'pages' => $pages];
    }

    /**
     * @return array<string,mixed>
     */
    private function uptime(string $from, string $to, ?int $site): array
    {
        $ratios = $this->report->uptime($from, $to, $site);
        $checks = UptimeCheck::query()->withoutGlobalScopes()
            ->when($site !== null, fn ($q) => $q->where('site_id', $site))
            ->orderByDesc('is_home')->orderBy('id')->get();

        // Like the checks above, every site's: the panel's current site would hide the name.
        $incidents = Incident::query()->with(['check' => fn ($q) => $q->withoutGlobalScopes()])
            ->when($site !== null, fn ($q) => $q->whereHas('check', fn ($c) => $c->withoutGlobalScopes()->where('site_id', $site)))
            ->latest('started_at')->limit(10)->get();

        return [
            'checks' => $checks->map(fn (UptimeCheck $check): array => [
                'id' => $check->id,
                'name' => $check->name,
                'url' => $check->url,
                'active' => $check->active,
                'status' => $check->status,
                'uptime' => isset($ratios[$check->id]) ? round($ratios[$check->id]['ratio'] * 100, 2) : null,
                'response' => $check->last_response_ms === null ? null : self::ms($check->last_response_ms),
                'checked_at' => $check->last_checked_at?->format('Y-m-d H:i'),
                'error' => $check->last_error,
            ])->values(),
            'incidents' => $incidents->map(fn (Incident $incident): array => [
                'id' => $incident->id,
                'check' => (string) $incident->check?->name,
                'started_at' => $incident->started_at->format('Y-m-d H:i'),
                'resolved_at' => $incident->resolved_at?->format('Y-m-d H:i'),
                'duration' => $incident->duration,
                'error' => $incident->last_error,
            ])->values(),
        ];
    }

    private function authorize(Request $request): void
    {
        $user = $request->user((string) config('atrium.auth.guard', 'admin'));

        abort_unless($user instanceof AdminUser && $user->hasAdminPermission(self::PERMISSION), 403);
    }

    private function siteFilter(Request $request, Sites $sites): ?int
    {
        $site = $request->query('site');

        return is_numeric($site) && $sites->find((int) $site) !== null ? (int) $site : null;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function choice(Request $request, string $key, array $allowed, string $default): string
    {
        $value = $request->query($key);

        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    private function stringFilter(Request $request, string $key, int $length): string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? mb_substr($value, 0, $length) : Aggregator::TOTAL;
    }

    /**
     * Vitals are summed by language, by page or by device, not by their
     * pairs: the narrowest filter given wins.
     *
     * @return array{site:int|null,locale:string,subject:string,device:string}
     */
    private function vitalsFilter(?int $site, string $locale, string $page, string $device): array
    {
        return match (true) {
            $page !== Aggregator::TOTAL => ['site' => $site, 'locale' => Aggregator::TOTAL, 'subject' => $page, 'device' => Aggregator::TOTAL],
            $device !== Aggregator::TOTAL => ['site' => $site, 'locale' => Aggregator::TOTAL, 'subject' => Aggregator::TOTAL, 'device' => $device],
            default => ['site' => $site, 'locale' => $locale, 'subject' => Aggregator::TOTAL, 'device' => Aggregator::TOTAL],
        };
    }

    /** An address the route answered lately, for a link to the live page. */
    private function samplePath(string $route): ?string
    {
        $path = DB::table('monitoring_requests')->where('route', $route)->where('method', 'GET')->where('status', '<', 400)->orderByDesc('id')->value('path');

        return is_string($path) ? $path : null;
    }

    /**
     * @param  array<int,array<string,mixed>>  $points
     * @return array<int,array<string,mixed>>
     */
    private static function labelled(array $points, string $granularity): array
    {
        return array_map(static function (array $point) use ($granularity): array {
            $from = Carbon::parse($point['from']);
            $point['label'] = $granularity === 'month' ? $from->translatedFormat('M Y') : $from->format('d.m');

            return $point;
        }, $points);
    }

    /**
     * @param  array<int,array<string,mixed>>  $points
     * @return array<int,array<string,mixed>>
     */
    private static function formatted(array $points, callable $format): array
    {
        return array_map(static fn (array $point): array => ['label' => $point['label'], 'count' => $point['count']] + self::formattedPercentiles($point, $format), $points);
    }

    /**
     * @param  array{count:int,p50?:float|null,p75?:float|null,p95?:float|null}  $row
     * @return array{count:int,p50:string,p75:string,p95:string}
     */
    private static function formattedPercentiles(array $row, callable $format): array
    {
        $result = ['count' => $row['count']];

        foreach (array_keys(Report::QUANTILES) as $name) {
            $result[$name] = isset($row[$name]) ? $format((float) $row[$name]) : '—';
        }

        return $result;
    }

    public static function ms(float $value): string
    {
        return Vitals::format('ttfb', $value);
    }
}
