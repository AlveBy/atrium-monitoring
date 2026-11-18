<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Monitoring\Admin\MonitoringGadget;
use Atrium\Monitoring\Collector\CollectMetrics;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/** Required by composer but not installed: no tables, so nothing is wired. */
final class NotInstalledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('atrium.modules', []);
    }

    public function test_nothing_is_measured_shown_or_scheduled(): void
    {
        $this->assertNotContains(CollectMetrics::class, app('router')->getMiddlewareGroups()['web'] ?? []);
        $this->assertFalse(Route::has('atrium-monitoring.rum'));
        $this->assertFalse(Route::has('admin.monitoring.index'));
        $this->assertNotContains(MonitoringGadget::class, (array) config('atrium.dashboard.gadgets'));
        $this->assertArrayNotHasKey('atrium:monitoring:flush', Artisan::all());
        $this->assertSame([], app(Schedule::class)->events());
        // The translations and the config are there for the install screen.
        $this->assertSame('Monitoring', __('atrium-monitoring::monitoring.title'));
        $this->assertSame(1.0, config('atrium-monitoring.server.sample_rate'));
    }
}
