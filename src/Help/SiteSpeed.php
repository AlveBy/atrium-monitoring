<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Help;

use Atrium\Admin\Models\AdminUser;
use Atrium\Monitoring\Admin\MonitoringController;
use Atrium\Monitoring\Stats\Histogram;
use Atrium\Monitoring\Stats\Report;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * The monitoring page for the helper of the AI module: how fast the site
 * answered over the last days, how many requests failed and which pages
 * were the slowest. The same numbers as the page, for whoever may open it.
 */
final class SiteSpeed implements Tool
{
    /** The pages and routes listed. */
    private const SLOWEST = 5;

    public function __construct(private readonly AdminUser $user) {}

    public function name(): string
    {
        return 'site_speed';
    }

    public function description(): string
    {
        return 'How fast the site is, from the monitoring module: the server response time (average, median and slow 95th percentile, in milliseconds) and the share of it spent in the database, the share of failed requests, and the slowest pages. Answer questions about speed, slowness and errors of the site with it.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->description('How many last days to look at, 1 to 90. Default: 7.'),
        ];
    }

    public function handle(Request $request): string
    {
        if (! $this->user->hasAdminPermission(MonitoringController::PERMISSION)) {
            return 'This administrator may not open the monitoring page, so the speed of the site is not shown to them.';
        }

        $report = app(Report::class);
        [$from, $to] = $report->lastDays(min(90, $request->integer('days') ?: 7));
        $total = $report->total('request', 'ttfb', $from, $to);
        $db = $report->total('request', 'db_ms', $from, $to);
        $errors = $report->total('request', 'error', $from, $to);

        return (string) json_encode([
            'from' => $from,
            'to' => $to,
            'requests' => $total->count(),
            'response_ms' => $this->times($total),
            'database_ms' => $this->times($db),
            'failed_requests_percent' => $errors->count() === 0 ? null : round($errors->sum() / $errors->count() * 100, 2),
            'slowest_pages' => array_map(static fn (array $row): array => [
                'route' => $row['subject'],
                'requests' => $row['count'],
                'median_ms' => $row['p50'],
                'p95_ms' => $row['p95'],
            ], $report->top('request', 'ttfb', $from, $to, [], self::SLOWEST, 'p95')),
            'monitoring_url' => route('admin.monitoring.index'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{average:float|null,median:float|null,p95:float|null}
     */
    private function times(Histogram $histogram): array
    {
        $percentiles = Report::percentiles($histogram);

        return [
            'average' => $histogram->mean() === null ? null : round($histogram->mean(), 1),
            'median' => $percentiles['p50'],
            'p95' => $percentiles['p95'],
        ];
    }
}
