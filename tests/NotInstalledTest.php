<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Admin\Facades\Hook;
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
        // Even with the AI module there, its helper gets nothing of this module.
        $app['config']->set('atrium-ai.help', ['enabled' => true, 'tools' => []]);
    }

    public function test_nothing_is_measured_shown_or_scheduled(): void
    {
        $this->assertNotContains(CollectMetrics::class, app('router')->getMiddlewareGroups()['web'] ?? []);
        $this->assertFalse(Route::has('atrium-monitoring.rum'));
        $this->assertFalse(Route::has('admin.monitoring.index'));
        $this->assertNull(Hook::resolve('atrium.ui.gadgets', 'monitoring'));
        $this->assertArrayNotHasKey('atrium:monitoring:flush', Artisan::all());
        // The admin schedules its own tasks (the imports in progress); monitoring none.
        $this->assertSame([], array_values(array_filter(
            app(Schedule::class)->events(),
            static fn ($event): bool => str_contains((string) $event->command, 'atrium:monitoring'),
        )));
        $this->assertSame([], config('atrium-ai.help.tools'));
        // The translations and the config are there for the install screen.
        $this->assertSame('Monitoring', __('atrium-monitoring::monitoring.title'));
        $this->assertSame(1.0, config('atrium-monitoring.server.sample_rate'));
    }
}
