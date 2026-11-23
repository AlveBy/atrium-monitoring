<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Monitoring\Admin\MonitoringGadget;
use Atrium\Monitoring\Collector\CollectMetrics;
use Atrium\Monitoring\MonitoringServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\Attributes\DefineEnvironment;

final class ProviderTest extends TestCase
{
    private string $themeViews;

    protected function setUp(): void
    {
        parent::setUp();

        // A stand-in for the theme's layout: the module pushes its script into the head stack.
        $this->themeViews = sys_get_temp_dir().'/atrium-monitoring-theme-'.getmypid();
        File::ensureDirectoryExists($this->themeViews);
        File::put($this->themeViews.'/layout.blade.php', '<head>@stack(\'head\')</head>');
        View::addNamespace('atrium-theme', $this->themeViews);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->themeViews);

        parent::tearDown();
    }

    protected function serverOff($app): void
    {
        $app['config']->set('atrium-monitoring.server.enabled', false);
    }

    protected function themeOff($app): void
    {
        $app['config']->set('atrium-monitoring.rum.theme', false);
    }

    protected function rumOff($app): void
    {
        $app['config']->set('atrium-monitoring.rum.enabled', false);
    }

    protected function scheduleOff($app): void
    {
        $app['config']->set('atrium-monitoring.schedule', false);
    }

    /**
     * The monitoring tasks of the schedule; the admin's own are not its business.
     *
     * @return array<int,string>
     */
    private function scheduled(): array
    {
        $events = array_filter(app(Schedule::class)->events(), static fn ($event): bool => str_contains((string) $event->command, 'atrium:monitoring'));

        return array_values(array_map(static fn ($event): string => preg_replace('/^.*artisan.? /', '', (string) $event->command).' '.$event->expression.' lock '.($event->withoutOverlapping ? $event->expiresAt : 'none'), $events));
    }

    public function test_an_installed_module_measures_shows_and_schedules(): void
    {
        $this->assertContains(CollectMetrics::class, app('router')->getMiddlewareGroups()['web']);
        $this->assertTrue(Route::has('atrium-monitoring.rum'));
        $this->assertTrue(Route::has('admin.monitoring.index'));
        $this->assertContains(MonitoringGadget::class, config('atrium.dashboard.gadgets'));
        $this->assertArrayHasKey('atrium:monitoring:prune', Artisan::all());
        $this->assertArrayHasKey('atrium:monitoring:benchmark', Artisan::all());
        $this->assertSame([
            'atrium:monitoring:flush * * * * * lock 10',
            'atrium:monitoring:uptime * * * * * lock 5',
            'atrium:monitoring:prune 17 3 * * * lock 60',
        ], $this->scheduled());
        $this->assertArrayHasKey(MonitoringServiceProvider::class, ServiceProvider::$publishes);
    }

    public function test_the_theme_layout_gets_the_script(): void
    {
        $html = View::make('atrium-theme::layout')->render();

        $this->assertStringContainsString('<script data-endpoint="/atrium/rum"', $html);
        $this->assertStringContainsString('sendBeacon', $html);
    }

    public function test_no_script_for_a_visitor_who_asked_not_to_be_tracked(): void
    {
        $this->app->instance('request', tap(request(), fn ($request) => $request->headers->set('DNT', '1')));

        $this->assertSame('<head></head>', View::make('atrium-theme::layout')->render());
    }

    #[DefineEnvironment('themeOff')]
    public function test_the_theme_can_be_left_alone(): void
    {
        $this->assertSame('<head></head>', View::make('atrium-theme::layout')->render());
    }

    #[DefineEnvironment('rumOff')]
    public function test_visitor_measurements_can_be_switched_off(): void
    {
        $this->assertSame('<head></head>', View::make('atrium-theme::layout')->render());
    }

    #[DefineEnvironment('serverOff')]
    public function test_server_measurements_can_be_switched_off(): void
    {
        $this->assertNotContains(CollectMetrics::class, app('router')->getMiddlewareGroups()['web'] ?? []);
        $this->assertTrue(Route::has('admin.monitoring.index'), 'the page stays');
    }

    #[DefineEnvironment('scheduleOff')]
    public function test_the_schedule_can_be_left_to_the_app(): void
    {
        $this->assertSame([], $this->scheduled());
        $this->assertArrayHasKey('atrium:monitoring:flush', Artisan::all());
    }

    public function test_without_the_ai_module_its_helper_is_not_touched(): void
    {
        $this->assertNull(config('atrium-ai'));
    }

    public function test_the_gadget_is_on_the_dashboard_once_after_config_cache(): void
    {
        // A cached config already holds the gadget: booting on top of it must not add it again.
        $this->app->getProvider(MonitoringServiceProvider::class)->boot();

        $this->assertSame(1, array_count_values((array) config('atrium.dashboard.gadgets'))[MonitoringGadget::class]);
    }
}
