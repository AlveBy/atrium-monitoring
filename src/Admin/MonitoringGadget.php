<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Admin;

use Atrium\Admin\Dashboard\Gadget;
use Atrium\Admin\Models\AdminUser;
use Atrium\Monitoring\Models\UptimeCheck;
use Atrium\Monitoring\Stats\Aggregator;
use Atrium\Monitoring\Stats\Report;
use Atrium\Monitoring\Stats\Vitals;

/**
 * The dashboard tile: the visitors' Web Vitals at p75 over the last week,
 * uptime over 30 days, and the slowest routes.
 */
final class MonitoringGadget extends Gadget
{
    public function component(): string
    {
        return 'MonitoringGadget';
    }

    public function title(): string
    {
        return (string) $this->option('title', __('atrium-monitoring::monitoring.gadget.title'));
    }

    /** Only for those who may open the monitoring page it links to. */
    public function isVisible(): bool
    {
        $user = auth((string) config('atrium.auth.guard', 'admin'))->user();

        return parent::isVisible() && $user instanceof AdminUser && $user->hasAdminPermission(MonitoringController::PERMISSION);
    }

    public function data(): array
    {
        $report = app(Report::class);
        [$weekFrom, $to] = $report->lastDays(7);
        [$monthFrom] = $report->lastDays(30);

        $vitals = [];

        foreach (Aggregator::VITALS as $metric) {
            $p75 = $report->total('vital', $metric, $weekFrom, $to)->quantile(0.75);
            $vitals[] = [
                'key' => $metric,
                'label' => __('atrium-monitoring::monitoring.vitals.'.$metric),
                'value' => Vitals::format($metric, $p75),
                'rating' => Vitals::rating($metric, $p75),
            ];
        }

        $uptime = $report->uptime($monthFrom, $to);
        $checks = array_sum(array_column($uptime, 'checks'));
        $up = array_sum(array_column($uptime, 'up'));

        return [
            'vitals' => $vitals,
            'uptime' => $checks === 0 ? null : round($up / $checks * 100, 2),
            'down' => UptimeCheck::query()->withoutGlobalScopes()->where('active', true)->where('status', UptimeCheck::DOWN)->pluck('name')->all(),
            'slowest' => array_map(static fn (array $row): array => [
                'route' => $row['subject'],
                'p75' => MonitoringController::ms((float) $row['p75']),
            ], $report->top('request', 'ttfb', $weekFrom, $to, [], 5)),
            'url' => route('admin.monitoring.index'),
            'labels' => trans('atrium-monitoring::monitoring.gadget'),
            'ratings' => trans('atrium-monitoring::monitoring.ratings'),
        ];
    }
}
